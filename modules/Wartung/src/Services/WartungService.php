<?php

namespace Modules\Wartung\src\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\ImageResizer;
use App\Services\ModuleRegistry;
use App\Support\SignatureImage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Wartung\src\Models\Attachment;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Models\Provider;
use Modules\Wartung\src\Models\Qualification;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Models\Template;
use Modules\Wartung\src\Notifications\WartungDigestNotification;
use Throwable;

/**
 * All changes of the Wartungsmanagement go through here:
 * 1. the Gerätewart keeps Wartungsvorlagen (interval, Voranzeige, checklist) per category or device,
 * 2. plan() opens a Wartung for every device whose next date is within the Voranzeige,
 * 3. a Prüfer (or a Fachfirma) works through the checklist and signs the Prüfprotokoll; it closes
 *    the Wartung, sets the Prüffrist of the device in the Ausrüstungsverwaltung (or marks it
 *    defective) and the next Wartung follows from its date,
 * 4. a protocol never changes; a wrong one is cancelled with a reason.
 * The devices themselves live in the Ausrüstungsverwaltung, which has to be switched on, too.
 */
class WartungService
{
    public const MODULE = 'wartung';

    public const MAX_ATTACHMENTS = 10;

    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    public const HOURLY_RATE_SETTING = 'wartung.hourly_rate_cents';

    private const IMAGE_SIDE = 2000;

    public function __construct(private readonly ImageResizer $images, private readonly EquipmentService $equipment) {}

    // ─── who may do what ─────────────────────────────────────────────────────

    /** The devices come from the Ausrüstungsverwaltung; without it there is nothing to maintain */
    public static function ready(): bool
    {
        return app(ModuleRegistry::class)->isActive('ausruestung');
    }

