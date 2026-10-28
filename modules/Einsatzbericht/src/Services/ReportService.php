<?php

namespace Modules\Einsatzbericht\src\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\Geocoder;
use App\Support\SignatureImage;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Einsatzbericht\src\Models\CrewMember;
use Modules\Einsatzbericht\src\Models\Material;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\ReportMaterial;
use Modules\Einsatzbericht\src\Models\ReportVehicle;
use Modules\Einsatzbericht\src\Notifications\ReportReminderNotification;
use Modules\Einsatzbericht\src\Notifications\ReportStatusNotification;
use Modules\Einsatzbericht\src\Support\ReportDetails;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Throwable;

/**
 * Everything that changes an Einsatzbericht. Einsatzleiter (and Gruppenführer) write drafts and
 * close them; once closed only the Wehrführung changes a report, also after the release – every
 * change lands with name and time in the admin Logs.
 */
class ReportService
{
    /** Einsatzleiter, Gruppenführer, Wehrführung and admins write reports */
    public static function canLead(User $user): bool
    {
        return $user->is_active && $user->canUseModule('einsatzbericht')
            && ($user->is_admin || $user->hasAnyModuleRole('einsatzbericht', 'einsatzleiter') || $user->hasAnyModuleRole('einsatzbericht', 'wehrführung'));
    }

    /** Wehrführung and admins release, correct and evaluate */
    public static function canRelease(User $user): bool
    {
        return $user->is_active && $user->canUseModule('einsatzbericht')
            && ($user->is_admin || $user->hasModuleRole('einsatzbericht', 'wehrführung'));
    }

    public static function canLeadReport(User $user, Report $report): bool
    {
        if (! self::canLead($user)) {
            return false;
        }
        if ($user->hasModuleRole('einsatzbericht', 'einsatzleiter') || self::canRelease($user)) {
            return true;
        }

        return self::hasLocationLeadership($user, $report);
    }

    public static function canReleaseReport(User $user, Report $report): bool
    {
        return self::canRelease($user) || self::hasLocationLeadership($user, $report);
    }

    private static function hasLocationLeadership(User $user, Report $report): bool
    {
        $locationId = $report->getAttribute('ext_location_id');
        if (! $user->is_active || ! $user->canUseModule('einsatzbericht')
            || ! config('locations.enabled') || ! is_numeric($locationId)
            || ! Schema::hasTable('x_locations') || ! Schema::hasColumn('eb_reports', 'ext_location_id')) {
            return false;
        }

        $locationId = (int) $locationId;

        return Location::query()->active()->whereKey($locationId)->exists()
            && app(LocationAccess::class)->canSee($user, $locationId)
            && $user->hasModuleRole('einsatzbericht', 'wehrführung', $locationId);
    }

    public static function canEdit(User $user, Report $report): bool
    {
        return in_array($report->status, Report::OPEN, true) ? self::canLeadReport($user, $report) : self::canReleaseReport($user, $report);
    }

    /** @return list<string> fields that must be filled before closing */
    public static function required(): array
    {
        $saved = Setting::get('einsatzbericht_required');

        return $saved !== null ? array_values(array_intersect((array) json_decode($saved, true), array_keys(Report::REQUIRABLE))) : Report::DEFAULT_REQUIRED;
    }

    public static function setRequired(array $fields): void
    {
        Setting::set('einsatzbericht_required', json_encode(array_values(array_intersect($fields, array_keys(Report::REQUIRABLE)))));
    }

    /** A new draft with the next running number of the year ("2026/007") */
    public function create(User $by, ?Carbon $alarmedAt = null): Report
    {
        $this->assert(self::canLead($by));

        $attributes = ['commander_id' => $by->id, 'created_by' => $by->id];
        if (config('locations.enabled') && $by->hasAnyModuleRole('einsatzbericht', 'wehrführung')
            && ! $by->hasModuleRole('einsatzbericht', 'wehrführung')
            && Schema::hasColumn('eb_reports', 'ext_location_id')) {
            $roleLocations = $by->standardRoleLocationIds('einsatzbericht', ['wehrführung']);
            $currentLocation = app(LocationContext::class)->forUser($by)['current'];
            $locationId = $currentLocation !== null && in_array($currentLocation, $roleLocations, true)
                ? $currentLocation
                : (count($roleLocations) === 1 ? $roleLocations[0] : null);
            if ($locationId === null) {
                throw ValidationException::withMessages(['report' => 'Bitte wählen Sie einen Standort aus, an dem Sie als Wehrführung eingetragen sind.']);
            }
            $attributes['ext_location_id'] = $locationId;
        }

        return $this->numbered($attributes, $alarmedAt ?? now());
    }

