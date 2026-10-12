<?php

namespace Modules\Einsatzbericht\src\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\Geocoder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Einsatzbericht\src\Models\CrewMember;
use Modules\Einsatzbericht\src\Models\Material;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\ReportMaterial;
use Modules\Einsatzbericht\src\Models\ReportVehicle;
use Modules\Einsatzbericht\src\Notifications\ReportStatusNotification;
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
            && ($user->is_admin || $user->hasModuleRole('einsatzbericht', 'einsatzleiter') || $user->hasModuleRole('einsatzbericht', 'wehrführung'));
    }

    /** Wehrführung and admins release, correct and evaluate */
    public static function canRelease(User $user): bool
    {
        return $user->is_active && $user->canUseModule('einsatzbericht')
            && ($user->is_admin || $user->hasModuleRole('einsatzbericht', 'wehrführung'));
    }

    public static function canEdit(User $user, Report $report): bool
    {
        return in_array($report->status, Report::OPEN, true) ? self::canLead($user) : self::canRelease($user);
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

        return $this->numbered(['commander_id' => $by->id, 'created_by' => $by->id], $alarmedAt ?? now());
    }

    /** A current (aktuell) Einsatz from an alarm (DIVERA), without anyone logged in: the alarm has happened */
    public function createFromAlarm(array $data, Carbon $alarmedAt): Report
    {
        return $this->numbered([...collect($data)->only(['keyword', 'alarm_message', 'address', 'latitude', 'longitude', 'divera_id'])->all(), 'source' => 'divera', 'status' => 'active'], $alarmedAt);
    }

    /** "Einsatz starten": the draft becomes the current Einsatz (Lagemeldungen, QR for external Wehren) */
    public function start(User $by, Report $report): void
    {
        $this->assert(self::canLead($by) && $report->status === 'draft', 'Nur ein Entwurf lässt sich starten.');
        $report->update(['status' => 'active']);
    }

    private function numbered(array $attributes, Carbon $alarmedAt): Report
    {
        $year = $alarmedAt->copy()->setTimezone('Europe/Berlin')->year;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $alarmedAt, $year) {
                    $last = Report::where('number', 'like', $year.'/%')->lockForUpdate()->pluck('number')
                        ->map(fn ($n) => (int) substr($n, 5))->max() ?? 0;

                    return Report::create([
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

        $data = collect($data)->only(array_diff((new Report)->getFillable(), ['number', 'status', 'created_by', 'closed_at', 'closed_by', 'released_at', 'released_by', 'source', 'divera_id']))
            ->map(fn ($v) => $v instanceof Carbon ? $v->copy()->utc() : (is_string($v) ? (trim($v) !== '' ? trim($v) : null) : $v))
            ->all();

        if (isset($data['category']) && ! array_key_exists($data['category'], Report::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => 'Unbekannte Einsatzart.']);
        }

        $addressChanged = array_key_exists('address', $data) && $data['address'] !== $report->address;
        $report->update($data);

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

        if (! array_key_exists($function, CrewMember::FUNCTIONS)) {
            throw ValidationException::withMessages(['function' => 'Unbekannte Funktion.']);
        }

        return CrewMember::updateOrCreate(
            ['report_id' => $vehicle->report_id, 'user_id' => $member->id],
            ['report_vehicle_id' => $vehicle->id, 'function' => $function, 'breathing_apparatus' => $breathingApparatus && ! $vehicle->is_standby],
        );
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

        return ReportMaterial::whereHas('report', fn ($q) => $q->whereIn('status', ['closed', 'released'])->whereBetween('alarmed_at', [$from, $from->copy()->addYear()]))
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
    public function close(User $by, Report $report): void
    {
        $this->assert(self::canLead($by) && in_array($report->status, Report::OPEN, true), 'Nur ein Entwurf oder aktueller Einsatz lässt sich abschließen.');

        $missing = $this->missing($report);
        if ($missing) {
            throw ValidationException::withMessages(['report' => 'Noch nicht ausgefüllt: '.implode(', ', $missing).'.']);
        }

        $report->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);

        // The Wehrführung (and admins) hear that a report waits for them
        $releasers = User::active()->get()->filter(fn (User $u) => self::canRelease($u) && ! $u->is($by));
        $this->tell($releasers, new ReportStatusNotification($report, $by));
    }

    /** The Wehrführung checks and releases */
    public function release(User $by, Report $report): void
    {
        $this->assert(self::canRelease($by) && $report->status === 'closed', 'Freigeben kann die Wehrführung einen abgeschlossenen Bericht.');
        $report->update(['status' => 'released', 'released_at' => now(), 'released_by' => $by->id]);
    }

    /** The Wehrführung hands a closed report back to the Einsatzleiter; it was a real Einsatz, so it is aktuell again */
    public function reopen(User $by, Report $report): void
    {
        $this->assert(self::canRelease($by) && in_array($report->status, Report::DONE, true));
        $closer = $report->closed_by ? User::find($report->closed_by) : null;
        $report->update(['status' => 'active', 'closed_at' => null, 'closed_by' => null, 'released_at' => null, 'released_by' => null]);

        if ($closer && $closer->is_active && ! $closer->is($by)) {
            $this->tell(collect([$closer]), new ReportStatusNotification($report, $by, returned: true));
        }
    }

    /** A mail problem is logged, never thrown */
    private function tell(Collection $users, ReportStatusNotification $notification): void
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
        $this->assert(in_array($report->status, Report::OPEN, true) ? self::canLead($by) : self::canRelease($by));
        $report->delete();
    }

    /** @return list<string> labels of mandatory fields still empty */
    public function missing(Report $report): array
    {
        return array_map(fn ($field) => Report::REQUIRABLE[$field], $this->missingFields($report));
    }

    /** @return list<string> keys of the mandatory fields still empty, in the order of the form */
    public function missingFields(Report $report): array
    {
        // The order of the form, so the first one is the topmost on the page
        return collect(['dispatch_number', 'keyword', 'alarmed_at', 'departed_at', 'arrived_at', 'ended_at', 'ready_at', 'address', 'category', 'situation', 'measures', 'crew', 'commander_id'])
            ->intersect(self::required())
            ->filter(fn ($field) => $field === 'crew' ? ! $report->crew()->exists() : blank($report->getAttribute($field)))
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

    /** Jahresstatistik over closed and released reports */
    public function statistics(int $year): array
    {
        $reports = Report::with(['vehicles.crew'])->whereIn('status', ['closed', 'released'])
            ->whereNotNull('alarmed_at')->get()
            ->filter(fn (Report $r) => $r->alarmed_at->setTimezone('Europe/Berlin')->year === $year)->values();

        $byMonth = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => $reports->filter(fn ($r) => $r->alarmed_at->setTimezone('Europe/Berlin')->month === $m)->count()]);
        $personMinutes = $reports->sum(fn (Report $r) => ($r->durationMinutes() ?? 0) * $r->vehicles->flatMap->crew->count());

        return [
            'total' => $reports->count(),
            'byCategory' => collect(Report::CATEGORIES)->map(fn ($label, $key) => ['label' => $label, 'count' => $reports->where('category', $key)->count()])
                ->filter(fn ($row) => $row['count'] > 0)->sortByDesc('count')->values(),
            'byMonth' => $byMonth,
            'byVehicle' => $reports->flatMap->vehicles->reject->is_standby->groupBy('name')->map->count()->sortDesc(),
            'minutes' => $reports->sum(fn (Report $r) => $r->durationMinutes() ?? 0),
            'personMinutes' => $personMinutes,
            'averageStrength' => $reports->isEmpty() ? 0 : round($reports->avg(fn (Report $r) => self::reportStrength($r)['total']), 1),
            'rescued' => $reports->sum('rescued'),
            'injured' => $reports->sum('injured'),
            'dead' => $reports->sum('dead'),
            'breathing' => $reports->flatMap->crew->where('breathing_apparatus', true)->count(),
            'drafts' => Report::whereIn('status', Report::OPEN)->count(),
        ];
    }

    private function assert(bool $allowed, string $message = 'Dafür fehlt die Berechtigung.'): void
    {
        if (! $allowed) {
            throw ValidationException::withMessages(['report' => $message]);
        }
    }
}