    /** Everyone with access sees the due Wartungen and the protocols */
    public static function canView(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE);
    }

    /** Gerätewart and admins: templates, Fachfirmen, qualifications, cancel protocols */
    public static function canManage(User $user): bool
    {
        return self::canView($user) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'geraetewart'));
    }

    /** Gerätewart and Prüfer: carry out Wartungen and sign the protocol */
    public static function canPerform(User $user): bool
    {
        return self::canManage($user) || (self::canView($user) && $user->hasModuleRole(self::MODULE, 'pruefer'));
    }

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public static function hourlyRateCents(): int
    {
        return (int) Setting::get(self::HOURLY_RATE_SETTING, 0);
    }

    /** Who gets the reminders; admins when nobody has the role */
    public function managers(): EloquentCollection
    {
        $managers = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'geraetewart'))
            ->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    // ─── templates, Fachfirmen, qualifications, settings ─────────────────────

    /**
     * @param  array{name: string, description?: ?string, category_id?: ?int, equipment_ids?: list<int>, interval_months?: ?int, lead_days?: ?int,
     *     checklist: list<string>, counts_as_inspection?: bool, performer?: string, provider_id?: ?int, required_qualification?: ?string,
     *     estimated_minutes?: ?int, is_active?: bool}  $data
     */
    public function saveTemplate(User $by, ?Template $template, array $data): Template
    {
        $this->assertManager($by);
        $errors = [];

        $name = $this->text($data['name'] ?? null, 150);
        if ($name === null) {
            $errors['name'] = 'Bitte einen Namen angeben, z. B. „Jahresprüfung Feuerwehrleine“.';
        }
        $checklist = collect($data['checklist'] ?? [])->map(fn ($item) => $this->text($item, 200))->filter()->values();
        if ($checklist->isEmpty()) {
            $errors['checklist'] = 'Bitte mindestens einen Prüfpunkt angeben.';
        } elseif ($checklist->count() > 60) {
            $errors['checklist'] = 'Höchstens 60 Prüfpunkte.';
        }
        $interval = filled($data['interval_months'] ?? null) ? (int) $data['interval_months'] : null;
        if ($interval !== null && ($interval < 1 || $interval > 240)) {
            $errors['interval_months'] = 'Das Intervall muss zwischen 1 und 240 Monaten liegen.';
        }
        $lead = (int) ($data['lead_days'] ?? 30);
        if ($lead < 0 || $lead > 365) {
            $errors['lead_days'] = 'Die Voranzeige muss zwischen 0 und 365 Tagen liegen.';
        }
        $categoryId = filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null;
        if ($categoryId && ! Category::whereKey($categoryId)->exists()) {
            $errors['category_id'] = 'Diese Kategorie gibt es nicht.';
        }
        $equipmentIds = Equipment::whereIn('id', collect($data['equipment_ids'] ?? [])->map(fn ($id) => (int) $id))->pluck('id');
        if (! $categoryId && $equipmentIds->isEmpty()) {
            $errors['category_id'] = 'Bitte eine Kategorie oder einzelne Geräte wählen.';
        }
        $performer = ($data['performer'] ?? 'internal') === 'external' ? 'external' : 'internal';
        $providerId = filled($data['provider_id'] ?? null) ? (int) $data['provider_id'] : null;
        if ($providerId && ! Provider::whereKey($providerId)->exists()) {
            $errors['provider_id'] = 'Diese Fachfirma gibt es nicht.';
        }
        $minutes = filled($data['estimated_minutes'] ?? null) ? (int) $data['estimated_minutes'] : null;
        if ($minutes !== null && ($minutes < 0 || $minutes > 6000)) {
            $errors['estimated_minutes'] = 'Bitte einen Zeitaufwand bis 6000 Minuten angeben.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $template = DB::transaction(function () use ($template, $name, $data, $categoryId, $interval, $lead, $checklist, $performer, $providerId, $minutes, $equipmentIds) {
            $template ??= new Template;
            $template->fill([
                'name' => $name, 'description' => $this->text($data['description'] ?? null, 2000), 'category_id' => $categoryId,
                'interval_months' => $interval, 'lead_days' => $lead, 'checklist' => $checklist->all(),
                'counts_as_inspection' => (bool) ($data['counts_as_inspection'] ?? true), 'performer' => $performer,
                'provider_id' => $providerId, 'required_qualification' => $this->text($data['required_qualification'] ?? null, 150),
                'estimated_minutes' => $minutes, 'is_active' => (bool) ($data['is_active'] ?? true),
            ])->save();
            $template->equipment()->sync($equipmentIds->all());

            return $template;
        });

        $this->plan($template);

        return $template;
    }

    /** Templates with protocols are only switched off, so the history keeps its name */
    public function deleteTemplate(User $by, Template $template): bool
    {
        $this->assertManager($by);

        if (Protocol::where('template_id', $template->id)->exists()) {
            $template->update(['is_active' => false]);
            $this->plan($template);

            return false;
        }

        $template->delete();

        return true;
    }

    /** @param  array{name: string, contact_person?: ?string, email?: ?string, phone?: ?string, notes?: ?string, is_active?: bool}  $data */
    public function saveProvider(User $by, ?Provider $provider, array $data): Provider
    {
        $this->assertManager($by);
        $name = $this->text($data['name'] ?? null, 150);
        $email = $this->text($data['email'] ?? null, 150);
        if ($name === null) {
            throw ValidationException::withMessages(['provider_name' => 'Bitte den Namen der Fachfirma angeben.']);
        }
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['provider_email' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
        }

        $provider ??= new Provider;
        $provider->fill([
            'name' => $name, 'contact_person' => $this->text($data['contact_person'] ?? null, 150), 'email' => $email,
            'phone' => $this->text($data['phone'] ?? null, 50), 'notes' => $this->text($data['notes'] ?? null, 2000),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        return $provider;
    }

    /** @param  array{user_id: int, name: string, inspector_number?: ?string, valid_until?: ?string, notes?: ?string}  $data */
    public function saveQualification(User $by, ?Qualification $qualification, array $data): Qualification
    {
        $this->assertManager($by);
        $errors = [];
        if (! User::whereKey((int) ($data['user_id'] ?? 0))->exists()) {
            $errors['qualification_user'] = 'Bitte den Kameraden wählen.';
        }
        $name = $this->text($data['name'] ?? null, 150);
        if ($name === null) {
            $errors['qualification_name'] = 'Bitte die Qualifikation angeben, z. B. „Gerätewart Atemschutz“.';
        }
        $validUntil = null;
        if (filled($data['valid_until'] ?? null)) {
            try {
                $validUntil = Carbon::createFromFormat('!Y-m-d', (string) $data['valid_until']);
            } catch (Throwable) {
                $errors['qualification_valid_until'] = 'Bitte ein gültiges Datum angeben.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $qualification ??= new Qualification;
        $qualification->fill([
            'user_id' => (int) $data['user_id'], 'name' => $name, 'inspector_number' => $this->text($data['inspector_number'] ?? null, 50),
            'valid_until' => $validUntil?->toDateString(), 'notes' => $this->text($data['notes'] ?? null, 300),
        ])->save();

        return $qualification;
    }

    public function deleteQualification(User $by, Qualification $qualification): void
    {
        $this->assertManager($by);
        $qualification->delete();
    }

    public function setHourlyRate(User $by, ?int $cents): void
    {
        $this->assertManager($by);
        if ($cents === null || $cents < 0 || $cents > 100000) {
            throw ValidationException::withMessages(['hourly_rate' => 'Bitte einen Stundensatz zwischen 0 und 1.000 € angeben.']);
        }
        Setting::set(self::HOURLY_RATE_SETTING, $cents);
    }

    // ─── planning ────────────────────────────────────────────────────────────

    /**
     * When the next Wartung of a device after this template is due: the interval after the last
     * protocol; without one, the Prüffrist of the device (if the template counts as Prüfung), else
     * the interval after commissioning or after it was recorded.
     */
    public function nextDue(Template $template, Equipment $equipment, ?Carbon $lastDone = null): ?Carbon
    {
        if ($template->interval_months === null) {
            return null;
        }

        $lastDone ??= ($date = Protocol::where('template_id', $template->id)->where('equipment_id', $equipment->id)->whereNull('voided_at')->max('performed_on'))
            ? Carbon::parse($date) : null;

        return match (true) {
            $lastDone !== null => $lastDone->copy()->addMonths($template->interval_months),
            $template->counts_as_inspection && $equipment->next_inspection_at !== null => $equipment->next_inspection_at->copy(),
            default => Carbon::parse(($equipment->commissioned_at ?? $equipment->created_at)->toDateString())->addMonths($template->interval_months),
        };
    }

    /**
     * Opens the Wartungen that are within their Voranzeige, closes those whose template or device
     * is gone. Runs daily and after a template or protocol is saved. Returns how many it opened.
     */
    public function plan(?Template $only = null): int
    {
        if (! self::ready()) {
            return 0;
        }

        // Template switched off or device retired: the Wartung is no longer needed
        Task::open()->whereNotNull('template_id')
            ->when($only, fn ($q) => $q->where('template_id', $only->id))
            ->where(fn ($q) => $q->whereHas('template', fn ($t) => $t->where('is_active', false)->orWhereNull('interval_months'))
                ->orWhereHas('equipment', fn ($e) => $e->where('status', EquipmentStatus::Retired->value)))
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        $opened = 0;
        $today = self::today();
        $templates = $only ? collect([$only])->filter(fn (Template $t) => $t->is_active && $t->interval_months) : Template::active()->whereNotNull('interval_months')->get();

        foreach ($templates as $template) {
            $lastDone = Protocol::where('template_id', $template->id)->whereNull('voided_at')->whereNotNull('equipment_id')
                ->groupBy('equipment_id')->selectRaw('equipment_id, max(performed_on) as last')->pluck('last', 'equipment_id');
            $open = Task::open()->where('template_id', $template->id)->pluck('equipment_id')->flip();

            foreach ($template->devices()->get() as $device) {
                if ($open->has($device->id)) {
                    continue;
                }
                $due = $this->nextDue($template, $device, isset($lastDone[$device->id]) ? Carbon::parse($lastDone[$device->id]) : null);
                if ($due && $due->lte($today->copy()->addDays($template->lead_days))) {
                    // The partial unique index keeps two planners from opening the same Wartung twice
                    $opened += Task::query()->insertOrIgnore([
                        'template_id' => $template->id, 'equipment_id' => $device->id, 'due_on' => $due->toDateString(),
                        'status' => 'open', 'provider_id' => $template->performer === 'external' ? $template->provider_id : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        return $opened;
    }

    /** An ad hoc Wartung, e.g. after an Einsatz or when a Fachfirma comes anyway */
    public function planAdHoc(User $by, Equipment $equipment, ?Template $template, string $dueOn, ?int $providerId, ?string $notes): Task
    {
        $this->assertManager($by);
        try {
            $due = Carbon::createFromFormat('!Y-m-d', $dueOn);
        } catch (Throwable) {
            throw ValidationException::withMessages(['due_on' => 'Bitte ein gültiges Datum angeben.']);
        }
        if ($template === null && $this->text($notes, 500) === null) {
            throw ValidationException::withMessages(['notes' => 'Bitte angeben, was gemacht werden soll – oder eine Vorlage wählen.']);
        }
        if ($template && Task::open()->where('template_id', $template->id)->where('equipment_id', $equipment->id)->exists()) {
            throw ValidationException::withMessages(['template_id' => 'Für dieses Gerät ist diese Wartung schon offen.']);
        }

        return Task::create([
            'template_id' => $template?->id, 'equipment_id' => $equipment->id, 'due_on' => $due->toDateString(), 'status' => 'open',
            'provider_id' => $providerId ?: ($template?->performer === 'external' ? $template->provider_id : null),
            'notes' => $this->text($notes, 500), 'created_by' => $by->id,
        ]);
    }

    public function cancelTask(User $by, Task $task): void
    {
        $this->assertManager($by);
        if ($task->status === 'open') {
            $task->update(['status' => 'cancelled']);
        }
    }

    // ─── protocols ───────────────────────────────────────────────────────────

    /**
     * A Prüfer works through the checklist and signs. Internal: the signing user with his
     * qualification; external: the Fachfirma's technician signs on the screen.
     *
     * @param  array{task_id?: ?int, template_id?: ?int, equipment_id?: ?int, title?: ?string, performed_on: string, result: string,
     *     checklist?: list<array{item: string, result: string, note?: ?string}>, findings?: ?string, minutes?: ?int, material_cost_cents?: ?int,
     *     performer?: string, qualification_id?: ?int, provider_id?: ?int, technician?: ?string, external_cost_cents?: ?int, counts_as_inspection?: bool}  $data
     * @param  list<UploadedFile>  $files  photos or PDFs (e.g. the Fachfirma's report)
     */
    public function recordProtocol(User $by, array $data, string $signature, array $files = []): Protocol
    {
        if (! self::canPerform($by)) {
            throw ValidationException::withMessages(['protocol' => 'Prüfprotokolle schreiben nur Gerätewarte und Prüfer.']);
        }

        $task = filled($data['task_id'] ?? null) ? Task::open()->find((int) $data['task_id']) : null;
        if (filled($data['task_id'] ?? null) && ! $task) {
            throw ValidationException::withMessages(['protocol' => 'Diese Wartung ist schon erledigt.']);
        }
        $template = $task?->template ?? (filled($data['template_id'] ?? null) ? Template::find((int) $data['template_id']) : null);
        $equipment = $task?->equipment ?? Equipment::find((int) ($data['equipment_id'] ?? 0));
        $clean = $this->validateProtocol($by, $data, $template, $equipment);
        $png = SignatureImage::fromDataUrl($signature, 'signature');
        $attachments = $this->prepareAttachments($files);
        $countsAsInspection = (bool) ($template?->counts_as_inspection ?? ($data['counts_as_inspection'] ?? false));

        $protocol = DB::transaction(function () use ($by, $clean, $png, $attachments, $task, $template, $equipment) {
            $year = $clean['performed_on']->year;
            DB::select('select pg_advisory_xact_lock(?)', [crc32('wm_protocols_'.$year)]);

            $protocol = new Protocol([
                ...$clean,
                'year' => $year, 'number' => (int) Protocol::where('year', $year)->max('number') + 1,
                'template_id' => $template?->id, 'equipment_id' => $equipment->id, 'equipment_code' => $equipment->code, 'equipment_name' => $equipment->name,
                'signature' => $png, 'signed_at' => now()->startOfSecond(), 'recorded_by' => $by->id,
            ]);
            $protocol->content_hash = $this->hash($protocol, collect($attachments)->pluck('hash'));
            $protocol->save();

            foreach ($attachments as $file) {
                $protocol->attachments()->create($file);
            }

            // This Wartung is done, and so is an open one of the same template for this device
            if ($task || $template) {
                Task::open()->where('equipment_id', $equipment->id)
                    ->where(fn ($q) => $q->when($task, fn ($q) => $q->whereKey($task->id))->when($template, fn ($q) => $q->orWhere('template_id', $template->id)))
                    ->update(['status' => 'done', 'protocol_id' => $protocol->id, 'updated_at' => now()]);
            }

            return $protocol;
        });

        // The device in the Ausrüstungsverwaltung: new Prüffrist, or out of service when it failed
        try {
            $note = 'Prüfprotokoll '.$protocol->code().($protocol->findings ? ': '.$protocol->findings : '');
            if ($countsAsInspection) {
                $this->equipment->recordInspection($equipment, $by, $protocol->performed_on->copy(), $protocol->passed(), $note);
            } elseif (! $protocol->passed()) {
                $this->equipment->reportDamage($equipment, $by, 'Wartung nicht bestanden – '.$note);
            } else {
                $this->equipment->log($equipment, $by, 'inspection', 'Wartung „'.$protocol->title.'“ bestanden – '.$protocol->code());
            }
        } catch (Throwable $e) {
            report($e);
        }

        if ($template) {
            $this->plan($template);
        }

        return $protocol;
    }

    /** A wrong protocol is cancelled, never deleted. The Prüffrist of the device stays; check it there. */
    public function voidProtocol(User $by, Protocol $protocol, string $reason): void
    {
        $this->assertManager($by);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['void_reason' => 'Bitte den Grund für die Stornierung angeben.']);
        }
        if (! $protocol->isVoided()) {
            $protocol->update(['voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 500), 'voided_by' => $by->id]);
        }
    }

    /** Whether content, signature and attachments are still exactly what was signed */
    public function verify(Protocol $protocol): bool
    {
        $files = Attachment::where('protocol_id', $protocol->id)->orderBy('id')->get(['id', 'hash', 'data']);
        if ($files->contains(fn (Attachment $a) => ! hash_equals($a->hash, hash('sha256', (string) base64_decode($a->data))))) {
            return false;
        }

        return hash_equals($protocol->content_hash, $this->hash($protocol, $files->pluck('hash')));
    }

    /**
     * Daily mail to the Gerätewarte: Wartungen that came into their Voranzeige and those that just
     * became overdue. Each is claimed first, so a second scheduler can't mail it again.
     */
    public function remind(): int
    {
        if (! self::ready() || ! app(ModuleRegistry::class)->isActive(self::MODULE)) {
            return 0;
        }

        $today = self::today()->toDateString();
        $claim = fn (string $column, string $extra = '') => collect(DB::select(
            "UPDATE wm_tasks SET {$column} = now() WHERE status = 'open' AND {$column} IS NULL {$extra} RETURNING id", $extra ? [$today] : []
        ))->pluck('id');
        $dueIds = $claim('due_mailed_at');
        $overdueIds = $claim('overdue_mailed_at', 'AND due_on < ?');

        $due = Task::whereIn('id', $dueIds)->where('due_on', '>=', $today)->with(['template', 'equipment'])->orderBy('due_on')->get();
        $overdue = Task::whereIn('id', $overdueIds)->with(['template', 'equipment'])->orderBy('due_on')->get();
        if ($due->isEmpty() && $overdue->isEmpty()) {
            return 0;
        }

        $this->managers()->each(function (User $user) use ($due, $overdue) {
            try {
                $user->notify(new WartungDigestNotification($due, $overdue));
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $due->count() + $overdue->count();
    }

    /** @return array{count: int, failed: int, minutes: int, cost: int} the protocols of a year, cancelled ones left out */
    public function yearTotals(int $year): array
    {
        $row = Protocol::where('year', $year)->whereNull('voided_at')
            ->selectRaw("count(*) as count, count(*) filter (where result = 'failed') as failed, coalesce(sum(minutes), 0) as minutes,
                coalesce(sum(labor_cost_cents + material_cost_cents + external_cost_cents), 0) as cost")
            ->first();

        return ['count' => (int) $row->count, 'failed' => (int) $row->failed, 'minutes' => (int) $row->minutes, 'cost' => (int) $row->cost];
    }

    // ─── details ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateProtocol(User $by, array $data, ?Template $template, ?Equipment $equipment): array
    {
        $errors = [];
        if (! $equipment) {
            throw ValidationException::withMessages(['equipment_id' => 'Bitte das Gerät wählen.']);
        }
        if ($equipment->status === EquipmentStatus::Retired) {
            $errors['equipment_id'] = 'Das Gerät ist ausgesondert.';
        }

        $title = $template?->name ?? $this->text($data['title'] ?? null, 150);
        if ($title === null) {
            $errors['title'] = 'Bitte angeben, was gemacht wurde, z. B. „Sichtprüfung nach Einsatz“.';
        }

        $performedOn = null;
        try {
            $performedOn = Carbon::createFromFormat('!Y-m-d', (string) ($data['performed_on'] ?? ''));
        } catch (Throwable) {
            $errors['performed_on'] = 'Bitte das Datum der Prüfung angeben.';
        }
        if ($performedOn && $performedOn->gt(self::today())) {
            $errors['performed_on'] = 'Das Datum liegt in der Zukunft.';
        } elseif ($performedOn && $performedOn->lt(self::today()->subYears(2))) {
            $errors['performed_on'] = 'Das Datum liegt mehr als zwei Jahre zurück.';
        }

        // The checklist follows the template; without a template the Prüfer may add his own points
        $given = collect($data['checklist'] ?? [])->values();
        $items = $template ? collect($template->checklist) : $given->pluck('item')->map(fn ($i) => $this->text($i, 200))->filter()->values();
        $checklist = $items->map(function (string $item, int $i) use ($given, $template, &$errors) {
            $row = $template ? ($given[$i] ?? []) : ($given->firstWhere('item', $item) ?? []);
            $result = (string) ($row['result'] ?? '');
            $note = $this->text($row['note'] ?? null, 500);
            if (! array_key_exists($result, Protocol::ITEM_RESULTS)) {
                $errors['checklist.'.$i] = 'Bitte „'.$item.'“ bewerten.';
            } elseif ($result === 'defect' && $note === null) {
                $errors['checklist.'.$i] = 'Bitte den Mangel bei „'.$item.'“ beschreiben.';
            }

            return ['item' => $item, 'result' => $result, 'note' => $note];
        })->all();

        $result = (string) ($data['result'] ?? '');
        $findings = $this->text($data['findings'] ?? null, 5000);
        if (! array_key_exists($result, Protocol::RESULTS)) {
            $errors['result'] = 'Bitte das Ergebnis angeben.';
        } elseif ($result === 'failed' && $findings === null && ! collect($checklist)->contains('result', 'defect')) {
            $errors['findings'] = 'Bitte beschreiben, warum das Gerät nicht bestanden hat.';
        }
        if (! $template && $checklist === [] && $findings === null) {
            $errors['findings'] = 'Bitte Prüfpunkte oder einen Befund angeben.';
        }

        $minutes = (int) ($data['minutes'] ?? 0);
        $material = (int) ($data['material_cost_cents'] ?? 0);
        $external = (int) ($data['external_cost_cents'] ?? 0);
        if ($minutes < 0 || $minutes > 10000) {
            $errors['minutes'] = 'Bitte einen Zeitaufwand bis 10.000 Minuten angeben.';
        }
        if ($material < 0 || $external < 0 || $material > 10_000_000 || $external > 10_000_000) {
            $errors['costs'] = 'Bitte gültige Kosten angeben.';
        }

        $performer = ($data['performer'] ?? $template?->performer ?? 'internal') === 'external' ? 'external' : 'internal';
        $inspector = ['inspector_user_id' => null, 'inspector_name' => '', 'inspector_number' => null, 'qualification' => null, 'provider_id' => null, 'provider_name' => null];
        if ($performer === 'external') {
            $provider = Provider::find((int) ($data['provider_id'] ?? 0));
            $technician = $this->text($data['technician'] ?? null, 150);
            if (! $provider) {
                $errors['provider_id'] = 'Bitte die Fachfirma wählen.';
            }
            if ($technician === null) {
                $errors['technician'] = 'Bitte den Namen des Prüfers der Fachfirma angeben.';
            }
            $inspector = [...$inspector, 'inspector_name' => (string) $technician, 'inspector_number' => $this->text($data['inspector_number'] ?? null, 50),
                'provider_id' => $provider?->id, 'provider_name' => $provider?->name];
        } else {
            $qualification = filled($data['qualification_id'] ?? null)
                ? Qualification::valid()->where('user_id', $by->id)->find((int) $data['qualification_id']) : null;
            if (filled($data['qualification_id'] ?? null) && ! $qualification) {
                $errors['qualification_id'] = 'Diese Qualifikation ist nicht (mehr) gültig.';
            }
            $required = $template?->required_qualification;
            if ($required && (! $qualification || mb_strtolower($qualification->name) !== mb_strtolower($required))) {
                $errors['qualification_id'] = 'Für diese Prüfung ist die Qualifikation „'.$required.'“ nötig. Der Gerätewart trägt sie unter „Prüfer“ ein.';
            }
            $inspector = [...$inspector, 'inspector_user_id' => $by->id, 'inspector_name' => $by->name,
                'inspector_number' => $qualification?->inspector_number, 'qualification' => $qualification?->name];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $rate = self::hourlyRateCents();

        return [
            'title' => $title, 'performed_on' => $performedOn, 'result' => $result, 'checklist' => $checklist, 'findings' => $findings,
            'minutes' => $minutes, 'hourly_rate_cents' => $rate, 'labor_cost_cents' => (int) round($minutes / 60 * $rate),
            'material_cost_cents' => $material, 'external_cost_cents' => $performer === 'external' ? $external : 0,
            'performer' => $performer, ...$inspector,
        ];
    }

    /** @return list<array{name: string, mime: string, size: int, data: string, hash: string}> */
    private function prepareAttachments(array $files): array
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['files' => 'Höchstens '.self::MAX_ATTACHMENTS.' Fotos oder Dateien je Protokoll.']);
        }

        return array_map(function (UploadedFile $file) {
            $mime = (string) $file->getMimeType();
            $name = mb_substr($file->getClientOriginalName() ?: 'Anhang', 0, 200);

            if ($mime === 'application/pdf') {
                if ($file->getSize() > self::MAX_PDF_BYTES) {
                    throw ValidationException::withMessages(['files' => '„'.$name.'“ ist größer als 10 MB.']);
                }
                $bytes = (string) file_get_contents($file->getRealPath());
            } elseif (str_starts_with($mime, 'image/')) {
                $bytes = $this->images->toWebp($file->getRealPath(), self::IMAGE_SIDE, 'files');
                [$mime, $name] = ['image/webp', preg_replace('/\.[^.]+$/', '', $name).'.webp'];
            } else {
                throw ValidationException::withMessages(['files' => '„'.$name.'“: Bitte ein Foto oder eine PDF-Datei hochladen.']);
            }

            return ['name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'data' => base64_encode($bytes), 'hash' => hash('sha256', $bytes)];
        }, $files);
    }

    /** SHA-256 over everything that was signed */
    private function hash(Protocol $protocol, Collection $attachmentHashes): string
    {
        return hash('sha256', json_encode([
            'number' => $protocol->year.'-'.$protocol->number, 'equipment' => [$protocol->equipment_code, $protocol->equipment_name],
            'title' => $protocol->title, 'performed_on' => $protocol->performed_on->toDateString(), 'result' => $protocol->result,
            'checklist' => $protocol->checklist, 'findings' => $protocol->findings,
            'time' => [$protocol->minutes, $protocol->hourly_rate_cents, $protocol->labor_cost_cents, $protocol->material_cost_cents, $protocol->external_cost_cents],
            'performer' => [$protocol->performer, $protocol->inspector_name, $protocol->inspector_number, $protocol->qualification, $protocol->provider_name],
            'signature' => hash('sha256', $protocol->signature), 'signed_at' => $protocol->signed_at->copy()->utc()->format('Y-m-d H:i:s'),
            'attachments' => $attachmentHashes->values()->all(),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['wartung' => 'Das dürfen nur Gerätewarte und Admins.']);
        }
    }
}
