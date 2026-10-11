<?php

namespace Modules\Fahrzeuge\src\Services;

use App\Models\User;
use App\Services\ImageResizer;
use App\Services\ModuleRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Models\Location;
use Modules\Einsatzbericht\src\Models\ReportVehicle;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Downtime;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Models\Record;
use Modules\Fahrzeuge\src\Models\StatusEvent;
use Modules\Fahrzeuge\src\Notifications\DeadlineDigestNotification;
use Modules\Fahrzeuge\src\Notifications\VehicleUnavailableNotification;
use Throwable;

/**
 * All changes of the Fahrzeugverwaltung go through here. The vehicles are the shared list of the
 * Fahrzeugbuchung and the Einsatzbericht; this module adds the profile, the status, downtimes
 * (which block bookings via the VehicleBlockRegistry), deadlines and their documentation, and
 * reads who uses a vehicle right now: an Einsatz (Einsatzbericht) or a booking (Fahrzeugbuchung).
 */
class FleetService
{
    public const MODULE = 'fahrzeuge';

    public const MAX_ATTACHMENTS = 5;

    public function __construct(private readonly ImageResizer $images) {}

    // ─── who may do what ─────────────────────────────────────────────────────

    public static function canView(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE);
    }

    /** Fahrzeugwart and admins: profiles, status, Werkstatt, deadlines and documentation */
    public static function canManage(User $user): bool
    {
        return self::canView($user) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'fahrzeugwart'));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'fahrzeugwart'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public function profile(Vehicle $vehicle): Profile
    {
        return Profile::firstOrNew(['vehicle_id' => $vehicle->id]);
    }

    // ─── vehicle and profile ─────────────────────────────────────────────────

    /**
     * Create or change a vehicle with its profile. Name, Kurzname, colour and order are the
     * shared fields the Fahrzeugbuchung shows too.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveVehicle(User $by, ?Vehicle $vehicle, array $data, ?UploadedFile $photo = null): Vehicle
    {
        $this->assertManager($by);
        $errors = [];
        $name = $this->text($data['name'] ?? null, 255);
        if ($name === null) {
            $errors['name'] = 'Bitte den Namen angeben, z. B. „HLF 20“.';
        }
        $color = (string) ($data['color'] ?? '#3b82f6');
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors['color'] = 'Bitte eine Farbe wählen.';
        }
        $category = $data['category'] ?? null;
        if (filled($category) && ! array_key_exists($category, Profile::CATEGORIES)) {
            $errors['category'] = 'Bitte eine Fahrzeugart wählen.';
        }
        $numbers = [];
        foreach (['year_built' => [1900, (int) now()->year + 1], 'gross_weight_kg' => [100, 60000], 'water_liters' => [0, 50000],
            'foam_liters' => [0, 20000], 'mileage_km' => [0, 3000000]] as $field => [$min, $max]) {
            $value = $data[$field] ?? null;
            $numbers[$field] = filled($value) ? (int) $value : null;
            if (filled($value) && (! is_numeric($value) || $numbers[$field] < $min || $numbers[$field] > $max)) {
                $errors[$field] = 'Bitte eine gültige Zahl angeben.';
            }
        }
        $hours = filled($data['operating_hours'] ?? null) ? (float) str_replace(',', '.', (string) $data['operating_hours']) : null;
        $firstRegistration = $this->date($data['first_registration'] ?? null, 'first_registration', $errors);
        $locationId = filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null;
        if ($locationId && ! Location::whereKey($locationId)->exists()) {
            $errors['location_id'] = 'Diesen Standort gibt es nicht.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $photoData = $photo ? base64_encode($this->images->toWebp($photo->getRealPath(), 1600, 'photo')) : null;

        return DB::transaction(function () use ($vehicle, $data, $name, $color, $category, $numbers, $hours, $firstRegistration, $locationId, $photoData) {
            $vehicle ??= new Vehicle(['is_active' => true]);
            $vehicle->fill([
                'name' => $name, 'short_name' => $this->text($data['short_name'] ?? null, 255), 'color' => $color,
                'description' => $this->text($data['description'] ?? null, 2000), 'sort_order' => (int) ($data['sort_order'] ?? $vehicle->sort_order ?? 0),
            ])->save();

            $profile = $this->profile($vehicle);
            $mileageChanged = $numbers['mileage_km'] !== null && $numbers['mileage_km'] !== $profile->mileage_km;
            $profile->fill([
                'call_sign' => $this->text($data['call_sign'] ?? null, 50), 'license_plate' => $this->text(mb_strtoupper((string) ($data['license_plate'] ?? '')), 20),
                'category' => $category ?: null, 'vehicle_type' => $this->text($data['vehicle_type'] ?? null, 80), 'chassis' => $this->text($data['chassis'] ?? null, 100),
                'body_builder' => $this->text($data['body_builder'] ?? null, 100), 'year_built' => $numbers['year_built'],
                'first_registration' => $firstRegistration?->toDateString(), 'vin' => $this->text(mb_strtoupper((string) ($data['vin'] ?? '')), 30),
                'gross_weight_kg' => $numbers['gross_weight_kg'], 'crew' => $this->text($data['crew'] ?? null, 20), 'license_class' => $this->text($data['license_class'] ?? null, 30),
                'water_liters' => $numbers['water_liters'], 'foam_liters' => $numbers['foam_liters'], 'pump' => $this->text($data['pump'] ?? null, 80),
                'features' => collect($data['features'] ?? [])->map(fn ($f) => $this->text($f, 100))->filter()->unique()->values()->all(),
                'notes' => $this->text($data['notes'] ?? null, 5000), 'mileage_km' => $numbers['mileage_km'], 'operating_hours' => $hours,
                'mileage_on' => $mileageChanged ? self::today()->toDateString() : $profile->mileage_on?->toDateString(), 'location_id' => $locationId,
                ...($photoData ? ['photo' => $photoData, 'photo_updated_at' => now()] : []),
            ])->save();

            return $vehicle;
        });
    }

    public function removePhoto(User $by, Vehicle $vehicle): void
    {
        $this->assertManager($by);
        Profile::where('vehicle_id', $vehicle->id)->update(['photo' => null, 'photo_updated_at' => null]);
    }

    // ─── status and downtimes ────────────────────────────────────────────────

    public function setStatus(User $by, Vehicle $vehicle, string $status, ?string $note): void
    {
        $this->assertManager($by);
        if (! array_key_exists($status, Profile::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Bitte einen Status wählen.']);
        }
        $note = $this->text($note, 300);
        if ($status !== 'ready' && $note === null) {
            throw ValidationException::withMessages(['status_note' => 'Bitte kurz angeben, was los ist.']);
        }

        $profile = $this->profile($vehicle);
        $profile->fill(['status' => $status, 'status_note' => $status === 'ready' ? null : $note, 'status_changed_at' => now()])->save();
        StatusEvent::create(['vehicle_id' => $vehicle->id, 'status' => $status, 'note' => $note, 'user_id' => $by->id]);
    }

    /** Außer Dienst: no longer bookable, not offered in the Einsatzbericht; the history stays */
    public function decommission(User $by, Vehicle $vehicle, ?string $note): void
    {
        $this->assertManager($by);
        $vehicle->update(['is_active' => false]);
        StatusEvent::create(['vehicle_id' => $vehicle->id, 'status' => 'decommissioned', 'note' => $this->text($note, 300), 'user_id' => $by->id]);
    }

    public function recommission(User $by, Vehicle $vehicle): void
    {
        $this->assertManager($by);
        $vehicle->update(['is_active' => true]);
        StatusEvent::create(['vehicle_id' => $vehicle->id, 'status' => 'recommissioned', 'user_id' => $by->id]);
    }

    /**
     * Werkstatt or another downtime. It blocks new bookings in that time; the people whose
     * bookings it overlaps are told (their bookings stay, they decide).
     *
     * @return Collection<int, Booking> the overlapping bookings
     */
    public function addDowntime(User $by, Vehicle $vehicle, string $startsAt, ?string $endsAt, string $reason): Collection
    {
        $this->assertManager($by);
        $errors = [];
        $start = $this->time($startsAt, 'downtime_starts_at', $errors);
        $end = filled($endsAt) ? $this->time($endsAt, 'downtime_ends_at', $errors) : null;
        $reason = $this->text($reason, 200);
        if ($reason === null) {
            $errors['downtime_reason'] = 'Bitte den Grund angeben, z. B. „Werkstatt: Bremsen“.';
        }
        if ($start && $end && $end->lte($start)) {
            $errors['downtime_ends_at'] = 'Das Ende muss nach dem Beginn liegen.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $downtime = Downtime::create(['vehicle_id' => $vehicle->id, 'starts_at' => $start, 'ends_at' => $end, 'reason' => $reason, 'created_by' => $by->id]);
        if ($downtime->isCurrent() && $this->profile($vehicle)->status === 'ready') {
            $this->setStatus($by, $vehicle, 'unavailable', $reason);
        }

        $bookings = app(ModuleRegistry::class)->isActive('fahrzeugbuchung')
            ? Booking::visible()->with('user')->where('vehicle_id', $vehicle->id)->where('ends_at', '>', $start)
                ->when($end, fn ($q) => $q->where('starts_at', '<', $end))->orderBy('starts_at')->get()
            : collect();
        $bookings->groupBy('user_id')->each(fn ($own) => $this->notify(collect([$own->first()->user]), new VehicleUnavailableNotification($downtime, $own)));

        return $bookings;
    }

    /** Back from the Werkstatt: a running downtime ends now, a planned one is removed */
    public function endDowntime(User $by, Downtime $downtime): void
    {
        $this->assertManager($by);
        if ($downtime->starts_at->isFuture()) {
            $downtime->delete();

            return;
        }
        if ($downtime->ends_at === null || $downtime->ends_at->isFuture()) {
            $downtime->update(['ends_at' => now()]);
        }

        $vehicle = $downtime->vehicle;
        $stillDown = Downtime::where('vehicle_id', $vehicle->id)->overlapping(now(), now()->addSecond())->exists();
        if (! $stillDown && $this->profile($vehicle)->status === 'unavailable') {
            $this->setStatus($by, $vehicle, 'ready', null);
        }
    }

    /** For the VehicleBlockRegistry: downtimes overlapping a period */
    public static function blocks(CarbonInterface $start, CarbonInterface $end, ?array $vehicleIds): Collection
    {
        return Downtime::overlapping($start, $end)->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))->get()
            ->map(fn (Downtime $d) => ['vehicle_id' => $d->vehicle_id, 'starts_at' => $d->starts_at, 'ends_at' => $d->ends_at, 'reason' => $d->reason]);
    }

    // ─── deadlines and documentation ─────────────────────────────────────────

    /** @param  array{title: string, interval_months?: ?int, due_on: string, lead_days?: ?int, notes?: ?string}  $data */
    public function saveDeadline(User $by, Vehicle $vehicle, ?Deadline $deadline, array $data): Deadline
    {
        $this->assertManager($by);
        $errors = [];
        $title = $this->text($data['title'] ?? null, 150);
        if ($title === null) {
            $errors['deadline_title'] = 'Bitte angeben, was fällig wird, z. B. „Hauptuntersuchung (HU)“.';
        }
        $due = $this->date($data['due_on'] ?? null, 'deadline_due_on', $errors);
        if (! $due && ! isset($errors['deadline_due_on'])) {
            $errors['deadline_due_on'] = 'Bitte das Fälligkeitsdatum angeben.';
        }
        $interval = filled($data['interval_months'] ?? null) ? (int) $data['interval_months'] : null;
        if ($interval !== null && ($interval < 1 || $interval > 240)) {
            $errors['deadline_interval'] = 'Das Intervall muss zwischen 1 und 240 Monaten liegen.';
        }
        $lead = (int) ($data['lead_days'] ?? 30);
        if ($lead < 0 || $lead > 365) {
            $errors['deadline_lead'] = 'Die Vorwarnung muss zwischen 0 und 365 Tagen liegen.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $deadline ??= new Deadline(['vehicle_id' => $vehicle->id]);
        $dateChanged = ! $deadline->exists || $deadline->due_on?->toDateString() !== $due->toDateString();
        $deadline->fill(['title' => $title, 'interval_months' => $interval, 'due_on' => $due->toDateString(), 'lead_days' => $lead,
            'notes' => $this->text($data['notes'] ?? null, 500), 'is_active' => true,
            ...($dateChanged ? ['due_mailed_at' => null, 'overdue_mailed_at' => null] : [])])->save();

        return $deadline;
    }

    public function deleteDeadline(User $by, Deadline $deadline): void
    {
        $this->assertManager($by);
        $deadline->records()->exists() ? $deadline->update(['is_active' => false]) : $deadline->delete();
    }

    /**
     * Document a Prüfung, Wartung or Reparatur. Done for a deadline, that deadline moves on by its
     * interval (or is closed without one); a higher Kilometerstand goes into the profile.
     *
     * @param  array{deadline_id?: ?int, kind: string, title?: ?string, done_on: string, mileage_km?: ?int, cost_cents?: ?int, provider?: ?string, notes?: ?string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function recordWork(User $by, Vehicle $vehicle, array $data, array $files = []): Record
    {
        $this->assertManager($by);
        $errors = [];
        $deadline = filled($data['deadline_id'] ?? null) ? Deadline::where('vehicle_id', $vehicle->id)->find((int) $data['deadline_id']) : null;
        $kind = (string) ($data['kind'] ?? ($deadline ? 'inspection' : ''));
        if (! array_key_exists($kind, Record::KINDS)) {
            $errors['record_kind'] = 'Bitte die Art wählen.';
        }
        $title = $this->text($data['title'] ?? null, 150) ?? $deadline?->title;
        if ($title === null) {
            $errors['record_title'] = 'Bitte angeben, was gemacht wurde.';
        }
        $done = $this->date($data['done_on'] ?? null, 'record_done_on', $errors);
        if ($done && $done->gt(self::today())) {
            $errors['record_done_on'] = 'Das Datum liegt in der Zukunft.';
        } elseif (! $done && ! isset($errors['record_done_on'])) {
            $errors['record_done_on'] = 'Bitte das Datum angeben.';
        }
        $mileage = filled($data['mileage_km'] ?? null) ? (int) $data['mileage_km'] : null;
        if ($mileage !== null && ($mileage < 0 || $mileage > 3000000)) {
            $errors['record_mileage'] = 'Bitte einen gültigen Kilometerstand angeben.';
        }
        if (count($files) > self::MAX_ATTACHMENTS) {
            $errors['record_files'] = 'Höchstens '.self::MAX_ATTACHMENTS.' Dateien je Eintrag.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $attachments = array_map(fn (UploadedFile $file) => $this->attachment($file), $files);

        return DB::transaction(function () use ($by, $vehicle, $deadline, $kind, $title, $done, $mileage, $data, $attachments) {
            $record = Record::create([
                'vehicle_id' => $vehicle->id, 'deadline_id' => $deadline?->id, 'kind' => $kind, 'title' => $title, 'done_on' => $done->toDateString(),
                'mileage_km' => $mileage, 'cost_cents' => $data['cost_cents'] ?? null, 'provider' => $this->text($data['provider'] ?? null, 150),
                'notes' => $this->text($data['notes'] ?? null, 5000), 'created_by' => $by->id,
            ]);
            foreach ($attachments as $file) {
                $record->attachments()->create($file);
            }

            if ($deadline) {
                $deadline->update($deadline->interval_months
                    ? ['due_on' => $done->copy()->addMonths($deadline->interval_months)->toDateString(), 'due_mailed_at' => null, 'overdue_mailed_at' => null]
                    : ['is_active' => false]);
            }

            $profile = $this->profile($vehicle);
            if ($mileage !== null && $mileage > (int) $profile->mileage_km) {
                $profile->fill(['mileage_km' => $mileage, 'mileage_on' => $done->toDateString()])->save();
            }

            return $record;
        });
    }

    // ─── what is going on ────────────────────────────────────────────────────

    /**
     * Availability of the given vehicles right now, in this order: außer Dienst, Werkstatt
     * (downtime), nicht einsatzbereit, im Einsatz, gebucht, bedingt einsatzbereit, einsatzbereit.
     *
     * @return Collection<int, array{key: string, label: string, detail: ?string, tone: string, overdue: int}> by vehicle id
     */
    public function states(Collection $vehicles): Collection
    {
        $ids = $vehicles->pluck('id')->all();
        $now = now();
        $profiles = Profile::whereIn('vehicle_id', $ids)->get()->keyBy('vehicle_id');
        $downtimes = Downtime::whereIn('vehicle_id', $ids)->overlapping($now, $now->copy()->addSecond())->get()->keyBy('vehicle_id');
        $einsatz = $this->runningAssignments()->keyBy('vehicle_id');
        $bookings = app(ModuleRegistry::class)->isActive('fahrzeugbuchung')
            ? Booking::active()->with('user')->whereIn('vehicle_id', $ids)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->get()->keyBy('vehicle_id')
            : collect();
        $overdue = Deadline::active()->whereIn('vehicle_id', $ids)->where('due_on', '<', self::today()->toDateString())
            ->selectRaw('vehicle_id, count(*) as n')->groupBy('vehicle_id')->pluck('n', 'vehicle_id');

        return $vehicles->mapWithKeys(function (Vehicle $v) use ($profiles, $downtimes, $einsatz, $bookings, $overdue) {
            $profile = $profiles[$v->id] ?? null;
            $state = match (true) {
                ! $v->is_active => ['key' => 'decommissioned', 'label' => 'Außer Dienst', 'detail' => null, 'tone' => 'gray'],
                isset($downtimes[$v->id]) => ['key' => 'downtime', 'label' => 'Nicht verfügbar', 'detail' => $downtimes[$v->id]->reason.($downtimes[$v->id]->ends_at ? ' bis '.$downtimes[$v->id]->ends_at->setTimezone('Europe/Berlin')->format('d.m. H:i') : ''), 'tone' => 'red'],
                $profile?->status === 'unavailable' => ['key' => 'unavailable', 'label' => 'Nicht einsatzbereit', 'detail' => $profile->status_note, 'tone' => 'red'],
                isset($einsatz[$v->id]) => ['key' => 'einsatz', 'label' => 'Im Einsatz', 'detail' => $einsatz[$v->id]['keyword'] ?: 'Einsatz '.$einsatz[$v->id]['number'], 'tone' => 'blue'],
                isset($bookings[$v->id]) => ['key' => 'booked', 'label' => 'Gebucht', 'detail' => $bookings[$v->id]->user->name.' bis '.$bookings[$v->id]->ends_at->setTimezone('Europe/Berlin')->format('d.m. H:i'), 'tone' => 'blue'],
                $profile?->status === 'limited' => ['key' => 'limited', 'label' => 'Bedingt einsatzbereit', 'detail' => $profile->status_note, 'tone' => 'amber'],
                default => ['key' => 'ready', 'label' => 'Einsatzbereit', 'detail' => null, 'tone' => 'green'],
            };

            return [$v->id => [...$state, 'overdue' => (int) ($overdue[$v->id] ?? 0)]];
        });
    }

    /**
     * Vehicles out at a running Einsatz (Einsatzbericht): left the station, not yet back.
     *
     * @return Collection<int, array{vehicle_id: ?int, name: string, report_id: int, number: string, keyword: ?string, departed_at: ?Carbon, crew: int}>
     */
    public function runningAssignments(): Collection
    {
        if (! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return collect();
        }

        return ReportVehicle::with('report')->withCount('crew')->where('is_standby', false)->whereNull('returned_at')
            ->whereHas('report', fn ($q) => $q->where('status', 'active')->whereNull('ended_at'))
            ->get()
            ->map(fn (ReportVehicle $rv) => ['vehicle_id' => $rv->vehicle_id, 'name' => $rv->name, 'report_id' => $rv->report_id, 'number' => $rv->report->number,
                'keyword' => $rv->report->keyword, 'departed_at' => $rv->departed_at, 'crew' => (int) $rv->crew_count]);
    }

    /** @return Collection<int, object{report_id: int, number: string, keyword: ?string, alarmed_at: string, category: ?string, crew: int}> */
    public function einsatzHistory(Vehicle $vehicle, int $limit = 50): Collection
    {
        if (! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return collect();
        }

        return DB::table('eb_report_vehicles as rv')->join('eb_reports as r', 'r.id', '=', 'rv.report_id')
            ->where('rv.vehicle_id', $vehicle->id)->where('rv.is_standby', false)
            ->selectRaw('r.id as report_id, r.number, r.keyword, r.alarmed_at, r.category, (select count(*) from eb_report_crew c where c.report_vehicle_id = rv.id) as crew')
            ->orderByDesc('r.alarmed_at')->limit($limit)->get();
    }

    /**
     * Daily mail to the Fahrzeugwarte: deadlines that came into their Vorwarnung and those that
     * just became overdue. Claimed first, so a second scheduler can't mail them again.
     */
    public function remind(): int
    {
        if (! app(ModuleRegistry::class)->isActive(self::MODULE)) {
            return 0;
        }

        $today = self::today()->toDateString();
        $claim = fn (string $column, string $where, array $bindings) => collect(DB::select(
            "UPDATE fv_deadlines SET {$column} = now() WHERE is_active AND {$column} IS NULL AND {$where} RETURNING id", $bindings
        ))->pluck('id');
        $dueIds = $claim('due_mailed_at', 'due_on >= ? AND due_on - lead_days <= ?::date', [$today, $today]);
        $overdueIds = $claim('overdue_mailed_at', 'due_on < ?', [$today]);

        $due = Deadline::with('vehicle')->whereIn('id', $dueIds)->orderBy('due_on')->get();
        $overdue = Deadline::with('vehicle')->whereIn('id', $overdueIds)->orderBy('due_on')->get();
        if ($due->isEmpty() && $overdue->isEmpty()) {
            return 0;
        }

        $this->notify($this->managers(), new DeadlineDigestNotification($due, $overdue));

        return $due->count() + $overdue->count();
    }

    // ─── details ─────────────────────────────────────────────────────────────

    /** @return array{name: string, mime: string, size: int, data: string} */
    private function attachment(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $name = mb_substr($file->getClientOriginalName() ?: 'Anhang', 0, 200);
        if ($mime === 'application/pdf') {
            if ($file->getSize() > 10 * 1024 * 1024) {
                throw ValidationException::withMessages(['record_files' => '„'.$name.'“ ist größer als 10 MB.']);
            }
            $bytes = (string) file_get_contents($file->getRealPath());
        } elseif (str_starts_with($mime, 'image/')) {
            $bytes = $this->images->toWebp($file->getRealPath(), 2000, 'record_files');
            [$mime, $name] = ['image/webp', preg_replace('/\.[^.]+$/', '', $name).'.webp'];
        } else {
            throw ValidationException::withMessages(['record_files' => '„'.$name.'“: Bitte ein Foto oder eine PDF-Datei hochladen.']);
        }

        return ['name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'data' => base64_encode($bytes)];
    }

    private function date(mixed $value, string $field, array &$errors): ?Carbon
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', (string) $value);
        } catch (Throwable) {
            $errors[$field] = 'Bitte ein gültiges Datum angeben.';

            return null;
        }
    }

    /** "2027-05-10T14:30" from the form, local time → UTC */
    private function time(mixed $value, string $field, array &$errors): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d\TH:i', (string) $value, 'Europe/Berlin')->utc();
        } catch (Throwable) {
            $errors[$field] = 'Bitte Datum und Uhrzeit angeben.';

            return null;
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['fahrzeuge' => 'Das dürfen nur Fahrzeugwarte und Admins.']);
        }
    }

    private function notify(Collection $users, $notification): void
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
