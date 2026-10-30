<?php

namespace Modules\Fahrzeuge\src\Services;

use App\Models\Module;
use App\Models\User;
use App\Services\ImageResizer;
use App\Services\LocationNotifications;
use App\Services\ModuleRegistry;
use App\Support\Money;
use App\Support\SignatureImage;
use Carbon\CarbonInterface;
use Extensions\Locations\Models\Location as Standort;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Models\Location;
use Modules\Einsatzbericht\src\Models\ReportVehicle;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Document;
use Modules\Fahrzeuge\src\Models\Downtime;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Models\Record;
use Modules\Fahrzeuge\src\Models\StatusEvent;
use Modules\Fahrzeuge\src\Notifications\DeadlineDigestNotification;
use Modules\Fahrzeuge\src\Notifications\VehicleUnavailableNotification;
use Modules\Finanzen\src\Services\FinanceService;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Services\DefectService;
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
        return self::canView($user) && ($user->is_admin || (
            $user->hasAnyModuleRole(self::MODULE, 'fahrzeugwart') && self::vehicleLocationScope($user) !== []
        ));
    }

    public static function canViewVehicle(User $user, Vehicle $vehicle): bool
    {
        if (! self::canView($user)) {
            return false;
        }

        $locationIds = self::vehicleLocationScope($user);

        return $locationIds === null || in_array((int) $vehicle->getAttribute('ext_location_id'), $locationIds, true);
    }

    public static function canManageVehicle(User $user, Vehicle $vehicle): bool
    {
        return self::canViewVehicle($user, $vehicle)
            && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'fahrzeugwart', $vehicle->getAttribute('ext_location_id') ? (int) $vehicle->getAttribute('ext_location_id') : null));
    }

    /** @return Builder<Vehicle> */
    public static function vehiclesFor(User $user): Builder
    {
        $locationIds = self::vehicleLocationScope($user);

        return Vehicle::query()->when($locationIds !== null, fn (Builder $query) => $query->whereIn('ext_location_id', $locationIds));
    }

    /** @return list<int>|null Null means the user has an existing unscoped module grant. */
    public static function vehicleLocationScope(User $user): ?array
    {
        if ($user->is_admin
            || $user->hasModuleRole(self::MODULE, 'fahrzeugwart')
            || $user->moduleAccess()->where('module', self::MODULE)->exists()
            || Module::where('key', self::MODULE)->where('auto_assign', true)->exists()) {
            return null;
        }

        if (! config('locations.enabled', false)) {
            return [];
        }

        return $user->standardRoleLocationIds(self::MODULE, ['fahrzeugwart']);
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
        $this->assertManager($by, $vehicle);
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
        $purchasedOn = $this->date($data['purchased_on'] ?? null, 'purchased_on', $errors);
        $warrantyUntil = $this->date($data['warranty_until'] ?? null, 'warranty_until', $errors);
        $price = filled($data['purchase_price'] ?? null) ? Money::parse((string) $data['purchase_price']) : null;
        if (filled($data['purchase_price'] ?? null) && ($price === null || $price < 0)) {
            $errors['purchase_price'] = 'Bitte den Kaufpreis als Betrag angeben, z. B. 385.000,00.';
        }
        $replacementYear = filled($data['replacement_year'] ?? null) ? (int) $data['replacement_year'] : null;
        if ($replacementYear !== null && ($replacementYear < 1990 || $replacementYear > (int) now()->year + 50)) {
            $errors['replacement_year'] = 'Bitte ein gültiges Jahr angeben.';
        }
        $replacementCost = filled($data['replacement_cost'] ?? null) ? Money::parse((string) $data['replacement_cost']) : null;
        if (filled($data['replacement_cost'] ?? null) && ($replacementCost === null || $replacementCost < 0)) {
            $errors['replacement_cost'] = 'Bitte die geschätzten Kosten als Betrag angeben.';
        }
        $locationId = filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null;
        if ($locationId && ! Location::whereKey($locationId)->exists()) {
            $errors['location_id'] = 'Diesen Standort gibt es nicht.';
        }
        $standortId = filled($data['ext_location_id'] ?? null)
            ? (int) $data['ext_location_id']
            : ($vehicle?->getAttribute('ext_location_id') ? (int) $vehicle->getAttribute('ext_location_id') : null);
        if (config('locations.enabled', false)) {
            $visibleLocationIds = app(LocationAccess::class)->visibleLocationIds($by);
            $standortId ??= app(LocationContext::class)->forUser($by)['current'];
            $roleLocationIds = self::vehicleLocationScope($by);
            if ($roleLocationIds !== null && ! in_array($standortId, $roleLocationIds, true) && ! filled($data['ext_location_id'] ?? null)) {
                $standortId = $roleLocationIds[0] ?? null;
            }
            if (! $standortId || ! in_array($standortId, $visibleLocationIds, true) || ! Standort::query()->whereKey($standortId)->exists()) {
                $errors['ext_location_id'] = 'Bitte einen Standort auswählen, auf den du Zugriff hast.';
            } elseif (! self::canManageLocation($by, $standortId)) {
                $errors['ext_location_id'] = 'Du darfst Fahrzeuge nur an deinen zugewiesenen Standorten verwalten.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $photoData = $photo ? base64_encode($this->images->toWebp($photo->getRealPath(), 1600, 'photo')) : null;

        return DB::transaction(function () use ($vehicle, $data, $name, $color, $category, $numbers, $hours, $firstRegistration, $locationId, $standortId, $photoData, $purchasedOn, $warrantyUntil, $price, $replacementYear, $replacementCost) {
            $vehicle ??= new Vehicle(['is_active' => true]);
            if ($standortId !== null) {
                $vehicle->forceFill(['ext_location_id' => $standortId]);
            }
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
                'purchased_on' => $purchasedOn?->toDateString(), 'purchase_price_cents' => $price, 'supplier' => $this->text($data['supplier'] ?? null, 150),
                'funding' => $this->text($data['funding'] ?? null, 150), 'warranty_until' => $warrantyUntil?->toDateString(),
                'replacement_year' => $replacementYear, 'replacement_cost_cents' => $replacementCost,
                ...($photoData ? ['photo' => $photoData, 'photo_updated_at' => now()] : []),
            ])->save();

            return $vehicle;
        });
    }

    /** Delete only unused vehicles; booking and trip records are retained as history. */
    public function deleteVehicle(User $by, Vehicle $vehicle): void
    {
        $this->assertManager($by, $vehicle);

        try {
            DB::transaction(function () use ($by, $vehicle) {
                $locked = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
                $this->assertManager($by, $locked);
                $hasTrips = Schema::hasTable('ft_trips') && DB::table('ft_trips')->where('vehicle_id', $locked->getKey())->exists();
                $hasFuelings = Schema::hasTable('ft_fuelings') && DB::table('ft_fuelings')->where('vehicle_id', $locked->getKey())->exists();

                if ($locked->bookings()->exists() || $hasTrips || $hasFuelings) {
                    throw ValidationException::withMessages([
                        'fahrzeuge' => 'Das Fahrzeug hat bereits Buchungen oder Fahrtenbucheinträge und kann deshalb nicht gelöscht werden. Du kannst es stattdessen außer Dienst stellen.',
                    ]);
                }

                $locked->delete();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23503') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'fahrzeuge' => 'Das Fahrzeug wird bereits in anderen Datensätzen verwendet und kann deshalb nicht gelöscht werden. Du kannst es stattdessen außer Dienst stellen.',
            ]);
        }
    }

    public function removePhoto(User $by, Vehicle $vehicle): void
    {
        $this->assertManager($by, $vehicle);
        Profile::where('vehicle_id', $vehicle->id)->update(['photo' => null, 'photo_updated_at' => null]);
    }

    // ─── status and downtimes ────────────────────────────────────────────────

    public function setStatus(User $by, Vehicle $vehicle, string $status, ?string $note): void
    {
        $this->assertManager($by, $vehicle);
        if (! array_key_exists($status, Profile::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Bitte einen Status wählen.']);
        }
        $note = $this->text($note, 300);
        if ($status !== 'ready' && $note === null) {
            throw ValidationException::withMessages(['status_note' => 'Bitte kurz angeben, was los ist.']);
        }

        $this->changeStatus($by, $vehicle, $status, $note, null);
    }

    /** $source 'inspection' marks a status set by a Prüfung, so a passed Nachprüfung may lift it again */
    private function changeStatus(User $by, Vehicle $vehicle, string $status, ?string $note, ?string $source): void
    {
        $profile = $this->profile($vehicle);
        $profile->fill(['status' => $status, 'status_note' => $status === 'ready' ? null : $note, 'status_changed_at' => now(),
            'status_source' => $status === 'ready' ? null : $source])->save();
        StatusEvent::create(['vehicle_id' => $vehicle->id, 'status' => $status, 'note' => $note, 'user_id' => $by->id]);
    }

    /** Außer Dienst: no longer bookable, not offered in the Einsatzbericht; the history stays */
    public function decommission(User $by, Vehicle $vehicle, ?string $note): void
    {
        $this->assertManager($by, $vehicle);
        $vehicle->update(['is_active' => false]);
        StatusEvent::create(['vehicle_id' => $vehicle->id, 'status' => 'decommissioned', 'note' => $this->text($note, 300), 'user_id' => $by->id]);
    }

    public function recommission(User $by, Vehicle $vehicle): void
    {
        $this->assertManager($by, $vehicle);
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
        $this->assertManager($by, $vehicle);
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
        $vehicle = $this->targetVehicle((int) $downtime->vehicle_id);
        $this->assertManager($by, $vehicle);
        if ($downtime->starts_at->isFuture()) {
            $downtime->delete();

            return;
        }
        if ($downtime->ends_at === null || $downtime->ends_at->isFuture()) {
            $downtime->update(['ends_at' => now()]);
        }

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

    /** @param  array{title: string, interval_months?: ?int, due_on: string, lead_days?: ?int, notes?: ?string, steps?: string|list<string>}  $data */
    public function saveDeadline(User $by, Vehicle $vehicle, ?Deadline $deadline, array $data): Deadline
    {
        $this->assertManager($by, $vehicle);
        if ($deadline && (int) $deadline->vehicle_id !== (int) $vehicle->id) {
            throw ValidationException::withMessages(['deadline' => 'Die Frist gehört nicht zu diesem Fahrzeug.']);
        }
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

        $steps = collect(is_array($data['steps'] ?? null) ? $data['steps'] : preg_split('/\R/', (string) ($data['steps'] ?? '')))
            ->map(fn ($s) => $this->text($s, 150))->filter()->unique()->take(40)->values()->all();

        $deadline ??= new Deadline(['vehicle_id' => $vehicle->id]);
        $dateChanged = ! $deadline->exists || $deadline->due_on?->toDateString() !== $due->toDateString();
        $deadline->fill(['title' => $title, 'interval_months' => $interval, 'due_on' => $due->toDateString(), 'lead_days' => $lead,
            'notes' => $this->text($data['notes'] ?? null, 500), 'is_active' => true, 'steps' => $steps ?: null,
            ...($dateChanged ? ['due_mailed_at' => null, 'overdue_mailed_at' => null] : [])])->save();
        $this->syncCalendar($deadline);

        return $deadline;
    }

    public function deleteDeadline(User $by, Deadline $deadline): void
    {
        $this->assertManager($by, $this->targetVehicle((int) $deadline->vehicle_id));
        $deadline->records()->exists() ? $deadline->update(['is_active' => false]) : $deadline->delete();
        $this->syncCalendar($deadline);
    }

    /**
     * The deadline as an all-day entry in the Kalender, for the Fahrzeugwarte only (managed_by =
     * fahrzeuge). Rebuilt on every change; a Kalender error never stops the Fahrzeugverwaltung.
     */
    public function syncCalendar(Deadline $deadline): void
    {
        if (! app(ModuleRegistry::class)->isActive('kalender')) {
            return;
        }
        try {
            if ($deadline->event_id) {
                Event::where('managed_by', self::MODULE)->whereKey($deadline->event_id)->delete();
            }
            $eventId = null;
            if ($deadline->exists && $deadline->is_active && $deadline->due_on->gte(self::today())) {
                $start = Carbon::createFromFormat('!Y-m-d', $deadline->due_on->toDateString(), 'Europe/Berlin');
                $vehicle = $deadline->vehicle()->first();
                $eventId = app(EventService::class)->create(['title' => ($vehicle?->name ?? 'Fahrzeug').': '.$deadline->title, 'type' => 'wartung', 'all_day' => true,
                    'starts_at' => $start, 'ends_at' => $start->copy()->addDay(), 'managed_by' => self::MODULE,
                    'description' => 'Frist aus der Fahrzeugverwaltung – erledigt wird sie im Fahrzeugprofil.'], [], null, null, false, $this->managers()->pluck('id')->all(),
                    locationIds: $vehicle?->ext_location_id ? [(int) $vehicle->ext_location_id] : [])
                    ->first()?->id;
            }
            if ($deadline->exists && $deadline->event_id !== $eventId) {
                $deadline->forceFill(['event_id' => $eventId])->saveQuietly();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Document a Prüfung, Wartung or Reparatur. Done for a deadline, that deadline moves on by its
     * interval (or is closed without one) – unless the Prüfung failed: then it stays open for the
     * Nachprüfung. A higher Kilometerstand goes into the profile. A Prüfung with a result sets the
     * Einsatzbereitschaft, and each Prüfschritt with a Mangel becomes a defect in the
     * Mängelmanagement (when it is on).
     *
     * @param  array{deadline_id?: ?int, kind: string, title?: ?string, done_on: string, mileage_km?: ?int, cost_cents?: ?int, provider?: ?string, notes?: ?string, invoice_number?: ?string, result?: ?string, checklist?: list<array{step: string, state: string, note?: ?string}>, signature?: ?string, signer_name?: ?string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function recordWork(User $by, Vehicle $vehicle, array $data, array $files = []): Record
    {
        $this->assertManager($by, $vehicle);
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
        $checklist = collect($data['checklist'] ?? [])->map(fn ($s) => ['step' => (string) $this->text($s['step'] ?? null, 150),
            'state' => (string) ($s['state'] ?? ''), 'note' => $this->text($s['note'] ?? null, 300)])->filter(fn ($s) => $s['step'] !== '')->values();
        if ($checklist->contains(fn ($s) => ! array_key_exists($s['state'], Record::STEP_STATES))) {
            $errors['record_checklist'] = 'Bitte jeden Prüfschritt bewerten: in Ordnung, Mangel oder entfällt.';
        }
        $defects = $checklist->where('state', 'defect');
        $result = filled($data['result'] ?? null) ? (string) $data['result'] : ($checklist->isNotEmpty() ? ($defects->isEmpty() ? 'passed' : 'limited') : null);
        if ($result !== null && ! array_key_exists($result, Record::RESULTS)) {
            $errors['record_result'] = 'Bitte das Ergebnis wählen.';
        } elseif ($result === 'passed' && $defects->isNotEmpty()) {
            $errors['record_result'] = 'Bei Mängeln bitte „bedingt“ oder „nicht einsatzbereit“ wählen.';
        }
        if ($result !== null && $kind !== 'inspection') {
            $result = null;
        }
        $signature = null;
        if (filled($data['signature'] ?? null)) {
            try {
                $signature = SignatureImage::fromDataUrl((string) $data['signature'], 'record_signature');
            } catch (ValidationException $e) {
                $errors += array_map(fn ($m) => $m[0], $e->errors());
            }
            if ($this->text($data['signer_name'] ?? null, 150) === null) {
                $errors['record_signer'] = 'Bitte angeben, wer unterschreibt.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $attachments = array_map(fn (UploadedFile $file) => $this->attachment($file), $files);

        $record = DB::transaction(function () use ($by, $vehicle, $deadline, $kind, $title, $done, $mileage, $data, $attachments, $checklist, $result, $signature) {
            $record = Record::create([
                'vehicle_id' => $vehicle->id, 'deadline_id' => $deadline?->id, 'kind' => $kind, 'title' => $title, 'done_on' => $done->toDateString(),
                'mileage_km' => $mileage, 'cost_cents' => $data['cost_cents'] ?? null, 'provider' => $this->text($data['provider'] ?? null, 150),
                'notes' => $this->text($data['notes'] ?? null, 5000), 'created_by' => $by->id,
                'invoice_number' => $this->text($data['invoice_number'] ?? null, 60), 'result' => $result,
                'checklist' => $checklist->isNotEmpty() ? $checklist->all() : null, 'signature' => $signature,
                'signer_name' => $signature ? $this->text($data['signer_name'] ?? null, 150) : null, 'signed_at' => $signature ? now() : null,
            ]);
            foreach ($attachments as $file) {
                $record->attachments()->create($file);
            }

            if ($deadline && $result !== 'failed') {
                $deadline->update($deadline->interval_months
                    ? ['due_on' => $done->copy()->addMonths($deadline->interval_months)->toDateString(), 'due_mailed_at' => null, 'overdue_mailed_at' => null]
                    : ['is_active' => false]);
            }

            $profile = $this->profile($vehicle);
            if ($mileage !== null && $mileage > (int) $profile->mileage_km) {
                $profile->fill(['mileage_km' => $mileage, 'mileage_on' => $done->toDateString()])->save();
            }

            $this->applyResult($by, $vehicle, $record);

            return $record;
        });

        if ($deadline) {
            $this->syncCalendar($deadline->refresh());
        }
        $this->reportDefects($by, $vehicle, $record);

        return $record;
    }

    /**
     * The Prüfung sets the Einsatzbereitschaft: failed → nicht einsatzbereit, with Mängeln →
     * bedingt (never better than it already is), passed → lifts a status a Prüfung had set.
     */
    private function applyResult(User $by, Vehicle $vehicle, Record $record): void
    {
        if ($record->result === null || ! $vehicle->is_active) {
            return;
        }
        $profile = $this->profile($vehicle);
        $note = 'Prüfung „'.$record->title.'“ vom '.$record->done_on->format('d.m.Y').': '.match ($record->result) {
            'failed' => 'nicht bestanden', 'limited' => count($record->defects()).' '.(count($record->defects()) === 1 ? 'Mangel' : 'Mängel'), default => 'bestanden',
        };
        match ($record->result) {
            'failed' => $this->changeStatus($by, $vehicle, 'unavailable', $note, 'inspection'),
            'limited' => $profile->status === 'unavailable' && $profile->status_source !== 'inspection' ? null
                : $this->changeStatus($by, $vehicle, 'limited', $note, 'inspection'),
            default => $profile->status !== 'ready' && $profile->status_source === 'inspection'
                ? $this->changeStatus($by, $vehicle, 'ready', $note, null) : null,
        };
    }

    /** Each Prüfschritt with a Mangel becomes a defect in the Mängelmanagement, tied to the vehicle */
    private function reportDefects(User $by, Vehicle $vehicle, Record $record): void
    {
        if (! $record->defects() || ! app(ModuleRegistry::class)->isActive('maengelmanagement')) {
            return;
        }
        foreach ($record->defects() as $step) {
            try {
                app(DefectService::class)->report($by, [
                    'vehicle_id' => $vehicle->id, 'title' => mb_substr($step['step'], 0, 150),
                    'description' => trim('Bei der Prüfung „'.$record->title.'“ am '.$record->done_on->format('d.m.Y').' festgestellt.'.($step['note'] ? "\n".$step['note'] : '')),
                    'urgency' => $record->result === 'failed' ? 'high' : 'medium',
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Open defects (Mängelmanagement) of the vehicles: count by vehicle id */
    public function openDefects(array $vehicleIds): Collection
    {
        if (! app(ModuleRegistry::class)->isActive('maengelmanagement') || ! $vehicleIds) {
            return collect();
        }

        return Defect::active()->whereIn('vehicle_id', $vehicleIds)->selectRaw('vehicle_id, count(*) as n')->groupBy('vehicle_id')->pluck('n', 'vehicle_id');
    }

    /**
     * Hand the costs of a documented Wartung or Reparatur to the Finanzen as an Ausgabe (submitted,
     * the Kassenwart decides), with the same Belege. Returns the Finanzen entry id.
     *
     * @param  list<UploadedFile>  $files
     */
    public function submitToFinance(User $by, Record $record, ?int $costCenterId, array $files = []): int
    {
        $vehicle = $this->targetVehicle((int) $record->vehicle_id);
        $this->assertManager($by, $vehicle);
        if (! app(ModuleRegistry::class)->isActive('finanzen') || ! $by->canUseModule('finanzen')) {
            throw ValidationException::withMessages(['record_finance' => 'Die Finanzen sind nicht eingeschaltet.']);
        }
        if (! $record->cost_cents) {
            throw ValidationException::withMessages(['record_finance' => 'Ohne Kosten gibt es nichts abzurechnen.']);
        }
        try {
            $entry = app(FinanceService::class)->create($by, [
                'cost_center_id' => $costCenterId, 'title' => $vehicle->name.': '.$record->title, 'vendor' => $record->provider,
                'amount_cents' => $record->cost_cents, 'booked_on' => $record->done_on->toDateString(),
                'description' => trim(($record->invoice_number ? 'Rechnung '.$record->invoice_number.'. ' : '').'Aus der Fahrzeugverwaltung ('.$record->kindLabel().').'),
            ], $files);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['record_finance' => collect($e->errors())->flatten()->first()]);
        }
        $record->update(['finance_entry_id' => $entry->id]);

        return $entry->id;
    }

    // ─── Fahrzeugakte: documents ─────────────────────────────────────────────

    /** @param  array{kind: string, title?: ?string, valid_until?: ?string, internal?: bool}  $data */
    public function addDocument(User $by, Vehicle $vehicle, array $data, UploadedFile $file): Document
    {
        $this->assertManager($by, $vehicle);
        $errors = [];
        $kind = (string) ($data['kind'] ?? '');
        if (! array_key_exists($kind, Document::KINDS)) {
            $errors['document_kind'] = 'Bitte die Art wählen.';
        }
        $validUntil = $this->date($data['valid_until'] ?? null, 'document_valid_until', $errors);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        try {
            $stored = $this->attachment($file);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['document_file' => collect($e->errors())->flatten()->first()]);
        }

        return Document::create([...$stored, 'vehicle_id' => $vehicle->id, 'kind' => $kind,
            'title' => $this->text($data['title'] ?? null, 150) ?? Document::KINDS[$kind], 'valid_until' => $validUntil?->toDateString(),
            'internal' => (bool) ($data['internal'] ?? false), 'uploaded_by' => $by->id]);
    }

    public function deleteDocument(User $by, Document $document): void
    {
        $this->assertManager($by, $this->targetVehicle((int) $document->vehicle_id));
        $document->delete();
    }

    public static function canSeeDocument(User $user, Document $document): bool
    {
        $vehicle = Vehicle::withoutGlobalScopes()->find($document->vehicle_id);
        if (! $vehicle) {
            return false;
        }

        return self::canViewVehicle($user, $vehicle)
            && (! $document->internal || self::canManageVehicle($user, $vehicle));
    }

    /**
     * All active deadlines of the active vehicles for the Fristenliste.
     *
     * @param  string  $filter  overdue | upcoming (next 90 days) | all
     */
    public function deadlines(string $filter = 'upcoming', ?int $vehicleId = null, ?User $user = null): EloquentCollection
    {
        $today = self::today();
        $locationIds = $user ? self::vehicleLocationScope($user) : null;

        return Deadline::active()->with('vehicle')->whereHas('vehicle', fn ($q) => $q->where('is_active', true))
            ->when($locationIds !== null, fn ($q) => $q->whereHas('vehicle', fn ($vehicle) => $vehicle->whereIn('ext_location_id', $locationIds)))
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->when($filter === 'overdue', fn ($q) => $q->where('due_on', '<', $today->toDateString()))
            ->when($filter === 'upcoming', fn ($q) => $q->where('due_on', '<=', $today->copy()->addDays(90)->toDateString()))
            ->orderBy('due_on')->get();
    }

    /** @return array{year: int, total: int, by_kind: array<string, int>} costs of the documented work in a year */
    public function costs(Vehicle $vehicle, int $year): array
    {
        $rows = Record::where('vehicle_id', $vehicle->id)->whereYear('done_on', $year)->whereNotNull('cost_cents')
            ->selectRaw('kind, sum(cost_cents) as c')->groupBy('kind')->pluck('c', 'kind')->map(fn ($c) => (int) $c);

        return ['year' => $year, 'total' => (int) $rows->sum(), 'by_kind' => $rows->all()];
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
        $einsatz = $this->runningAssignments($ids)->keyBy('vehicle_id');
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
     * @return Collection<int, array{vehicle_id: ?int, name: string, report_id: int, number: string, keyword: ?string, departed_at: ?Carbon, crew: int<0, max>}>
     */
    public function runningAssignments(?array $vehicleIds = null): Collection
    {
        if (! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return collect();
        }

        return ReportVehicle::with('report')->withCount('crew')->where('is_standby', false)->whereNull('returned_at')
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
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

        // Deadlines from before the Kalender was switched on get their entry
        if (app(ModuleRegistry::class)->isActive('kalender')) {
            Deadline::active()->whereNull('event_id')->where('due_on', '>=', self::today()->toDateString())->get()->each(fn (Deadline $d) => $this->syncCalendar($d));
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

        $managers = $this->managers();
        app(LocationNotifications::class)->digest(
            $due->toBase()->concat($overdue),
            fn (Deadline $deadline) => $deadline->vehicle->getAttribute('ext_location_id'),
            fn (Collection $entries) => new DeadlineDigestNotification($entries->whereIn('id', $dueIds)->values(), $entries->whereIn('id', $overdueIds)->values()),
            fn () => $managers,
        );

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

    private static function canManageLocation(User $user, int $locationId): bool
    {
        return self::canManage($user) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'fahrzeugwart', $locationId));
    }

    private function targetVehicle(int $vehicleId): Vehicle
    {
        return Vehicle::withoutGlobalScopes()->findOrFail($vehicleId);
    }

    private function assertManager(User $user, ?Vehicle $vehicle = null): void
    {
        if (! self::canManage($user) || ($vehicle && ! self::canManageVehicle($user, Vehicle::withoutGlobalScopes()->find($vehicle->id) ?? $vehicle))) {
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
