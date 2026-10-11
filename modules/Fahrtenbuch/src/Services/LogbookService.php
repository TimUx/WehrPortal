<?php

namespace Modules\Fahrtenbuch\src\Services;

use App\Models\User;
use App\Services\ImageResizer;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\ReportVehicle;
use Modules\Fahrtenbuch\src\Models\Fueling;
use Modules\Fahrtenbuch\src\Models\Revision;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrtenbuch\src\Notifications\OpenTripNotification;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Profile;
use Throwable;

/**
 * All changes of the Fahrtenbuch go through here. The vehicles are the shared list of the
 * Fahrzeugbuchung and the Fahrzeugverwaltung; a trip starts (vehicle, driver, Kilometerstand,
 * start, purpose) and ends (Kilometerstand, destination), or is entered complete afterwards.
 * The Kilometerstand never goes backwards, a finished trip is only corrected with a reason
 * (revision) and never deleted. Ending a trip or a fuel stop moves the Kilometerstand of the
 * Fahrzeugverwaltung along.
 */
class LogbookService
{
    public const MODULE = 'fahrtenbuch';

    /** More than this in one trip is a typing error */
    public const MAX_TRIP_KM = 3000;

    /** Open longer than this: the driver is reminded to end it */
    public const REMIND_HOURS = 12;

    public function __construct(private readonly ImageResizer $images) {}

    // ─── who may do what ─────────────────────────────────────────────────────

