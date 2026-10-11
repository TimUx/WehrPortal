<?php

namespace Modules\Wasserkarte\src\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Wasserkarte\src\Models\Source;
use Throwable;

/**
 * Takes the Löschwasserquellen mapped in OpenStreetMap as a starting stock: hydrants
 * (emergency=fire_hydrant), tanks, ponds and suction points in a map section, via the Overpass API.
 * First a preview (new, linked to an existing source nearby, already taken over), then the import.
 * Data the Hydrantenwart keeps is never overwritten; empty fields are filled in.
 * The data is © OpenStreetMap contributors (ODbL), shown with the source.
 */
class OsmImporter
{
    public const ENDPOINT = 'https://overpass-api.de/api/interpreter';

    /** Largest section, in degrees per side (about 35 × 20 km): Overpass is a shared, free service */
    public const MAX_SPAN = 0.3;

    /** A source without OSM id this close to an OSM node is the same one (e.g. from the Wasserversorger's list) */
    public const MATCH_METERS = 15;

    public function __construct(private readonly WaterService $water) {}

    /**
     * The OSM objects in the section, already as Wasserkarte fields.
     *
     * @return list<array{osm_id: int, kind: string, latitude: float, longitude: float, number: ?string, pipe_dn: ?int, flow_lpm: ?int, pressure_bar: ?float, capacity_m3: ?int, location: ?string, operator: ?string}>
     */
    public function fetch(float $south, float $west, float $north, float $east): array
    {
        if ($north <= $south || $east <= $west || $north - $south > self::MAX_SPAN || $east - $west > self::MAX_SPAN) {
            throw ValidationException::withMessages(['osm' => 'Der Kartenausschnitt ist zu groß. Bitte näher heranzoomen (höchstens etwa 30 × 20 km).']);
        }

        $box = implode(',', array_map(fn ($v) => round($v, 6), [$south, $west, $north, $east]));
        $query = '[out:json][timeout:25];('
            ."node[\"emergency\"=\"fire_hydrant\"]({$box});"
            ."node[\"emergency\"=\"water_tank\"]({$box});"
            ."node[\"emergency\"=\"fire_water_pond\"]({$box});"
            ."node[\"emergency\"=\"suction_point\"]({$box});"
            .');out body 3000;';

        try {
            $response = Http::timeout(40)->withUserAgent('openwehr (Wasserkarte; Feuerwehr-Software)')->asForm()
                ->post(self::ENDPOINT, ['data' => $query]);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['osm' => 'OpenStreetMap (Overpass) ist gerade nicht erreichbar. Bitte später noch einmal versuchen.']);
        }
        if ($response->status() === 429 || $response->status() >= 500) {
            throw ValidationException::withMessages(['osm' => 'OpenStreetMap (Overpass) ist gerade ausgelastet. Bitte in ein paar Minuten noch einmal versuchen.']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['osm' => 'OpenStreetMap hat die Abfrage abgelehnt (HTTP '.$response->status().').']);
        }

