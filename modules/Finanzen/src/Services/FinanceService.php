<?php

namespace Modules\Finanzen\src\Services;

use App\Models\User;
use App\Services\ImageResizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finanzen\src\Models\Budget;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Models\Receipt;
use Modules\Finanzen\src\Notifications\ExpenseDecidedNotification;
use Modules\Finanzen\src\Notifications\ExpensePaidNotification;
use Modules\Finanzen\src\Notifications\ExpenseSubmittedNotification;
use Throwable;

/**
 * All changes of Budget & Finanzen go through here:
 * 1. a Kamerad submits an Ausgabe with Beleg (Kassenwart and Wehrführung are told),
 * 2. Kassenwart or Wehrführung approve or reject it (not their own),
 * 3. the Kassenwart pays it; Einnahmen and paid Ausgaben he books directly,
 * 4. the Jahresabschluss locks a year: nothing of it changes any more.
 */
class FinanceService
{
    public const MODULE = 'finanzen';

    public const MAX_RECEIPTS = 5;

    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    private const IMAGE_SIDE = 2000;

    public function __construct(private readonly ImageResizer $images) {}

    // ─── who may do what ─────────────────────────────────────────────────────

    /** Kassenwart and admins: Kostenstellen, budgets, booking, paying, Jahresabschluss */
    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'kassenwart'));
    }

    /** Kassenwart, Wehrführung and admins: approve, see all Buchungen with Belege, reports and export */
    public static function canReview(User $user): bool
    {
        return self::canManage($user) || ($user->is_active && $user->canUseModule(self::MODULE) && $user->hasModuleRole(self::MODULE, 'wehrführung'));
    }

    public static function canView(User $user, Entry $entry): bool
    {
        return self::canReview($user) || $entry->submitted_by === $user->id;
    }

    /** The submitter while it waits, the Kassenwart until the year is closed */
    public static function canEdit(User $user, Entry $entry): bool
    {
        if (self::isClosed($entry->year)) {
            return false;
        }

        return self::canManage($user) || ($entry->submitted_by === $user->id && $entry->status === 'submitted');
    }

    public static function isClosed(int $year): bool
    {
        return DB::table('fin_closed_years')->where('year', $year)->exists();
    }

    /** Today in Berlin */
    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    /** Who decides on Ausgaben; admins when nobody has the role */
    public function reviewers(): EloquentCollection
    {
        $reviewers = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->whereIn('role', ['kassenwart', 'wehrführung']))
            ->get();

        return $reviewers->isNotEmpty() ? $reviewers : User::active()->where('is_admin', true)->get();
    }

    // ─── Ausgaben and Einnahmen ──────────────────────────────────────────────

    /**
     * A Kamerad submits an Ausgabe; the Kassenwart can book an Ausgabe or Einnahme directly as paid.
     *
     * @param  array{kind?: string, cost_center_id: int, title: string, description?: ?string, vendor?: ?string, amount_cents: ?int, booked_on: string, payment_note?: ?string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(User $by, array $data, array $files = [], bool $direct = false): Entry
    {
        $direct = $direct && self::canManage($by);
        $kind = $direct ? ($data['kind'] ?? 'expense') : 'expense';
        $clean = $this->validate($data, $kind);
        $receipts = $this->prepareReceipts($files, 0);

        $entry = DB::transaction(function () use ($by, $clean, $kind, $direct, $receipts) {
            $year = $clean['year'];
            DB::select('select pg_advisory_xact_lock(?)', [crc32('fin_entries_'.$year)]);
            $number = (int) Entry::where('year', $year)->max('number') + 1;

            $entry = Entry::create([...$clean, 'kind' => $kind, 'number' => $number, 'submitted_by' => $by->id,
                'status' => $direct ? 'paid' : 'submitted', 'paid_by' => $direct ? $by->id : null, 'paid_at' => $direct ? now() : null]);
            $this->storeReceipts($entry, $receipts);

            return $entry;
        });

        if (! $direct) {
            $this->notify($this->reviewers()->reject(fn (User $u) => $u->is($by)), new ExpenseSubmittedNotification($entry));
        }

        return $entry;
    }

    /** @param  list<UploadedFile>  $files  more Belege */
    public function update(User $by, Entry $entry, array $data, array $files = []): Entry
    {
        $this->assertEditable($by, $entry);
        // A Kostenstelle switched off later stays valid for its own Buchungen
        $clean = $this->validate([...$data, 'keep_center' => $entry->cost_center_id], $entry->kind);

        if ($clean['year'] !== $entry->year) {
            throw ValidationException::withMessages(['booked_on' => 'Das Datum muss im Jahr '.$entry->year.' bleiben (Belegnummer '.$entry->code().'). Sonst bitte neu erfassen.']);
        }

        $receipts = $this->prepareReceipts($files, $entry->receipts()->count());
        DB::transaction(function () use ($entry, $clean, $receipts) {
            $entry->update(collect($clean)->except('year')->all());
            $this->storeReceipts($entry, $receipts);
        });

        return $entry->refresh();
    }

    public function removeReceipt(User $by, Receipt $receipt): void
    {
        $this->assertEditable($by, $receipt->entry);
        $receipt->delete();
    }

    /** Approve or reject; a rejection needs a comment. Not the own Ausgabe, unless admin. */
    public function decide(User $by, Entry $entry, bool $approve, ?string $comment = null): void
    {
        if (! self::canReview($by)) {
            throw ValidationException::withMessages(['entry' => 'Genehmigen dürfen Kassenwart und Wehrführung.']);
        }

        if ($entry->submitted_by === $by->id && ! $by->is_admin) {
            throw ValidationException::withMessages(['entry' => 'Die eigene Ausgabe genehmigt jemand anderes.']);
        }

        $comment = trim((string) $comment);
        if (! $approve && $comment === '') {
            throw ValidationException::withMessages(['comment' => 'Bitte kurz begründen, warum die Ausgabe abgelehnt wird.']);
        }

        $decided = DB::transaction(function () use ($by, $entry, $approve, $comment) {
            $locked = Entry::lockForUpdate()->findOrFail($entry->id);
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['entry' => 'Über diese Ausgabe wurde schon entschieden ('.$locked->statusLabel().').']);
            }
            $this->assertOpenYear($locked->year);

            $locked->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_comment' => $comment !== '' ? $comment : null]);

            return $locked;
        });

        $this->notify(collect([$decided->submitter])->reject(fn (?User $u) => $u?->is($by)), new ExpenseDecidedNotification($decided));
    }

    /** The Kassenwart has paid (or refunded) an approved Ausgabe */
    public function pay(User $by, Entry $entry, ?string $note = null): void
    {
        if (! self::canManage($by)) {
            throw ValidationException::withMessages(['entry' => 'Bezahlen trägt der Kassenwart ein.']);
        }

        $paid = DB::transaction(function () use ($by, $entry, $note) {
            $locked = Entry::lockForUpdate()->findOrFail($entry->id);
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['entry' => 'Nur genehmigte Ausgaben können bezahlt werden.']);
            }
            $this->assertOpenYear($locked->year);
            $locked->update(['status' => 'paid', 'paid_by' => $by->id, 'paid_at' => now(), 'payment_note' => filled($note) ? mb_substr(trim($note), 0, 150) : null]);

            return $locked;
        });

        $this->notify(collect([$paid->submitter])->reject(fn (?User $u) => $u?->is($by)), new ExpensePaidNotification($paid));
    }

    /** Withdraw while it waits (submitter), or delete (Kassenwart, open year). The Belegnummer stays free. */
    public function delete(User $by, Entry $entry): void
    {
        $this->assertEditable($by, $entry);
        $entry->delete();
    }

    // ─── Kostenstellen and budgets ───────────────────────────────────────────

    public function saveCostCenter(User $by, ?CostCenter $center, array $data): CostCenter
    {
        $this->assertManage($by);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Bitte einen Namen für die Kostenstelle angeben.']);
        }

        $values = ['name' => mb_substr($name, 0, 100), 'code' => filled($data['code'] ?? null) ? mb_substr(trim($data['code']), 0, 20) : null,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null, 'is_active' => (bool) ($data['is_active'] ?? true)];

        return $center ? tap($center)->update($values) : CostCenter::create($values + ['sort_order' => (int) CostCenter::max('sort_order') + 1]);
    }

    /** Only an unused Kostenstelle goes; a used one is switched off */
    public function deleteCostCenter(User $by, CostCenter $center): void
    {
        $this->assertManage($by);
        if ($center->entries()->exists()) {
            throw ValidationException::withMessages(['center' => '„'.$center->name.'“ hat Buchungen und bleibt erhalten. Zum Ausblenden ausschalten.']);
        }
        $center->delete();
    }

    public function setBudget(User $by, CostCenter $center, int $year, ?int $cents, ?string $notes = null): void
    {
        $this->assertManage($by);
        $this->assertOpenYear($year);
        if ($cents === null || $cents < 0) {
            throw ValidationException::withMessages(['budget' => 'Bitte einen Betrag angeben, z. B. 1.500,00.']);
        }

        Budget::updateOrCreate(['cost_center_id' => $center->id, 'year' => $year], ['amount_cents' => $cents, 'notes' => filled($notes) ? trim($notes) : null]);
    }

    /** Takes over the budgets of the year before for Kostenstellen without a budget yet; returns how many */
    public function copyBudgets(User $by, int $year): int
    {
        $this->assertManage($by);
        $this->assertOpenYear($year);
        $count = 0;

        foreach (Budget::where('year', $year - 1)->get() as $previous) {
            $budget = Budget::firstOrCreate(['cost_center_id' => $previous->cost_center_id, 'year' => $year], ['amount_cents' => $previous->amount_cents, 'notes' => $previous->notes]);
            $count += $budget->wasRecentlyCreated ? 1 : 0;
        }

        return $count;
    }

    // ─── reports and Jahresabschluss ─────────────────────────────────────────

    /**
     * Per Kostenstelle: budget, approved (promised), paid (spent), available, Einnahmen.
     *
     * @return Collection<int, array{center: CostCenter, budget: int, approved: int, paid: int, available: int, income: int, percent: ?int}>
     */
    public function overview(int $year, bool $activeOnly = false): Collection
    {
        $budgets = Budget::where('year', $year)->pluck('amount_cents', 'cost_center_id');
        $sums = Entry::where('year', $year)->whereIn('status', Entry::BOOKED)
            ->selectRaw('cost_center_id, kind, status, sum(amount_cents) as total')->groupBy('cost_center_id', 'kind', 'status')->get();
        $sum = fn ($id, $kind, $status) => (int) $sums->where('cost_center_id', $id)->where('kind', $kind)->where('status', $status)->sum('total');

        return CostCenter::ordered()->get()
            ->filter(fn (CostCenter $c) => ! $activeOnly || $c->is_active || $budgets->has($c->id) || $sums->contains('cost_center_id', $c->id))
            ->map(function (CostCenter $center) use ($budgets, $sum) {
                $budget = (int) ($budgets[$center->id] ?? 0);
                $approved = $sum($center->id, 'expense', 'approved');
                $paid = $sum($center->id, 'expense', 'paid');

                return [
                    'center' => $center, 'budget' => $budget, 'approved' => $approved, 'paid' => $paid,
                    'available' => $budget - $approved - $paid, 'income' => $sum($center->id, 'income', 'paid'),
                    'percent' => $budget > 0 ? (int) round(($approved + $paid) * 100 / $budget) : null,
                ];
            })->values();
    }

    /** @return array{budget: int, approved: int, paid: int, available: int, income: int} */
    public function totals(Collection $overview): array
    {
        return collect(['budget', 'approved', 'paid', 'available', 'income'])->mapWithKeys(fn ($k) => [$k => (int) $overview->sum($k)])->all();
    }

    /** Locks the year; all Ausgaben must be decided and paid first */
    public function closeYear(User $by, int $year): void
    {
        $this->assertManage($by);
        $open = Entry::where('year', $year)->whereIn('status', ['submitted', 'approved'])->count();
        if ($open > 0) {
            throw ValidationException::withMessages(['year' => $open.' Ausgabe'.($open === 1 ? '' : 'n').' aus '.$year.' '.($open === 1 ? 'ist' : 'sind').' noch nicht entschieden oder bezahlt.']);
        }

        DB::table('fin_closed_years')->insertOrIgnore(['year' => $year, 'closed_by' => $by->id, 'closed_at' => now()]);
    }

    /** Only admins open a closed year again */
    public function reopenYear(User $by, int $year): void
    {
        if (! $by->is_admin) {
            throw ValidationException::withMessages(['year' => 'Einen abgeschlossenen Jahrgang öffnen nur Admins wieder.']);
        }

        DB::table('fin_closed_years')->where('year', $year)->delete();
    }

    /** @return list<int> years with Buchungen or budgets, and the current one, newest first */
    public function years(): array
    {
        return collect([self::today()->year])
            ->merge(Entry::distinct()->pluck('year'))->merge(Budget::distinct()->pluck('year'))
            ->map(fn ($y) => (int) $y)->unique()->sortDesc()->values()->all();
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** @return array{year: int, cost_center_id: int, title: string, description: ?string, vendor: ?string, amount_cents: int, booked_on: string, payment_note: ?string} */
    private function validate(array $data, string $kind): array
    {
        $errors = [];
        $title = trim((string) ($data['title'] ?? ''));
        $amount = $data['amount_cents'] ?? null;
        $center = CostCenter::find($data['cost_center_id'] ?? null);

        if ($title === '') {
            $errors['title'] = 'Bitte angeben, wofür das Geld ist.';
        }
        if (! is_int($amount) || $amount <= 0) {
            $errors['amount'] = 'Bitte einen Betrag über 0 angeben, z. B. 24,90.';
        } elseif ($amount > 100_000_000) {
            $errors['amount'] = 'Der Betrag ist zu groß.';
        }
        if (! $center || (! $center->is_active && ($data['keep_center'] ?? null) !== $center->id)) {
            $errors['cost_center_id'] = 'Bitte eine Kostenstelle wählen.';
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) ($data['booked_on'] ?? ''))->startOfDay();
        } catch (Throwable) {
            $date = null;
        }
        if (! $date) {
            $errors['booked_on'] = 'Bitte das Datum des Belegs angeben.';
        } elseif ($date->gt(self::today()->addDays(31))) {
            $errors['booked_on'] = 'Das Datum liegt zu weit in der Zukunft.';
        } elseif (self::isClosed($date->year)) {
            $errors['booked_on'] = 'Das Jahr '.$date->year.' ist abgeschlossen.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'year' => $date->year, 'cost_center_id' => $center->id, 'title' => mb_substr($title, 0, 150),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'vendor' => filled($data['vendor'] ?? null) ? mb_substr(trim($data['vendor']), 0, 150) : null,
            'amount_cents' => $amount, 'booked_on' => $date->toDateString(),
            ...(array_key_exists('payment_note', $data) ? ['payment_note' => filled($data['payment_note']) ? mb_substr(trim($data['payment_note']), 0, 150) : null] : []),
        ];
    }

    /**
     * Photos become WebP (2000 px), PDFs are kept as they are; done before the transaction.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{name: string, mime: string, bytes: string}>
     */
    private function prepareReceipts(array $files, int $existing): array
    {
        if ($existing + count($files) > self::MAX_RECEIPTS) {
            throw ValidationException::withMessages(['files' => 'Höchstens '.self::MAX_RECEIPTS.' Belege je Buchung.']);
        }

        return array_map(function (UploadedFile $file) {
            $mime = (string) $file->getMimeType();
            $name = mb_substr($file->getClientOriginalName() ?: 'Beleg', 0, 200);

            if ($mime === 'application/pdf') {
                if ($file->getSize() > self::MAX_PDF_BYTES) {
                    throw ValidationException::withMessages(['files' => '„'.$name.'“ ist größer als 10 MB.']);
                }

                return ['name' => $name, 'mime' => $mime, 'bytes' => (string) file_get_contents($file->getRealPath())];
            }

            if (! str_starts_with($mime, 'image/')) {
                throw ValidationException::withMessages(['files' => '„'.$name.'“: Bitte ein Foto oder eine PDF-Datei hochladen.']);
            }

            return ['name' => preg_replace('/\.[^.]+$/', '', $name).'.webp', 'mime' => 'image/webp', 'bytes' => $this->images->toWebp($file->getRealPath(), self::IMAGE_SIDE, 'files')];
        }, $files);
    }

    private function storeReceipts(Entry $entry, array $receipts): void
    {
        foreach ($receipts as $receipt) {
            $entry->receipts()->create(['name' => $receipt['name'], 'mime' => $receipt['mime'], 'size' => strlen($receipt['bytes']), 'data' => base64_encode($receipt['bytes'])]);
        }
    }

    private function assertEditable(User $by, Entry $entry): void
    {
        if (self::isClosed($entry->year)) {
            throw ValidationException::withMessages(['entry' => 'Das Jahr '.$entry->year.' ist abgeschlossen.']);
        }

        if (! self::canEdit($by, $entry)) {
            throw ValidationException::withMessages(['entry' => $entry->submitted_by === $by->id
                ? 'Nach der Entscheidung ändert nur der Kassenwart die Buchung.'
                : 'Diese Buchung ändert nur der Kassenwart.']);
        }
    }

    private function assertOpenYear(int $year): void
    {
        if (self::isClosed($year)) {
            throw ValidationException::withMessages(['year' => 'Das Jahr '.$year.' ist abgeschlossen.']);
        }
    }

    private function assertManage(User $by): void
    {
        if (! self::canManage($by)) {
            throw ValidationException::withMessages(['center' => 'Das macht der Kassenwart.']);
        }
    }

    /** Mail failures are reported, never thrown: the change itself stays */
    private function notify(Collection $users, Notification $notification): void
    {
        $users->filter()->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
