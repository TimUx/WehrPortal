<?php

namespace Modules\Finanzen\src\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\ImageResizer;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Builder;
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
 * 2. Kassenwart or the Wehrführung at that Standort approve or reject it (not their own),
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

    /** Kassenwart and admins: shared Kostenstellen, all Standortbudgets, booking, paying, Jahresabschluss */
    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'kassenwart'));
    }

    /** Global finance managers retain cross-location review; local Wehrführung is checked per entry. */
    public static function canReviewGlobally(User $user): bool
    {
        return self::canManage($user) || ($user->is_active && $user->canUseModule(self::MODULE) && $user->hasModuleRole(self::MODULE, 'wehrführung'));
    }

    public static function locationsEnabled(): bool
    {
        return (bool) config('locations.enabled');
    }

    public static function canReviewAt(User $user, ?int $locationId): bool
    {
        if (self::canReviewGlobally($user)) {
            return true;
        }

        return self::locationsEnabled() && $locationId !== null
            && $user->is_active && $user->canUseModule(self::MODULE)
            && $user->hasModuleRole(self::MODULE, 'wehrführung', $locationId);
    }

    public static function canReview(User $user): bool
    {
        return self::canReviewGlobally($user)
            || (self::locationsEnabled() && $user->is_active && $user->canUseModule(self::MODULE)
                && $user->standardRoleLocationIds(self::MODULE, ['wehrführung']) !== []);
    }

    public static function canManageBudgetPage(User $user): bool
    {
        return self::canManage($user) || (self::budgetEnabled() && self::locationsEnabled()
            && $user->standardRoleLocationIds(self::MODULE, ['wehrführung']) !== []);
    }

    /** @return EloquentCollection<int, Location> */
    public static function entryLocationOptions(User $user): EloquentCollection
    {
        if (! self::locationsEnabled()) {
            return new EloquentCollection;
        }

        if (self::canManage($user)) {
            return Location::active()->orderBy('sort_order')->orderBy('name')->get();
        }

        $locationId = (int) ($user->ext_location_id ?? 0);

        return $locationId > 0 ? Location::active()->whereKey($locationId)->get() : new EloquentCollection;
    }

    public static function defaultEntryLocation(User $user): ?int
    {
        $locationId = (int) ($user->ext_location_id ?? 0);

        return self::entryLocationOptions($user)->contains('id', $locationId) ? $locationId : null;
    }

    /** @return EloquentCollection<int, Location> */
    public static function budgetLocationOptions(User $user): EloquentCollection
    {
        if (! self::locationsEnabled()) {
            return new EloquentCollection;
        }

        $query = Location::active();
        if (! self::canManage($user)) {
            $query->whereIn('id', $user->standardRoleLocationIds(self::MODULE, ['wehrführung']));
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return EloquentCollection<int, Location> */
    public static function overviewLocationOptions(User $user): EloquentCollection
    {
        if (! self::locationsEnabled()) {
            return new EloquentCollection;
        }

        $query = Location::active();
        if (! self::canReviewGlobally($user) && ! self::settings()['public']) {
            $query->whereIn('id', $user->standardRoleLocationIds(self::MODULE, ['wehrführung']));
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Null means an unrestricted reviewer; an empty list grants no access. */
    public static function reviewLocationIds(User $user): ?array
    {
        if (self::canReviewGlobally($user)) {
            return null;
        }

        return self::locationsEnabled()
            ? $user->standardRoleLocationIds(self::MODULE, ['wehrführung'])
            : [];
    }

    /** Apply the reviewer's location boundary to a finance-entry query. */
    public static function scopeReviewEntries(User $user, Builder $query): Builder
    {
        $locationIds = self::reviewLocationIds($user);

        return $locationIds === null ? $query : $query->whereIn('location_id', $locationIds);
    }

    // ─── settings ────────────────────────────────────────────────────────────

    public const SETTINGS_KEY = 'finanzen.settings';

    /**
     * What the Wehr uses: budget (budgets per Standort and Kostenstelle), report (consolidated Jahresabschluss with closing the
     * year), public (every Kamerad sees the budget status and all approved/paid Ausgaben; otherwise only
     * their own – the full picture is for Kassenwart, Wehrführung and admins).
     *
     * @return array{budget: bool, report: bool, public: bool}
     */
    public static function settings(): array
    {
        $stored = json_decode((string) Setting::get(self::SETTINGS_KEY, ''), true) ?: [];

        return ['budget' => (bool) ($stored['budget'] ?? true), 'report' => (bool) ($stored['report'] ?? true), 'public' => (bool) ($stored['public'] ?? false)];
    }

    /** @param  array{budget?: bool, report?: bool, public?: bool}  $settings */
    public function saveSettings(User $by, array $settings): void
    {
        $this->assertManage($by);
        Setting::set(self::SETTINGS_KEY, json_encode(array_map('boolval', array_intersect_key($settings + self::settings(), self::settings()))));
    }

    public static function budgetEnabled(): bool
    {
        return self::settings()['budget'];
    }

    public static function reportEnabled(): bool
    {
        return self::settings()['report'];
    }

    /** The budget status and every approved/paid Ausgabe: reviewers always, everyone else only when the Wehr wants it */
    public static function canSeeOverview(User $user): bool
    {
        return self::canReview($user) || self::settings()['public'];
    }

    public static function canView(User $user, Entry $entry): bool
    {
        return self::canReviewAt($user, self::locationsEnabled() ? $entry->location_id : null)
            || $entry->submitted_by === $user->id;
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
    public function reviewers(?int $locationId = null): EloquentCollection
    {
        $reviewers = User::withoutGlobalScopes()->where('is_active', true)->where(function ($query) {
            $query->where('is_admin', true)
                ->orWhereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->whereIn('role', ['kassenwart', 'wehrführung']));
        })
            ->get();
        if ($locationId !== null && self::locationsEnabled()) {
            $standardRoleUserIds = DB::table('x_standard_role_assignments')
                ->where('profile', 'wehrfuehrung')->where('location_id', $locationId)->whereNull('group_id')
                ->pluck('user_id');
            $reviewers = $reviewers->merge(User::withoutGlobalScopes()->where('is_active', true)->whereIn('id', $standardRoleUserIds)->get());
        }
        $reviewers = $reviewers->filter(fn (User $user) => self::canReviewAt($user, $locationId))->unique('id')->values();

        return $reviewers->isNotEmpty() ? $reviewers : User::withoutGlobalScopes()->where('is_active', true)->where('is_admin', true)->get();
    }

    // ─── Ausgaben and Einnahmen ──────────────────────────────────────────────

    /**
     * A Kamerad submits an Ausgabe; the Kassenwart can book an Ausgabe or Einnahme directly as paid.
     *
     * @param  array{kind?: string, cost_center_id: int, title: string, description?: ?string, vendor?: ?string, amount_cents: ?int, booked_on: string, payment_note?: ?string, location_id?: ?int}  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(User $by, array $data, array $files = [], bool $direct = false): Entry
    {
        $direct = $direct && self::canManage($by);
        $kind = $direct ? ($data['kind'] ?? 'expense') : 'expense';
        $clean = $this->validate($by, $data, $kind);
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
            $this->notify($this->reviewers(self::locationsEnabled() ? $entry->location_id : null)->reject(fn (User $u) => $u->is($by)), new ExpenseSubmittedNotification($entry));
        }

        return $entry;
    }

    /** @param  list<UploadedFile>  $files  more Belege */
    public function update(User $by, Entry $entry, array $data, array $files = []): Entry
    {
        $this->assertEditable($by, $entry);
        // A Kostenstelle switched off later stays valid for its own Buchungen
        $clean = $this->validate($by, [...$data, 'keep_center' => $entry->cost_center_id], $entry->kind, $entry);

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
        if (! self::canReviewAt($by, self::locationsEnabled() ? $entry->location_id : null)) {
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

    public function setBudget(User $by, CostCenter $center, int $year, ?int $cents, ?string $notes = null, ?int $locationId = null): void
    {
        $this->assertBudgetManager($by, $locationId);
        $this->assertBudget();
        $this->assertOpenYear($year);
        if ($cents === null || $cents < 0) {
            throw ValidationException::withMessages(['budget' => 'Bitte einen Betrag angeben, z. B. 1.500,00.']);
        }

        $criteria = ['cost_center_id' => $center->id, 'year' => $year];
        if (self::locationsEnabled()) {
            if ($locationId === null && ! Budget::where($criteria)->whereNull('location_id')->exists()) {
                throw ValidationException::withMessages(['location_id' => 'Neue Budgets müssen einem Standort zugewiesen werden.']);
            }
            $criteria['location_id'] = $locationId;
        }

        Budget::updateOrCreate($criteria, ['amount_cents' => $cents, 'notes' => filled($notes) ? trim($notes) : null]);
    }

    /** Takes over the budgets of the year before for Kostenstellen without a budget yet; returns how many */
    public function copyBudgets(User $by, int $year, ?int $locationId = null): int
    {
        $this->assertBudgetManager($by, $locationId);
        $this->assertBudget();
        $this->assertOpenYear($year);
        if (self::locationsEnabled() && $locationId === null) {
            throw ValidationException::withMessages(['location_id' => 'Bitte einen Standort für das Budget auswählen.']);
        }
        $count = 0;

        $previousBudgets = Budget::where('year', $year - 1)
            ->when(self::locationsEnabled(), fn ($query) => $query->where('location_id', $locationId))
            ->get();
        foreach ($previousBudgets as $previous) {
            $criteria = ['cost_center_id' => $previous->cost_center_id, 'year' => $year];
            if (self::locationsEnabled()) {
                $criteria['location_id'] = $locationId;
            }
            $budget = Budget::firstOrCreate($criteria, ['amount_cents' => $previous->amount_cents, 'notes' => $previous->notes]);
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
    public function overview(int $year, bool $activeOnly = false, ?int $locationId = null, bool $unassignedLocation = false): Collection
    {
        $budgetRows = Budget::where('year', $year)
            ->when(self::locationsEnabled() && $locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when(self::locationsEnabled() && $unassignedLocation, fn ($query) => $query->whereNull('location_id'))
            ->get(['cost_center_id', 'amount_cents']);
        $budgets = $budgetRows->groupBy('cost_center_id')->map(fn ($rows) => (int) $rows->sum('amount_cents'));
        $entryQuery = Entry::where('year', $year)->whereIn('status', Entry::BOOKED);
        if (self::locationsEnabled() && $locationId !== null) {
            $entryQuery->where('location_id', $locationId);
        } elseif (self::locationsEnabled() && $unassignedLocation) {
            $entryQuery->whereNull('location_id');
        }
        $sums = $entryQuery
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
        if (! self::reportEnabled()) {
            throw ValidationException::withMessages(['year' => 'Der Jahresabschluss ist ausgeschaltet.']);
        }
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

    /** @return array{year: int, cost_center_id: int, title: string, description: ?string, vendor: ?string, amount_cents: int, booked_on: string, payment_note?: ?string, location_id?: ?int} */
    private function validate(User $by, array $data, string $kind, ?Entry $entry = null): array
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

        $locationId = null;
        if (self::locationsEnabled()) {
            $locationId = self::canManage($by)
                ? (int) (array_key_exists('location_id', $data) ? $data['location_id'] : (self::defaultEntryLocation($by) ?? 0))
                : (int) ($entry ? $entry->location_id : ($by->ext_location_id ?? 0));
            $location = $locationId > 0 ? Location::active()->find($locationId) : null;
            if (! $location) {
                $errors['location_id'] = $entry !== null && $entry->location_id === null
                    ? 'Bitte ordne diese Altbuchung einem aktiven Standort zu.'
                    : 'Bitte einen aktiven Standort wählen.';
            } elseif (! self::canManage($by) && ! self::canReviewAt($by, $locationId)
                && (int) ($by->ext_location_id ?? 0) !== $locationId) {
                $errors['location_id'] = 'Du kannst nur Buchungen für deinen eigenen Standort einreichen.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'year' => $date->year, 'cost_center_id' => $center->id, 'title' => mb_substr($title, 0, 150),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'vendor' => filled($data['vendor'] ?? null) ? mb_substr(trim($data['vendor']), 0, 150) : null,
            'amount_cents' => $amount, 'booked_on' => $date->toDateString(),
            ...(self::locationsEnabled() ? ['location_id' => $locationId] : []),
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

    private function assertBudget(): void
    {
        if (! self::budgetEnabled()) {
            throw ValidationException::withMessages(['budget' => 'Das Budget ist ausgeschaltet.']);
        }
    }

    private function assertManage(User $by): void
    {
        if (! self::canManage($by)) {
            throw ValidationException::withMessages(['center' => 'Das macht der Kassenwart.']);
        }
    }

    private function assertBudgetManager(User $by, ?int $locationId): void
    {
        if (! self::locationsEnabled()) {
            $this->assertManage($by);

            return;
        }

        if ($locationId === null) {
            if (! self::canManage($by)) {
                throw ValidationException::withMessages(['location_id' => 'Für dieses Budget fehlt der Standort.']);
            }

            return;
        }

        if (! Location::active()->whereKey($locationId)->exists() || ! self::canManage($by)
            && ! in_array($locationId, $by->standardRoleLocationIds(self::MODULE, ['wehrführung']), true)) {
            throw ValidationException::withMessages(['location_id' => 'Du darfst das Budget dieses Standorts nicht verwalten.']);
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
