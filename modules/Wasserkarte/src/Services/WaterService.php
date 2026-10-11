<?php

namespace Modules\Wasserkarte\src\Services;

use App\Models\User;
use App\Services\ImageResizer;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Wasserkarte\src\Models\Defect;
use Modules\Wasserkarte\src\Models\Inspection;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Models\StatusEvent;
use Modules\Wasserkarte\src\Notifications\DefectReportedNotification;
use Modules\Wasserkarte\src\Notifications\DueDigestNotification;
use Throwable;

/**
 * All changes of the Wasserkarte go through here: the Hydrantenwart keeps the
 * Löschwasserquellen with position and performance data, records inspections and sets the status;
 * every Kamerad can report a defect, which marks the source defective at once. nearest() gives
 * the closest usable sources to an Einsatzstelle.
 */
class WaterService
{
    public const MODULE = 'wasserkarte';

    /** Usual inspection interval for hydrants (DVGW W 400-3) */
    public const DEFAULT_INTERVAL = 12;

    public function __construct(private readonly ImageResizer $images) {}

    public static function canView(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE);
    }

    /** Hydrantenwart and admins: sources, status, inspections, import */
    public static function canManage(User $user): bool
    {
        return self::canView($user) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'hydrantenwart'));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'hydrantenwart'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    // ─── sources ─────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    public function saveSource(User $by, ?Source $source, array $data): Source
    {
        $this->assertManager($by);
        $clean = $this->validateSource($data);

        $source ??= new Source;
        $intervalChanged = $source->inspection_interval_months !== $clean['inspection_interval_months'];
        $source->fill($clean);
        if (($intervalChanged || ! $source->exists) && $source->last_inspected_on && $clean['inspection_interval_months']) {
            $source->next_inspection_on = $source->last_inspected_on->copy()->addMonths($clean['inspection_interval_months']);
        }
        $source->save();

        return $source;
    }

    public function setStatus(User $by, Source $source, string $status, ?string $note): void
    {
        $this->assertManager($by);
        if (! array_key_exists($status, Source::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Bitte einen Status wählen.']);
        }
        $note = $this->text($note, 300);
        if ($status !== 'available' && $note === null) {
            throw ValidationException::withMessages(['status_note' => 'Bitte kurz angeben, was los ist.']);
        }
        $this->changeStatus($source, $by, $status, $note);
    }

    /** A Prüfung: in order sets the next date (and makes it available), a defect marks it defective */
    public function recordInspection(User $by, Source $source, array $data): Inspection
    {
        $this->assertManager($by);
        $errors = [];
        $on = $this->date($data['inspected_on'] ?? null);
        if (! $on) {
            $errors['inspected_on'] = 'Bitte das Datum angeben.';
        } elseif ($on->gt(Carbon::parse(now('Europe/Berlin')->toDateString()))) {
            $errors['inspected_on'] = 'Das Datum liegt in der Zukunft.';
        }
        $result = (string) ($data['result'] ?? '');
        if (! array_key_exists($result, Inspection::RESULTS)) {
            $errors['result'] = 'Bitte das Ergebnis angeben.';
        }
        $notes = $this->text($data['notes'] ?? null, 1000);
        if ($result === 'defect' && $notes === null) {
            $errors['inspection_notes'] = 'Bitte den Mangel beschreiben.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($by, $source, $data, $on, $result, $notes) {
            $inspection = Inspection::create([
                'source_id' => $source->id, 'inspected_on' => $on->toDateString(), 'result' => $result, 'notes' => $notes, 'user_id' => $by->id,
                'flow_lpm' => $this->int($data['flow_lpm'] ?? null), 'static_pressure_bar' => $this->decimal($data['static_pressure_bar'] ?? null),
                'flow_pressure_bar' => $this->decimal($data['flow_pressure_bar'] ?? null),
            ]);
            $interval = $source->inspection_interval_months;
            $source->fill([
                'last_inspected_on' => $on->toDateString(),
                'next_inspection_on' => $interval ? $on->copy()->addMonths($interval)->toDateString() : null,
                'due_mailed_at' => null,
                // The measured flow is the current performance of the source
                ...($inspection->flow_lpm ? ['flow_lpm' => $inspection->flow_lpm] : []),
                ...($inspection->static_pressure_bar ? ['pressure_bar' => $inspection->static_pressure_bar] : []),
            ])->save();

            if ($result === 'defect') {
                $this->changeStatus($source, $by, 'defective', 'Prüfung: '.$notes);
            } elseif ($source->status === 'defective' && ! $source->defects()->whereNull('resolved_at')->exists()) {
                $this->changeStatus($source, $by, 'available', 'Prüfung in Ordnung');
            }

            return $inspection;
        });
    }

    /** Any Kamerad: the source is marked defective at once, the Hydrantenwarte are told */
    public function reportDefect(User $by, Source $source, string $description, ?UploadedFile $photo = null): Defect
    {
        if (! self::canView($by)) {
            throw ValidationException::withMessages(['defect' => 'Kein Zugang zur Wasserkarte.']);
        }
        $description = $this->text($description, 1000);
        if ($description === null || mb_strlen($description) < 5) {
            throw ValidationException::withMessages(['defect_description' => 'Bitte beschreiben, was nicht stimmt (z. B. „Deckel festgerostet“, „zugeparkt“).']);
        }

        $defect = DB::transaction(function () use ($by, $source, $description, $photo) {
            $defect = Defect::create(['source_id' => $source->id, 'reported_by' => $by->id, 'description' => $description,
                'photo' => $photo ? base64_encode($this->images->toWebp($photo->getRealPath(), 1600, 'defect_photo')) : null]);
            if ($source->status === 'available') {
                $this->changeStatus($source, $by, 'defective', 'Gemeldet: '.$description);
            }

            return $defect;
        });

        $this->notify($this->managers()->reject(fn (User $u) => $u->is($by)), new DefectReportedNotification($defect));

        return $defect;
    }

    /** Repaired: the source is available again once no defect is open */
    public function resolveDefect(User $by, Defect $defect, ?string $resolution): void
    {
        $this->assertManager($by);
        if (! $defect->isOpen()) {
            return;
        }
        DB::transaction(function () use ($by, $defect, $resolution) {
            $defect->update(['resolved_at' => now(), 'resolved_by' => $by->id, 'resolution' => $this->text($resolution, 500)]);
            $source = $defect->source;
            if ($source->status === 'defective' && ! $source->defects()->whereNull('resolved_at')->exists()) {
                $this->changeStatus($source, $by, 'available', 'Mangel behoben'.($resolution ? ': '.$resolution : ''));
            }
        });
    }

    // ─── for the Einsatz ─────────────────────────────────────────────────────

    /**
     * The closest sources to a point, with distance in metres. Defective and locked ones come too
     * (marked), so nobody drives to a broken hydrant; usable ones first.
     *
     * @return Collection<int, array{source: Source, meters: int}>
     */
    public function nearest(float $lat, float $lng, int $limit = 5, int $radiusMeters = 1500): Collection
    {
        return Source::active()->get()
            ->map(fn (Source $s) => ['source' => $s, 'meters' => (int) round(self::distance($lat, $lng, $s->latitude, $s->longitude))])
            ->filter(fn ($row) => $row['meters'] <= $radiusMeters)
            ->sortBy([fn ($a, $b) => ($a['source']->status === 'available' ? 0 : 1) <=> ($b['source']->status === 'available' ? 0 : 1), fn ($a, $b) => $a['meters'] <=> $b['meters']])
            ->take($limit)->values();
    }

    /** Great-circle distance in metres */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** @return list<array{id: int, lat: float, lng: float, color: string, title: string, info: string, url: string}> for the map */
    public static function markers(Collection $sources): array
    {
        return $sources->map(fn (Source $s) => [
            'id' => $s->id, 'lat' => $s->latitude, 'lng' => $s->longitude, 'color' => Source::COLORS[$s->status] ?? '#6b7280',
            'title' => $s->label(), 'info' => trim($s->statusLabel().($s->performance() ? ' · '.$s->performance() : '').($s->location ? ' · '.$s->location : '')),
            'url' => route('wasserkarte.source', $s),
        ])->values()->all();
    }

    // ─── import and reminders ────────────────────────────────────────────────

    /**
     * Sources from a CSV of the Wasserversorger (semicolon or comma; header with at least
     * Breitengrad/lat and Längengrad/lng). A row with a known number is updated, not doubled.
     *
     * @return array{created: int, updated: int, skipped: list<string>}
     */
    public function import(User $by, string $csv): array
    {
        $this->assertManager($by);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\R/', (string) $csv), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['import' => 'Die Datei enthält keine Zeilen.']);
        }
        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $aliases = [
            'number' => ['nummer', 'nr', 'number', 'id', 'hydrantennummer'], 'kind' => ['art', 'typ', 'kind', 'type'],
            'latitude' => ['breitengrad', 'lat', 'latitude', 'breite'], 'longitude' => ['längengrad', 'laengengrad', 'lng', 'lon', 'longitude', 'länge'],
            'location' => ['lage', 'ort', 'adresse', 'location', 'standort'], 'flow_lpm' => ['durchfluss', 'l/min', 'flow', 'leistung'],
            'pressure_bar' => ['druck', 'bar', 'pressure'], 'pipe_dn' => ['dn', 'nennweite'], 'capacity_m3' => ['m3', 'm³', 'inhalt', 'volumen'],
            'operator' => ['betreiber', 'versorger', 'operator'],
        ];
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv($lines[0], $delimiter));
        $columns = [];
        foreach ($aliases as $field => $names) {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    $columns[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($columns['latitude'], $columns['longitude'])) {
            throw ValidationException::withMessages(['import' => 'Die Kopfzeile braucht Spalten für Breitengrad (lat) und Längengrad (lng).']);
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped' => []];
        foreach (array_slice($lines, 1) as $n => $line) {
            $cells = str_getcsv($line, $delimiter);
            $value = fn (string $field) => isset($columns[$field]) ? trim((string) ($cells[$columns[$field]] ?? '')) : '';
            $kind = $this->kindFrom($value('kind'));
            try {
                $clean = $this->validateSource([
                    'number' => $value('number'), 'kind' => $kind, 'latitude' => $value('latitude'), 'longitude' => $value('longitude'),
                    'location' => $value('location'), 'flow_lpm' => $value('flow_lpm'), 'pressure_bar' => $value('pressure_bar'),
                    'pipe_dn' => $value('pipe_dn'), 'capacity_m3' => $value('capacity_m3'), 'operator' => $value('operator'),
                    'inspection_interval_months' => in_array($kind, ['unterflur', 'ueberflur'], true) ? self::DEFAULT_INTERVAL : null,
                ]);
            } catch (ValidationException $e) {
                $result['skipped'][] = 'Zeile '.($n + 2).': '.collect($e->errors())->flatten()->first();

                continue;
            }
            $existing = $clean['number'] ? Source::where('number', $clean['number'])->first() : null;
            if ($existing) {
                $existing->update(collect($clean)->except(['inspection_interval_months', 'is_active'])->filter(fn ($v) => $v !== null)->all());
                $result['updated']++;
            } else {
                Source::create($clean);
                $result['created']++;
            }
        }

        return $result;
    }

    /** Daily: one mail to the Hydrantenwarte with the sources whose inspection became due */
    public function remind(): int
    {
        if (! app(ModuleRegistry::class)->isActive(self::MODULE)) {
            return 0;
        }
        $ids = collect(DB::select(
            'UPDATE wk_sources SET due_mailed_at = now() WHERE is_active AND due_mailed_at IS NULL AND next_inspection_on <= ? RETURNING id',
            [now('Europe/Berlin')->addDays(30)->toDateString()]
        ))->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        $this->notify($this->managers(), new DueDigestNotification(Source::whereIn('id', $ids)->orderBy('next_inspection_on')->get()));

        return $ids->count();
    }

    // ─── details ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateSource(array $data): array
    {
        $errors = [];
        $kind = (string) ($data['kind'] ?? '');
        if (! array_key_exists($kind, Source::KINDS)) {
            $errors['kind'] = 'Bitte die Art der Wasserquelle wählen.';
        }
        $lat = $this->decimal($data['latitude'] ?? null);
        $lng = $this->decimal($data['longitude'] ?? null);
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $errors['latitude'] = 'Bitte die Position angeben (auf der Karte tippen oder „Mein Standort“).';
        }
        $interval = filled($data['inspection_interval_months'] ?? null) ? (int) $data['inspection_interval_months'] : null;
        if ($interval !== null && ($interval < 1 || $interval > 120)) {
            $errors['inspection_interval_months'] = 'Das Prüfintervall muss zwischen 1 und 120 Monaten liegen.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'number' => $this->text($data['number'] ?? null, 30), 'kind' => $kind, 'latitude' => round($lat, 7), 'longitude' => round($lng, 7),
            'location' => $this->text($data['location'] ?? null, 200), 'flow_lpm' => $this->int($data['flow_lpm'] ?? null),
            'pressure_bar' => $this->decimal($data['pressure_bar'] ?? null), 'capacity_m3' => $this->int($data['capacity_m3'] ?? null),
            'pipe_dn' => $this->int($data['pipe_dn'] ?? null), 'operator' => $this->text($data['operator'] ?? null, 150),
            'inspection_interval_months' => $interval, 'notes' => $this->text($data['notes'] ?? null, 2000),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function kindFrom(string $text): string
    {
        $t = mb_strtolower($text);

        return match (true) {
            array_key_exists($t, Source::KINDS) => $t,
            str_contains($t, 'über') || str_contains($t, 'ueber') || $t === 'oh' => 'ueberflur',
            str_contains($t, 'teich') => 'teich',
            str_contains($t, 'brunnen') => 'brunnen',
            str_contains($t, 'zisterne') || str_contains($t, 'behälter') || str_contains($t, 'behaelter') => 'behaelter',
            str_contains($t, 'gewässer') || str_contains($t, 'saug') || str_contains($t, 'see') || str_contains($t, 'bach') => 'gewaesser',
            default => 'unterflur',
        };
    }

    private function changeStatus(Source $source, ?User $by, string $status, ?string $note): void
    {
        if ($source->status === $status && $source->status_note === $note) {
            return;
        }
        $source->update(['status' => $status, 'status_note' => $status === 'available' ? null : $note]);
        StatusEvent::create(['source_id' => $source->id, 'status' => $status, 'note' => $note ? mb_substr($note, 0, 300) : null, 'user_id' => $by?->id]);
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::createFromFormat('!Y-m-d', (string) $value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function int(mixed $value): ?int
    {
        $value = is_string($value) ? str_replace(['.', ' '], '', trim($value)) : $value;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    private function decimal(mixed $value): ?float
    {
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;

        return is_numeric($value) ? (float) $value : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['wasserkarte' => 'Das dürfen nur Hydrantenwarte und Admins.']);
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