    /** A current (aktuell) Einsatz from an alarm (DIVERA), without anyone logged in: the alarm has happened */
    public function createFromAlarm(array $data, Carbon $alarmedAt): Report
    {
        $attributes = collect($data)->only(['keyword', 'alarm_message', 'address', 'latitude', 'longitude', 'divera_id'])->all();
        if (config('locations.enabled') && Schema::hasColumn('eb_reports', 'ext_location_id')) {
            $attributes['ext_location_id'] = $data['ext_location_id'] ?? null;
        }

        return $this->numbered([...$attributes, 'source' => 'divera', 'status' => 'active'], $alarmedAt);
    }

    /** "Einsatz starten": the draft becomes the current Einsatz (Lagemeldungen, QR for external Wehren) */
    public function start(User $by, Report $report): void
    {
        $this->assert(self::canLeadReport($by, $report) && $report->status === 'draft', 'Nur ein Entwurf lässt sich starten.');
        $report->update(['status' => 'active']);
    }

    private function numbered(array $attributes, Carbon $alarmedAt): Report
    {
        $year = $alarmedAt->copy()->setTimezone('Europe/Berlin')->year;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $alarmedAt, $year) {
                    $last = Report::withoutGlobalScopes()->where('number', 'like', $year.'/%')->lockForUpdate()->pluck('number')
                        ->map(fn ($n) => (int) substr($n, 5))->max() ?? 0;

                    return Report::forceCreate([
                        ...$attributes,
                        'number' => $year.'/'.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT),
                        'alarmed_at' => $alarmedAt->copy()->utc(),
                    ]);
                });
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages(['report' => 'Die Einsatznummer konnte nicht vergeben werden, bitte erneut versuchen.']);
    }

    /** Saves the report fields; times come as Carbon (any time zone) or null */
    public function update(User $by, Report $report, array $data): void
    {
        $this->assert(self::canEdit($by, $report), 'Dieser Bericht ist abgeschlossen; Änderungen nur noch durch die Wehrführung.');

        $data = collect($data)->only(array_diff((new Report)->getFillable(), ['status', 'created_by', 'closed_at', 'closed_by', 'released_at', 'released_by', 'source', 'divera_id',
            'closed_signature', 'released_signature', 'released_hash', 'parent_id', 'reminded_at', 'external_key', 'external_key_hash']))
            ->map(fn ($v, $k) => $k === 'details' ? ($v ?? []) : $v)
            ->map(fn ($v) => $v instanceof Carbon ? $v->copy()->utc() : (is_string($v) ? (trim($v) !== '' ? trim($v) : null) : $v))
            ->all();

        if (array_key_exists('number', $data)) {
            Validator::make(['number' => $data['number']], [
                'number' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{3,}(?:-\d+)?$/', Rule::unique('eb_reports', 'number')->ignore($report->id)],
            ], [
                'number.regex' => 'Die laufende Nummer muss das Format JJJJ/001 haben.',
                'number.unique' => 'Diese laufende Nummer ist bereits vergeben.',
            ])->validate();
        }

        if (isset($data['category']) && ! array_key_exists($data['category'], Report::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => 'Unbekannte Einsatzart.']);
        }

        // Zusatzangaben: only those that belong to the (new) Einsatzart
        if (array_key_exists('details', $data) || array_key_exists('category', $data)) {
            $data['details'] = ReportDetails::clean($data['category'] ?? $report->category, (array) ($data['details'] ?? $report->details ?? [])) ?: null;
        }

        $addressChanged = array_key_exists('address', $data) && $data['address'] !== $report->address;
        try {
            $report->update($data);
        } catch (QueryException $e) {
            if (! array_key_exists('number', $data) || $e->getCode() !== '23505') {
                throw $e;
            }

            throw ValidationException::withMessages(['number' => 'Diese laufende Nummer ist bereits vergeben.']);
        }

        // New address without coordinates: look them up (Google key under Einstellungen → Dienste)
        if ($addressChanged && $report->address && ! array_key_exists('latitude', $data) && ($point = app(Geocoder::class)->locate($report->address))) {
            $report->update(['latitude' => $point['lat'], 'longitude' => $point['lng']]);
        }
    }

    public function addVehicle(User $by, Report $report, ?int $vehicleId): ReportVehicle
    {
        $this->assert(self::canEdit($by, $report));

        if ($vehicleId === null) {
            return $report->vehicles()->where('is_standby', true)->first()
                ?? $report->vehicles()->create(['name' => 'Bereitschaft (Gerätehaus)', 'is_standby' => true, 'sort_order' => 999]);
        }

        $vehicle = Vehicle::findOrFail($vehicleId);

        if ($report->vehicles()->where('vehicle_id', $vehicle->id)->exists()) {
            throw ValidationException::withMessages(['vehicle' => $vehicle->name.' ist schon eingetragen.']);
        }

        return $report->vehicles()->create([
            'vehicle_id' => $vehicle->id,
            'name' => $vehicle->name,
            'sort_order' => (int) $report->vehicles()->max('sort_order') + 1,
            // The vehicle leaves and arrives with the Einsatz unless changed
            'departed_at' => $report->departed_at,
            'arrived_at' => $report->arrived_at,
        ]);
    }

    public function removeVehicle(User $by, ReportVehicle $vehicle): void
    {
        $this->assert(self::canEdit($by, $vehicle->report));
        $vehicle->delete();
    }

    public function setVehicleTimes(User $by, ReportVehicle $vehicle, array $times): void
    {
        $this->assert(self::canEdit($by, $vehicle->report));
        $vehicle->update(collect($times)->only(array_keys(ReportVehicle::TIMES))->map(fn ($v) => $v instanceof Carbon ? $v->copy()->utc() : null)->all());
    }

    /** Puts a Kamerad on a vehicle (or the Bereitschaft); someone already on another one moves over */
    public function assign(User $by, ReportVehicle $vehicle, User $member, string $function = 'einsatzkraft', bool $breathingApparatus = false): CrewMember
    {
        $this->assert(self::canEdit($by, $vehicle->report));

        if (! $this->eligibleCrew($vehicle)->contains('id', $member->getKey())) {
            if (config('locations.enabled')) {
                throw ValidationException::withMessages(['crew' => 'Für dieses Fahrzeug können nur aktive Kameraden der Einsatzabteilung desselben Standorts eingeteilt werden.']);
            }

            throw ValidationException::withMessages(['crew' => 'Es können nur aktive Kameraden der Einsatzabteilung eingeteilt werden.']);
        }

        if (! array_key_exists($function, CrewMember::FUNCTIONS)) {
            throw ValidationException::withMessages(['function' => 'Unbekannte Funktion.']);
        }

        return CrewMember::updateOrCreate(
            ['report_id' => $vehicle->report_id, 'user_id' => $member->id],
            ['report_vehicle_id' => $vehicle->id, 'function' => $function, 'breathing_apparatus' => $breathingApparatus && ! $vehicle->is_standby],
        );
    }

    /** @return EloquentCollection<int, User> */
    public function eligibleCrew(ReportVehicle $vehicle): EloquentCollection
    {
        $locationId = $vehicle->vehicle?->getAttribute('ext_location_id') ?? $vehicle->report?->getAttribute('ext_location_id');
        $people = User::active()
            ->whereHas('groups', function ($groups) use ($locationId) {
                $groups->whereRaw('lower(groups.name) = ?', [mb_strtolower('Einsatzabteilung')]);
                if (config('locations.enabled')) {
                    $groups->where('groups.ext_location_id', $locationId);
                }
            });

        if (config('locations.enabled')) {
            if ($locationId === null) {
                return $people->whereRaw('1 = 0')->get(['id', 'name']);
            }

            $people->where(function ($members) use ($locationId) {
                $members->where('ext_location_id', $locationId)
                    ->orWhereHas('locations', fn ($locations) => $locations->whereKey($locationId));
            });
        }

        return $people->orderBy('name')->get(['id', 'name']);
    }

    public function updateCrew(User $by, CrewMember $crew, string $function, bool $breathingApparatus): void
    {
        $this->assert(self::canEdit($by, $crew->report));

        if (! array_key_exists($function, CrewMember::FUNCTIONS)) {
            throw ValidationException::withMessages(['function' => 'Unbekannte Funktion.']);
        }

        $crew->update(['function' => $function, 'breathing_apparatus' => $breathingApparatus && ! $crew->reportVehicle->is_standby]);
    }

    public function removeCrew(User $by, CrewMember $crew): void
    {
        $this->assert(self::canEdit($by, $crew->report));
        $crew->delete();
    }

    /** Verbrauchsmaterial from the catalog, or typed freely when it is not in there */
    public function addMaterial(User $by, Report $report, ?int $materialId, float $quantity, ?string $name = null, ?string $unit = null, ?string $category = null): ReportMaterial
    {
        $this->assert(self::canEdit($by, $report));

        if ($quantity <= 0 || $quantity > 100000) {
            throw ValidationException::withMessages(['material' => 'Bitte eine Menge größer als 0 angeben.']);
        }

        if ($materialId !== null) {
            $material = Material::findOrFail($materialId);

            return $report->materials()->create(['material_id' => $material->id, 'name' => $material->name, 'category' => $material->category, 'unit' => $material->unit, 'quantity' => $quantity]);
        }

        if (blank($name) || ! array_key_exists((string) $unit, Material::UNITS) || ! array_key_exists((string) $category, Material::CATEGORIES)) {
            throw ValidationException::withMessages(['material' => 'Für freies Material bitte Bezeichnung, Einheit und Kategorie angeben.']);
        }

        return $report->materials()->create(['name' => mb_substr(trim($name), 0, 150), 'category' => $category, 'unit' => $unit, 'quantity' => $quantity]);
    }

    public function updateMaterial(User $by, ReportMaterial $item, float $quantity): void
    {
        $this->assert(self::canEdit($by, $item->report));

        if ($quantity <= 0 || $quantity > 100000) {
            throw ValidationException::withMessages(['material' => 'Bitte eine Menge größer als 0 angeben.']);
        }

        $item->update(['quantity' => $quantity]);
    }

    public function removeMaterial(User $by, ReportMaterial $item): void
    {
        $this->assert(self::canEdit($by, $item->report));
        $item->delete();
    }

    /** Coordinates set by hand (map), e.g. for a field path without address */
    public function setLocation(User $by, Report $report, ?float $lat, ?float $lng): void
    {
        $this->assert(self::canEdit($by, $report));

        if (($lat === null) !== ($lng === null) || ($lat !== null && (abs($lat) > 90 || abs($lng) > 180))) {
            throw ValidationException::withMessages(['location' => 'Ungültige Koordinaten.']);
        }

        $report->update(['latitude' => $lat, 'longitude' => $lng]);
    }

    /**
     * Verbrauch of a year (closed and released reports) per category and material.
     *
     * @return Collection<string, array{label: string, items: Collection}>
     */
    public function consumption(int $year): Collection
    {
        $from = Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc();

        return ReportMaterial::whereHas('report', fn ($q) => $q->whereIn('status', self::countedStatuses())->whereBetween('alarmed_at', [$from, $from->copy()->addYear()]))
            ->get()
            ->groupBy('category')
            ->map(fn (Collection $items, $category) => [
                'label' => Material::CATEGORIES[$category] ?? $category,
                'items' => $items->groupBy(fn ($i) => $i->name.'|'.$i->unit)->map(fn (Collection $group) => [
                    'name' => $group->first()->name,
                    'unit' => $group->first()->unit,
                    'quantity' => $group->sum('quantity'),
                    'reports' => $group->pluck('report_id')->unique()->count(),
                ])->sortBy('name')->values(),
            ])
            ->sortKeys();
    }

    /** The Einsatzleiter confirms; the report is then locked for him. Mandatory fields must be there. */
    public function close(User $by, Report $report, ?string $signature = null): void
    {
        $this->assert(self::canLeadReport($by, $report) && in_array($report->status, Report::OPEN, true), 'Nur ein Entwurf oder aktueller Einsatz lässt sich abschließen.');

        $missing = $this->missing($report);
        if ($missing) {
            throw ValidationException::withMessages(['report' => 'Noch nicht ausgefüllt: '.implode(', ', $missing).'.']);
        }

        $report->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id,
            'closed_signature' => filled($signature) ? SignatureImage::fromDataUrl($signature) : null]);

        // The Wehrführung (and admins) hear that a report waits for them
        $releasers = $this->releaseRecipients($report)->reject(fn (User $user) => $user->is($by));
        $this->tell($releasers, new ReportStatusNotification($report, $by));
    }

    /** The Wehrführung checks and releases */
    /** The Wehrführung checks, releases and (optionally) signs; the checksum seals the content of that moment */
    public function release(User $by, Report $report, ?string $signature = null): void
    {
        $this->assert(self::canReleaseReport($by, $report) && $report->status === 'closed', 'Freigeben kann die Wehrführung einen abgeschlossenen Bericht.');
        $png = filled($signature) ? SignatureImage::fromDataUrl($signature) : null;
        $report->update(['status' => 'released', 'released_at' => now(), 'released_by' => $by->id, 'released_signature' => $png,
            'released_hash' => self::contentHash($report)]);
    }

    /**
     * sha256 over what the report says (fields, vehicles with times, crew, material), not over
     * bookkeeping. Changed after the release, the report no longer matches its checksum.
     */
    public static function contentHash(Report $report): string
    {
        $report->loadMissing(['vehicles.crew', 'materials']);
        $fields = collect($report->only(['number', 'dispatch_number', 'keyword', 'alarmed_at', 'departed_at', 'arrived_at', 'ended_at', 'ready_at',
            'address', 'object', 'latitude', 'longitude', 'affected_parties', 'category', 'details', 'situation', 'measures', 'equipment_used', 'rescued', 'injured', 'dead',
            'property_damage', 'environmental_damage', 'police', 'ems', 'neighbor_brigades', 'other_parties', 'handover', 'fire_watch', 'fire_watch_notes', 'commander_id']))
            ->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('c') : $v);
        $vehicles = $report->vehicles->map(fn ($v) => [$v->name, $v->is_standby, $v->departed_at?->format('c'), $v->arrived_at?->format('c'), $v->left_at?->format('c'), $v->returned_at?->format('c'),
            $v->crew->sortBy('user_id')->map(fn ($c) => [$c->user_id, $c->function, (bool) $c->breathing_apparatus])->values()->all()])->values();
        $materials = $report->materials->map(fn ($m) => [$m->name, $m->unit, (float) $m->quantity])->sortBy(0)->values();

        return hash('sha256', json_encode([$fields, $vehicles, $materials]));
    }

    /** null: not released with a checksum; true: unchanged since; false: corrected after the release */
    public static function sealIntact(Report $report): ?bool
    {
        return $report->released_hash ? hash_equals($report->released_hash, self::contentHash($report->fresh(['vehicles.crew', 'materials']))) : null;
    }

    /**
     * A Nebenbericht for a large Einsatz (another Abteilung, Abschnitt or Zug): it takes the
     * Kopfdaten of the Hauptbericht, gets the number "2026/007-1" and its own crew and vehicles.
     */
    public function createSide(User $by, Report $parent, ?string $label = null): Report
    {
        $this->assert(self::canLeadReport($by, $parent) && ! $parent->isSide(), 'Ein Nebenbericht gehört zu einem Hauptbericht.');

        return DB::transaction(function () use ($by, $parent, $label) {
            Report::whereKey($parent->id)->lockForUpdate()->first();
            $n = Report::where('parent_id', $parent->id)->count() + 1;
            while (Report::withoutGlobalScopes()->where('number', $parent->number.'-'.$n)->exists()) {
                $n++;
            }

            return Report::forceCreate([
                ...$parent->only(['dispatch_number', 'keyword', 'alarmed_at', 'departed_at', 'arrived_at', 'address', 'object', 'latitude', 'longitude', 'category']),
                ...(Schema::hasColumn('eb_reports', 'ext_location_id') ? ['ext_location_id' => $parent->getAttribute('ext_location_id')] : []),
                'number' => $parent->number.'-'.$n, 'parent_id' => $parent->id, 'side_label' => filled($label) ? mb_substr(trim($label), 0, 150) : null,
                'status' => $parent->status === 'active' ? 'active' : 'draft', 'commander_id' => $by->id, 'created_by' => $by->id,
            ]);
        });
    }

    /**
     * Daily: an Einsatz still open 3 days after the alarm reminds its Einsatzleiter (or whoever
     * created it), a closed report waiting 7 days for the release reminds the Wehrführung. Each
     * report at most once a week.
     */
    public function remind(): int
    {
        $ids = collect(DB::select(
            "UPDATE eb_reports SET reminded_at = ?
             WHERE (reminded_at IS NULL OR reminded_at < ?)
               AND ((status IN ('draft', 'active') AND alarmed_at < ?)
                 OR (status = 'closed' AND closed_at < ?))
             RETURNING id",
            [now(), now()->subDays(7), now()->subDays(3), now()->subDays(7)]
        ))->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        $reports = Report::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('alarmed_at')->get();

        foreach ($reports->whereIn('status', Report::OPEN)->groupBy(fn (Report $report) => ($report->commander_id ?? $report->created_by).'|'.($report->getAttribute('ext_location_id') ?? 'unassigned')) as $own) {
            $report = $own->first();
            $userId = $report->commander_id ?? $report->created_by;
            $user = $userId ? User::find($userId) : null;
            $to = $user && self::canLeadReport($user, $report) ? collect([$user]) : $this->releaseRecipients($report);
            $this->tell($to, new ReportReminderNotification($own->values(), false));
        }
        foreach ($reports->where('status', 'closed')->groupBy(fn (Report $report) => $report->getAttribute('ext_location_id') ?? 'unassigned') as $waiting) {
            $this->tell($this->releaseRecipients($waiting->first()), new ReportReminderNotification($waiting->values(), true));
        }

        return $reports->count();
    }

    /**
     * The Statusliste of a year: how many reports in which state, and how many are still open
     * (Entwurf, aktuell or waiting for the release) per Einsatzart.
     *
     * @return array{states: array<string, int>, openByCategory: Collection}
     */
    public function overview(int $year): array
    {
        $from = Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc();
        $reports = Report::where(fn ($q) => $q->whereBetween('alarmed_at', [$from, $from->copy()->addYear()])->orWhereNull('alarmed_at'))->get(['status', 'category']);

        return [
            'states' => collect(Report::STATUSES)->map(fn ($l, $s) => $reports->where('status', $s)->count())->all(),
            'openByCategory' => $reports->whereIn('status', [...Report::OPEN, 'closed'])->groupBy(fn ($r) => $r->category ?? '')
                ->map(fn ($g, $c) => ['label' => Report::CATEGORIES[$c] ?? 'Ohne Einsatzart', 'count' => $g->count()])->sortByDesc('count')->values(),
        ];
    }

    /** The Wehrführung hands a closed report back to the Einsatzleiter; it was a real Einsatz, so it is aktuell again */
    public function reopen(User $by, Report $report): void
    {
        $this->assert(self::canReleaseReport($by, $report) && in_array($report->status, Report::DONE, true));
        $closer = $report->closed_by ? User::find($report->closed_by) : null;
        $report->update(['status' => 'active', 'closed_at' => null, 'closed_by' => null, 'released_at' => null, 'released_by' => null,
            'closed_signature' => null, 'released_signature' => null, 'released_hash' => null]);

        if ($closer && $closer->is_active && ! $closer->is($by)) {
            $this->tell(collect([$closer]), new ReportStatusNotification($report, $by, returned: true));
        }
    }

    /** A mail problem is logged, never thrown */
    private function tell(Collection $users, ReportStatusNotification|ReportReminderNotification $notification): void
    {
        $users->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public function delete(User $by, Report $report): void
    {
        $this->assert(in_array($report->status, Report::OPEN, true) ? self::canLeadReport($by, $report) : self::canReleaseReport($by, $report));
        $report->delete();
    }

    private function releaseRecipients(Report $report): Collection
    {
        return User::active()->get()->filter(fn (User $user) => self::canReleaseReport($user, $report))->values();
    }

    /** @return list<string> labels of mandatory fields still empty */
    public function missing(Report $report): array
    {
        return array_map(fn ($field) => $field === 'details'
            ? 'Angaben zur Einsatzart ('.implode(', ', ReportDetails::missing($report->category, $report->details)).')'
            : Report::REQUIRABLE[$field], $this->missingFields($report));
    }

    /** @return list<string> keys of the mandatory fields still empty, in the order of the form */
    public function missingFields(Report $report): array
    {
        // The order of the form, so the first one is the topmost on the page
        return collect(['dispatch_number', 'keyword', 'alarmed_at', 'departed_at', 'arrived_at', 'ended_at', 'ready_at', 'address', 'category', 'details', 'situation', 'measures', 'crew', 'commander_id'])
            ->intersect(self::required())
            ->filter(fn ($field) => match ($field) {
                'crew' => ! $report->crew()->exists(),
                'details' => ReportDetails::missing($report->category, $report->details) !== [],
                default => blank($report->getAttribute($field)),
            })
            ->values()->all();
    }

    /**
     * Stärke Führung/Unterführer/Mannschaft/Gesamt at the Einsatzstelle; the Bereitschaft does not count.
     *
     * @return array{f: int, u: int, m: int, total: int, label: string}
     */
    public static function strength(Collection $crew): array
    {
        $counts = ['f' => 0, 'u' => 0, 'm' => 0];
        foreach ($crew as $member) {
            $counts[$member->level()]++;
        }
        $total = array_sum($counts);

        return [...$counts, 'total' => $total, 'label' => $counts['f'].'/'.$counts['u'].'/'.$counts['m'].'/'.$total];
    }

    public static function reportStrength(Report $report): array
    {
        $report->loadMissing('vehicles.crew');

        return self::strength($report->vehicles->reject->is_standby->flatMap->crew);
    }

    /** One Kamerad's Einsätze (closed and released) with counters */
    public function memberRecord(User $member): array
    {
        $crew = CrewMember::with(['report', 'reportVehicle'])->where('user_id', $member->id)
            ->whereHas('report', fn ($q) => $q->whereIn('status', Report::DONE))
            ->get()->sortByDesc(fn (CrewMember $c) => $c->report->alarmed_at?->timestamp ?? 0)->values();
        $year = now('Europe/Berlin')->year;

        return [
            'entries' => $crew,
            'total' => $crew->count(),
            'year' => $crew->filter(fn ($c) => $c->report->alarmed_at?->setTimezone('Europe/Berlin')->year === $year)->count(),
            'minutes' => $crew->sum(fn ($c) => $c->report->durationMinutes() ?? 0),
            'breathing' => $crew->where('breathing_apparatus', true)->count(),
        ];
    }

    /**
     * The states that count in Jahresstatistik and Jahresbericht: by default only released reports
     * (after the Wehrführung checked them), or closed ones too if the Wehrführung chose so.
     *
     * @return list<string>
     */
    public static function countedStatuses(): array
    {
        return Setting::get('einsatzbericht.released_only', '1') === '1' ? ['released'] : Report::DONE;
    }

    /** Jahresstatistik over the counted reports */
    public function statistics(int $year): array
    {
        $reports = Report::with(['vehicles.crew'])->whereIn('status', self::countedStatuses())
            ->whereNotNull('alarmed_at')->get()
            ->filter(fn (Report $r) => $r->alarmed_at->setTimezone('Europe/Berlin')->year === $year)->values();
        // Nebenberichte bring crew, hours and vehicles, but are not Einsätze of their own
        $all = $reports;
        $reports = $all->reject->isSide()->values();

        $byMonth = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => $reports->filter(fn ($r) => $r->alarmed_at->setTimezone('Europe/Berlin')->month === $m)->count()]);
        $personMinutes = $all->sum(fn (Report $r) => ($r->durationMinutes() ?? 0) * $r->vehicles->flatMap->crew->count());

        return [
            'total' => $reports->count(),
            'byCategory' => collect(Report::CATEGORIES)->map(fn ($label, $key) => ['label' => $label, 'count' => $reports->where('category', $key)->count()])
                ->filter(fn ($row) => $row['count'] > 0)->sortByDesc('count')->values(),
            'byMonth' => $byMonth,
            'byVehicle' => $all->flatMap->vehicles->reject->is_standby->groupBy('name')->map->count()->sortDesc(),
            'minutes' => $reports->sum(fn (Report $r) => $r->durationMinutes() ?? 0),
            'personMinutes' => $personMinutes,
            'averageStrength' => $reports->isEmpty() ? 0 : round($reports->avg(fn (Report $r) => self::reportStrength($r)['total']), 1),
            'rescued' => $reports->sum('rescued'),
            'injured' => $reports->sum('injured'),
            'dead' => $reports->sum('dead'),
            'breathing' => $all->flatMap->crew->where('breathing_apparatus', true)->count(),
            'details' => ReportDetails::statistics($reports),
            'sides' => $all->count() - $reports->count(),
            'drafts' => Report::whereIn('status', Report::OPEN)->count(),
            // Closed but not yet released: waiting for the Wehrführung, not counted yet
            'waiting' => self::countedStatuses() === ['released'] ? Report::where('status', 'closed')->count() : 0,
        ];
    }

    private function assert(bool $allowed, string $message = 'Dafür fehlt die Berechtigung.'): void
    {
        if (! $allowed) {
            throw ValidationException::withMessages(['report' => $message]);
        }
    }
}