        return collect($response->json('elements', []))
            ->filter(fn ($e) => ($e['type'] ?? '') === 'node' && isset($e['id'], $e['lat'], $e['lon']))
            ->map(fn ($e) => $this->toSource($e))
            ->values()->all();
    }

    /**
     * What an import would do with these objects.
     *
     * @return array{new: list<array>, link: list<array{osm: array, source: Source}>, known: int}
     */
    public function plan(array $objects): array
    {
        $known = Source::whereIn('osm_id', array_column($objects, 'osm_id'))->pluck('osm_id')->flip();
        $unlinked = Source::whereNull('osm_id')->get();
        $plan = ['new' => [], 'link' => [], 'known' => 0];

        foreach ($objects as $osm) {
            if ($known->has($osm['osm_id'])) {
                $plan['known']++;

                continue;
            }
            $match = $unlinked->first(fn (Source $s) => WaterService::distance($osm['latitude'], $osm['longitude'], $s->latitude, $s->longitude) <= self::MATCH_METERS
                && $this->sameFamily($s->kind, $osm['kind']));
            if ($match) {
                $plan['link'][] = ['osm' => $osm, 'source' => $match];
                $unlinked = $unlinked->reject(fn (Source $s) => $s->is($match));
            } else {
                $plan['new'][] = $osm;
            }
        }

        return $plan;
    }

    /**
     * New ones are created, nearby ones linked (only empty fields filled), known ones get missing
     * values filled in. Nothing the Hydrantenwart entered is overwritten.
     *
     * @return array{created: int, linked: int, updated: int}
     */
    public function import(User $by, array $objects): array
    {
        if (! WaterService::canManage($by)) {
            throw ValidationException::withMessages(['osm' => 'Das dürfen nur Hydrantenwarte und Admins.']);
        }
        $plan = $this->plan($objects);
        $result = ['created' => 0, 'linked' => 0, 'updated' => 0];

        DB::transaction(function () use ($plan, $objects, &$result) {
            foreach ($plan['new'] as $osm) {
                Source::create([...$osm, 'inspection_interval_months' => in_array($osm['kind'], ['unterflur', 'ueberflur'], true) ? WaterService::DEFAULT_INTERVAL : null]);
                $result['created']++;
            }
            foreach ($plan['link'] as $row) {
                $this->fillEmpty($row['source'], $row['osm']);
                $result['linked']++;
            }
            if ($plan['known'] > 0) {
                $byId = collect($objects)->keyBy('osm_id');
                Source::whereIn('osm_id', $byId->keys())->get()->each(function (Source $s) use ($byId, &$result) {
                    if ($this->fillEmpty($s, $byId[$s->osm_id])) {
                        $result['updated']++;
                    }
                });
            }
        });

        return $result;
    }

    /** Fills only what is empty; the position and kind of a linked source stay as kept */
    private function fillEmpty(Source $source, array $osm): bool
    {
        $missing = collect(['number', 'pipe_dn', 'flow_lpm', 'pressure_bar', 'capacity_m3', 'location', 'operator'])
            ->filter(fn ($f) => blank($source->{$f}) && filled($osm[$f]))
            ->mapWithKeys(fn ($f) => [$f => $osm[$f]])->all();
        $source->fill([...$missing, 'osm_id' => $osm['osm_id']]);
        $changed = $source->isDirty();
        $source->save();

        return $changed && $missing !== [];
    }

    /** Hydrants match hydrants, everything else matches everything else */
    private function sameFamily(string $a, string $b): bool
    {
        $hydrant = fn ($k) => in_array($k, ['unterflur', 'ueberflur'], true);

        return $hydrant($a) === $hydrant($b);
    }

    /** OSM tags → Wasserkarte fields (wiki: Tag:emergency=fire_hydrant) */
    private function toSource(array $node): array
    {
        $t = $node['tags'] ?? [];
        $kind = match ($t['emergency'] ?? '') {
            'water_tank' => 'behaelter',
            'fire_water_pond' => 'teich',
            'suction_point' => ($t['water_source'] ?? '') === 'pond' ? 'teich' : 'gewaesser',
            default => match ($t['fire_hydrant:type'] ?? '') {
                'pillar' => 'ueberflur',
                'pond' => 'teich',
                default => 'unterflur',
            },
        };
        $number = fn (?string $v) => $v !== null && preg_match('/\d+(?:[.,]\d+)?/', $v, $m) ? (float) str_replace(',', '.', $m[0]) : null;
        $volume = $number($t['water_tank:volume'] ?? $t['capacity'] ?? null);

        return [
            'osm_id' => (int) $node['id'],
            'kind' => $kind,
            'latitude' => round((float) $node['lat'], 7),
            'longitude' => round((float) $node['lon'], 7),
            'number' => isset($t['ref']) ? mb_substr(trim($t['ref']), 0, 30) : null,
            'pipe_dn' => ($dn = $number($t['fire_hydrant:diameter'] ?? $t['diameter'] ?? null)) ? (int) $dn : null,
            'flow_lpm' => ($flow = $number($t['flow_rate'] ?? null)) ? (int) $flow : null,
            'pressure_bar' => ($p = $number($t['fire_hydrant:pressure'] ?? $t['pressure'] ?? null)) && $p < 30 ? $p : null,
            // Litres in OSM above a few thousand are litres, not m³
            'capacity_m3' => $volume ? (int) round($volume > 5000 ? $volume / 1000 : $volume) : null,
            'location' => isset($t['addr:street']) ? mb_substr(trim($t['addr:street'].' '.($t['addr:housenumber'] ?? '')), 0, 200)
                : (isset($t['description']) ? mb_substr($t['description'], 0, 200) : null),
            'operator' => isset($t['operator']) ? mb_substr($t['operator'], 0, 150) : null,
        ];
    }
}