    /** Everyone with access drives, records trips and fuel stops and reads the Fahrtenbuch */
    public static function canView(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE);
    }

    /** Fahrtenbuchwart and admins: corrections, cancellations, statistics, export */
    public static function canManage(User $user): bool
    {
        return self::canView($user) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'fahrtenbuchwart'));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'fahrtenbuchwart'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    // ─── Kilometerstand and suggestions ──────────────────────────────────────

    /** The highest known Kilometerstand: trips, fuel stops and the Fahrzeugverwaltung */
    public function lastKm(Vehicle $vehicle, ?int $exceptTrip = null): ?int
    {
        $trips = Trip::valid()->where('vehicle_id', $vehicle->id)->when($exceptTrip, fn ($q) => $q->whereKeyNot($exceptTrip))
            ->selectRaw('max(greatest(start_km, coalesce(end_km, 0))) as km')->value('km');
        $fuel = Fueling::where('vehicle_id', $vehicle->id)->max('km');
        $profile = Profile::where('vehicle_id', $vehicle->id)->value('mileage_km');
        $known = array_filter([$trips, $fuel, $profile], fn ($v) => $v !== null);

        return $known ? (int) max($known) : null;
    }

    /**
     * What the start form can fill in by itself ("automatische Fahrzeugzuordnung"): the vehicle of
     * the Kamerad's running booking, the running Einsatz this vehicle is out at, the last Kilometerstand.
     *
     * @return array{vehicle_id: ?int, booking: ?Booking, report: ?Report, start_km: ?int}
     */
    public function suggest(User $driver, ?Vehicle $vehicle): array
    {
        $modules = app(ModuleRegistry::class);
        $booking = $modules->isActive('fahrzeugbuchung')
            ? Booking::active()->where('user_id', $driver->id)->when($vehicle, fn ($q) => $q->where('vehicle_id', $vehicle->id))
                ->where('starts_at', '<=', now()->addMinutes(30))->where('ends_at', '>', now())->orderBy('starts_at')->first()
            : null;
        $vehicle ??= $booking?->vehicle;

        $report = null;
        if ($vehicle && $modules->isActive('einsatzbericht')) {
            $report = ReportVehicle::where('vehicle_id', $vehicle->id)->whereHas('report', fn ($q) => $q->where('status', 'active')->whereNull('ended_at'))
                ->latest('id')->first()?->report;
        }

        return ['vehicle_id' => $vehicle?->id, 'booking' => $booking, 'report' => $report, 'start_km' => $vehicle ? $this->lastKm($vehicle) : null];
    }

    /** Einsätze a trip can belong to: open (Entwurf, aktuell) and of the last 14 days */
    public function reportOptions(): Collection
    {
        if (! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return collect();
        }

        return Report::where(fn ($q) => $q->whereIn('status', Report::OPEN)->orWhere('alarmed_at', '>=', now()->subDays(14)))
            ->latest('alarmed_at')->limit(30)->get(['id', 'number', 'keyword', 'alarmed_at']);
    }

    // ─── trips ───────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data  vehicle_id, driver_id, started_at, start_km, origin, purpose, purpose_note, report_id, passengers, notes */
    public function start(User $by, array $data): Trip
    {
        $this->assertView($by);
        $clean = $this->validateStart($data);

        try {
            $trip = DB::transaction(function () use ($by, $clean) {
                // The Kilometerstand is checked against the last trip under lock, so two starts can't cross
                DB::select('select pg_advisory_xact_lock(?)', [crc32('ft_vehicle_'.$clean['vehicle']->id)]);
                $this->assertKm($clean['vehicle'], $clean['start_km'], null);

                return Trip::create([...collect($clean)->except('vehicle')->all(), 'vehicle_id' => $clean['vehicle']->id, 'created_by' => $by->id]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['vehicle_id' => 'Dieses Fahrzeug ist schon unterwegs. Erst die offene Fahrt beenden.']);
            }
            throw $e;
        }

        return $trip;
    }

    /** @param  array<string, mixed>  $data  ended_at, end_km, destination, notes */
    public function end(User $by, Trip $trip, array $data): Trip
    {
        $this->assertView($by);
        if (! $trip->isOpen()) {
            throw ValidationException::withMessages(['trip' => 'Diese Fahrt ist schon beendet.']);
        }
        $clean = $this->validateEnd($data, $trip->started_at, $trip->start_km);
        $trip->update([...$clean, 'notes' => $this->text($data['notes'] ?? null, 2000) ?? $trip->notes]);
        $this->syncMileage($trip->vehicle, $clean['end_km']);

        return $trip;
    }

    /** A trip entered complete afterwards (start and end at once) */
    public function record(User $by, array $data): Trip
    {
        $this->assertView($by);
        $start = $this->validateStart($data, allowPast: true);
        $end = $this->validateEnd($data, $start['started_at'], $start['start_km']);

        $trip = DB::transaction(function () use ($by, $start, $end) {
            DB::select('select pg_advisory_xact_lock(?)', [crc32('ft_vehicle_'.$start['vehicle']->id)]);
            $this->assertNoOverlap($start['vehicle'], $start['started_at'], $end['ended_at'], null);

            return Trip::create([...collect($start)->except('vehicle')->all(), ...$end, 'vehicle_id' => $start['vehicle']->id, 'created_by' => $by->id]);
        });
        $this->syncMileage($trip->vehicle, $trip->end_km);

        return $trip;
    }

    /** The Fahrtenbuchwart corrects a trip; the old values stay as revision with the reason */
    public function correct(User $by, Trip $trip, array $data, string $reason): Trip
    {
        $this->assertManager($by);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Bitte den Grund der Korrektur angeben.']);
        }

        $start = $this->validateStart($data, allowPast: true, fixed: $trip->vehicle);
        $end = $trip->isOpen() && blank($data['end_km'] ?? null) ? [] : $this->validateEnd($data, $start['started_at'], $start['start_km']);
        $after = [...collect($start)->except('vehicle')->all(), ...$end];
        $fields = array_keys($after);
        $before = collect($trip->only($fields))->map(fn ($v) => $v instanceof Carbon ? $v->toIso8601String() : $v)->all();

        DB::transaction(function () use ($by, $trip, $after, $before, $reason) {
            $trip->update($after);
            Revision::create(['trip_id' => $trip->id, 'user_id' => $by->id, 'reason' => mb_substr($reason, 0, 300), 'before' => $before,
                'after' => collect($after)->map(fn ($v) => $v instanceof Carbon ? $v->toIso8601String() : $v)->all()]);
        });
        if ($trip->end_km) {
            $this->syncMileage($trip->vehicle, $trip->end_km);
        }

        return $trip;
    }

    /** A wrong trip (e.g. twice) is cancelled with a reason, never deleted */
    public function void(User $by, Trip $trip, string $reason): void
    {
        $this->assertManager($by);
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Bitte den Grund der Stornierung angeben.']);
        }
        $trip->update(['voided_at' => now(), 'void_reason' => mb_substr(trim($reason), 0, 300)]);
    }

    // ─── fuel ────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data  vehicle_id, fueled_at, km, liters, cost_cents, fuel, full_tank, station, notes */
    public function addFueling(User $by, array $data, ?UploadedFile $receipt = null): Fueling
    {
        $this->assertView($by);
        $errors = [];
        $vehicle = Vehicle::find((int) ($data['vehicle_id'] ?? 0));
        if (! $vehicle) {
            $errors['vehicle_id'] = 'Bitte das Fahrzeug wählen.';
        }
        $at = $this->time($data['fueled_at'] ?? null) ?? now();
        if ($at->isAfter(now()->addMinutes(5))) {
            $errors['fueled_at'] = 'Der Zeitpunkt liegt in der Zukunft.';
        }
        $liters = (float) str_replace(',', '.', (string) ($data['liters'] ?? ''));
        if ($liters <= 0 || $liters > 2000) {
            $errors['liters'] = 'Bitte die getankten Liter angeben.';
        }
        $km = filled($data['km'] ?? null) ? (int) $data['km'] : null;
        if ($km !== null && $vehicle && ($last = $this->lastKm($vehicle)) !== null && $km < $last - 50) {
            $errors['km'] = 'Der Kilometerstand liegt unter dem letzten bekannten ('.number_format($last, 0, ',', '.').' km).';
        }
        $fuel = (string) ($data['fuel'] ?? 'diesel');
        if (! array_key_exists($fuel, Fueling::FUELS)) {
            $errors['fuel'] = 'Bitte die Kraftstoffart wählen.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        [$file, $mime] = $receipt ? $this->receipt($receipt) : [null, null];
        $fueling = Fueling::create([
            'vehicle_id' => $vehicle->id, 'user_id' => $by->id, 'fueled_at' => $at, 'km' => $km, 'liters' => round($liters, 2),
            'cost_cents' => $data['cost_cents'] ?? null, 'fuel' => $fuel, 'full_tank' => (bool) ($data['full_tank'] ?? true),
            'station' => $this->text($data['station'] ?? null, 150), 'notes' => $this->text($data['notes'] ?? null, 500),
            'receipt' => $file, 'receipt_mime' => $mime,
        ]);
        if ($km !== null) {
            $this->syncMileage($vehicle, $km);
        }

        return $fueling;
    }

    /**
     * Consumption from full tank to full tank: the litres of all stops after a full tank up to and
     * including the next full tank, over the kilometres in between. AdBlue doesn't count.
     *
     * @return Collection<int, array{from: Fueling, to: Fueling, km: int, liters: float, per100: float}>
     */
    public function consumption(Vehicle $vehicle, ?int $year = null): Collection
    {
        $stops = Fueling::where('vehicle_id', $vehicle->id)->where('fuel', '!=', 'adblue')->whereNotNull('km')->orderBy('fueled_at')->get();
        $intervals = collect();
        $from = null;
        $liters = 0.0;
        foreach ($stops as $stop) {
            if ($from) {
                $liters += $stop->liters;
            }
            if ($stop->full_tank) {
                if ($from && $stop->km > $from->km) {
                    $km = $stop->km - $from->km;
                    $intervals->push(['from' => $from, 'to' => $stop, 'km' => $km, 'liters' => round($liters, 2), 'per100' => round($liters / $km * 100, 1)]);
                }
                $from = $stop;
                $liters = 0.0;
            }
        }

        return $year ? $intervals->filter(fn ($i) => $i['to']->fueled_at->setTimezone('Europe/Berlin')->year === $year)->values() : $intervals;
    }

    // ─── evaluation and export ───────────────────────────────────────────────

    /** @return Collection<int, array{vehicle: Vehicle, trips: int, km: int, by_purpose: array<string, int>, liters: float, cost: int, per100: ?float}> */
    public function statistics(int $year): Collection
    {
        [$from, $to] = [Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc(), Carbon::create($year + 1, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc()];
        $trips = Trip::valid()->whereNotNull('end_km')->where('started_at', '>=', $from)->where('started_at', '<', $to)->get()->groupBy('vehicle_id');
        $fuel = Fueling::where('fueled_at', '>=', $from)->where('fueled_at', '<', $to)->get()->groupBy('vehicle_id');

        return Vehicle::whereIn('id', $trips->keys()->merge($fuel->keys())->unique())->orderBy('sort_order')->orderBy('name')->get()
            ->map(function (Vehicle $vehicle) use ($trips, $fuel, $year) {
                $own = $trips[$vehicle->id] ?? collect();
                $stops = $fuel[$vehicle->id] ?? collect();
                $intervals = $this->consumption($vehicle, $year);

                return [
                    'vehicle' => $vehicle, 'trips' => $own->count(), 'km' => (int) $own->sum(fn (Trip $t) => $t->distance()),
                    'by_purpose' => $own->groupBy('purpose')->map(fn ($g) => (int) $g->sum(fn (Trip $t) => $t->distance()))->all(),
                    'liters' => round($stops->where('fuel', '!=', 'adblue')->sum('liters'), 2), 'cost' => (int) $stops->sum('cost_cents'),
                    'per100' => $intervals->sum('km') > 0 ? round($intervals->sum('liters') / $intervals->sum('km') * 100, 1) : null,
                ];
            });
    }

    /** The Fahrtenbuch of a vehicle and year as CSV for Steuer and Versicherung (Excel: semicolon, UTF-8) */
    public function csv(Vehicle $vehicle, int $year): string
    {
        $rows = [['Datum', 'Beginn', 'Ende', 'km Beginn', 'km Ende', 'Strecke km', 'Start', 'Ziel', 'Zweck', 'Anlass', 'Einsatz', 'Fahrer', 'Mitfahrer', 'Bemerkung', 'Storniert']];
        foreach ($this->yearTrips($vehicle, $year, withVoided: true) as $t) {
            $rows[] = [
                $t->started_at->setTimezone('Europe/Berlin')->format('d.m.Y'), $t->started_at->setTimezone('Europe/Berlin')->format('H:i'),
                $t->ended_at?->setTimezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'offen', $t->start_km, $t->end_km ?? '', $t->distance() ?? '',
                $t->origin, $t->destination ?? '', $t->purposeLabel(), $t->purpose_note ?? '', $t->report?->number ?? '', $t->driver->name,
                $t->passengers ?? '', $t->notes ?? '', $t->voided_at ? 'ja: '.$t->void_reason : '',
            ];
        }

        return $this->toCsv($rows);
    }

    public function fuelCsv(Vehicle $vehicle, int $year): string
    {
        $rows = [['Datum', 'km', 'Kraftstoff', 'Liter', 'Kosten EUR', 'Voll getankt', 'Tankstelle', 'Getankt von', 'Bemerkung']];
        foreach (Fueling::with('user')->where('vehicle_id', $vehicle->id)->get()->filter(fn (Fueling $f) => $f->fueled_at->setTimezone('Europe/Berlin')->year === $year)->sortBy('fueled_at') as $f) {
            $rows[] = [$f->fueled_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i'), $f->km ?? '', $f->fuelLabel(), number_format($f->liters, 2, ',', ''),
                $f->cost_cents !== null ? number_format($f->cost_cents / 100, 2, ',', '') : '', $f->full_tank ? 'ja' : 'nein', $f->station ?? '', $f->user?->name ?? '', $f->notes ?? ''];
        }

        return $this->toCsv($rows);
    }

    /** @return EloquentCollection<int, Trip> */
    public function yearTrips(Vehicle $vehicle, int $year, bool $withVoided = false): EloquentCollection
    {
        [$from, $to] = [Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc(), Carbon::create($year + 1, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc()];

        return Trip::with(['driver', 'report'])->where('vehicle_id', $vehicle->id)->when(! $withVoided, fn ($q) => $q->valid())
            ->where('started_at', '>=', $from)->where('started_at', '<', $to)->orderBy('started_at')->orderBy('id')->get();
    }

    /**
     * Gaps in the Kilometerstand between two trips (the vehicle was driven without an entry).
     *
     * @return array<int, int> km missing before the trip with this id
     */
    public static function gaps(Collection $trips): array
    {
        $gaps = [];
        $previous = null;
        foreach ($trips->whereNull('voided_at')->sortBy('started_at') as $trip) {
            if ($previous?->end_km !== null && $trip->start_km > $previous->end_km) {
                $gaps[$trip->id] = $trip->start_km - $previous->end_km;
            }
            $previous = $trip;
        }

        return $gaps;
    }

    /** Daily: drivers whose trip has been open for hours are reminded once to end it */
    public function remind(): int
    {
        if (! app(ModuleRegistry::class)->isActive(self::MODULE)) {
            return 0;
        }

        $ids = collect(DB::select(
            'UPDATE ft_trips SET reminded_at = now() WHERE ended_at IS NULL AND voided_at IS NULL AND reminded_at IS NULL AND started_at < ? RETURNING id',
            [now()->subHours(self::REMIND_HOURS)]
        ))->pluck('id');
        Trip::with(['driver', 'vehicle'])->whereIn('id', $ids)->get()->each(function (Trip $trip) {
            try {
                $trip->driver->notify(new OpenTripNotification($trip));
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $ids->count();
    }

    // ─── details ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateStart(array $data, bool $allowPast = false, ?Vehicle $fixed = null): array
    {
        $errors = [];
        $vehicle = $fixed ?? Vehicle::where('is_active', true)->find((int) ($data['vehicle_id'] ?? 0));
        if (! $vehicle) {
            $errors['vehicle_id'] = 'Bitte ein Fahrzeug wählen.';
        }
        $driver = User::active()->find((int) ($data['driver_id'] ?? 0));
        if (! $driver) {
            $errors['driver_id'] = 'Bitte den Fahrer wählen.';
        }
        $started = $this->time($data['started_at'] ?? null) ?? now()->startOfMinute();
        if (filled($data['started_at'] ?? null) && ! $this->time($data['started_at'])) {
            $errors['started_at'] = 'Bitte Datum und Uhrzeit angeben.';
        } elseif ($started->isAfter(now()->addMinutes(5))) {
            $errors['started_at'] = 'Der Beginn liegt in der Zukunft.';
        } elseif (! $allowPast && $started->isBefore(now()->subDay())) {
            $errors['started_at'] = 'Ältere Fahrten bitte als „Fahrt nachtragen“ erfassen.';
        } elseif ($started->isBefore(now()->subYears(2))) {
            $errors['started_at'] = 'Der Beginn liegt mehr als zwei Jahre zurück.';
        }
        $startKm = filled($data['start_km'] ?? null) && is_numeric($data['start_km']) ? (int) $data['start_km'] : null;
        if ($startKm === null || $startKm < 0 || $startKm > 3000000) {
            $errors['start_km'] = 'Bitte den Kilometerstand beim Losfahren angeben.';
        }
        $origin = $this->text($data['origin'] ?? null, 200);
        if ($origin === null) {
            $errors['origin'] = 'Bitte den Start angeben.';
        }
        $purpose = (string) ($data['purpose'] ?? '');
        if (! array_key_exists($purpose, Trip::PURPOSES)) {
            $errors['purpose'] = 'Bitte den Zweck wählen.';
        }
        $reportId = filled($data['report_id'] ?? null) ? (int) $data['report_id'] : null;
        if ($reportId && ! Report::whereKey($reportId)->exists()) {
            $errors['report_id'] = 'Diesen Einsatz gibt es nicht.';
        }
        $note = $this->text($data['purpose_note'] ?? null, 300);
        if (in_array($purpose, ['dienstfahrt', 'sonstiges'], true) && $note === null) {
            $errors['purpose_note'] = 'Bitte den Anlass angeben (z. B. Besorgung Atemschutzwerkstatt).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // A booking of the driver for this vehicle at that time is linked by itself
        $booking = app(ModuleRegistry::class)->isActive('fahrzeugbuchung')
            ? Booking::active()->where('vehicle_id', $vehicle->id)->where('user_id', $driver->id)
                ->where('starts_at', '<=', $started->copy()->addMinutes(30))->where('ends_at', '>', $started)->value('id')
            : null;

        return [
            'vehicle' => $vehicle, 'driver_id' => $driver->id, 'started_at' => $started, 'start_km' => $startKm, 'origin' => $origin,
            'purpose' => $purpose, 'purpose_note' => $note, 'report_id' => $purpose === 'einsatz' || $purpose === 'uebung' ? $reportId : null,
            'booking_id' => $booking, 'passengers' => filled($data['passengers'] ?? null) ? max(0, min(99, (int) $data['passengers'])) : null,
            'notes' => $this->text($data['notes'] ?? null, 2000),
        ];
    }

    /** @return array{ended_at: Carbon, end_km: int, destination: string} */
    private function validateEnd(array $data, Carbon $startedAt, int $startKm): array
    {
        $errors = [];
        $ended = $this->time($data['ended_at'] ?? null) ?? now()->startOfMinute();
        if (filled($data['ended_at'] ?? null) && ! $this->time($data['ended_at'])) {
            $errors['ended_at'] = 'Bitte Datum und Uhrzeit angeben.';
        } elseif ($ended->lt($startedAt)) {
            $errors['ended_at'] = 'Das Ende liegt vor dem Beginn.';
        } elseif ($ended->isAfter(now()->addMinutes(5))) {
            $errors['ended_at'] = 'Das Ende liegt in der Zukunft.';
        }
        $endKm = filled($data['end_km'] ?? null) && is_numeric($data['end_km']) ? (int) $data['end_km'] : null;
        if ($endKm === null) {
            $errors['end_km'] = 'Bitte den Kilometerstand bei der Rückkehr angeben.';
        } elseif ($endKm < $startKm) {
            $errors['end_km'] = 'Der Kilometerstand ist kleiner als beim Losfahren ('.number_format($startKm, 0, ',', '.').' km).';
        } elseif ($endKm - $startKm > self::MAX_TRIP_KM) {
            $errors['end_km'] = 'Mehr als '.number_format(self::MAX_TRIP_KM, 0, ',', '.').' km in einer Fahrt – bitte prüfen.';
        }
        $destination = $this->text($data['destination'] ?? null, 200);
        if ($destination === null) {
            $errors['destination'] = 'Bitte das Ziel angeben.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['ended_at' => $ended, 'end_km' => $endKm, 'destination' => $destination];
    }

    /** A new trip can't start below the Kilometerstand the vehicle already had */
    private function assertKm(Vehicle $vehicle, int $startKm, ?int $exceptTrip): void
    {
        $last = Trip::valid()->where('vehicle_id', $vehicle->id)->when($exceptTrip, fn ($q) => $q->whereKeyNot($exceptTrip))->max('end_km');
        if ($last !== null && $startKm < $last) {
            throw ValidationException::withMessages(['start_km' => 'Der Kilometerstand liegt unter dem Ende der letzten Fahrt ('.number_format((int) $last, 0, ',', '.').' km).']);
        }
    }

    private function assertNoOverlap(Vehicle $vehicle, Carbon $from, Carbon $to, ?int $exceptTrip): void
    {
        $overlap = Trip::valid()->where('vehicle_id', $vehicle->id)->when($exceptTrip, fn ($q) => $q->whereKeyNot($exceptTrip))
            ->where('started_at', '<', $to)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))->first();
        if ($overlap) {
            throw ValidationException::withMessages(['started_at' => 'In dieser Zeit ist schon eine Fahrt eingetragen ('.$overlap->started_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i').', '.$overlap->driver->name.').']);
        }
    }

    /** The Fahrzeugverwaltung keeps the Kilometerstand of the vehicle; only upwards */
    private function syncMileage(Vehicle $vehicle, int $km): void
    {
        try {
            $profile = Profile::firstOrNew(['vehicle_id' => $vehicle->id]);
            if ($km > (int) $profile->mileage_km) {
                $profile->fill(['mileage_km' => $km, 'mileage_on' => now('Europe/Berlin')->toDateString()])->save();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array{0: string, 1: string} base64 and mime */
    private function receipt(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        if ($mime === 'application/pdf') {
            return [base64_encode((string) file_get_contents($file->getRealPath())), $mime];
        }
        if (! str_starts_with($mime, 'image/')) {
            throw ValidationException::withMessages(['receipt' => 'Bitte ein Foto oder eine PDF-Datei hochladen.']);
        }

        return [base64_encode($this->images->toWebp($file->getRealPath(), 2000, 'receipt')), 'image/webp'];
    }

    private function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** "2027-05-10T14:30" from the form, local time → UTC */
    private function time(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::createFromFormat('Y-m-d\TH:i', (string) $value, 'Europe/Berlin')->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function assertView(User $user): void
    {
        if (! self::canView($user)) {
            throw ValidationException::withMessages(['trip' => 'Kein Zugang zum Fahrtenbuch.']);
        }
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['trip' => 'Das dürfen nur Fahrtenbuchwarte und Admins.']);
        }
    }
}
