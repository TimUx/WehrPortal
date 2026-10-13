<?php

namespace Modules\Wasserkarte\src\Livewire;

use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Services\OsmImporter;
use Modules\Wasserkarte\src\Services\WaterService;

/**
 * The Wasserkarte: all sources on the map and as list, filters, "Mein Standort" with the nearest
 * sources, and ?einsatz= for an Einsatzstelle. Polls, so a defect reported elsewhere shows up.
 */
class MapView extends Component
{
    #[Url(as: 'art')]
    public string $kind = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'einsatz')]
    public ?int $reportId = null;

    /** @var ?array{lat: float, lng: float} the phone's position after "Mein Standort" */
    public ?array $point = null;

    public string $signature = '';

    /** @var ?array{key: string, total: int, new: int, link: int, known: int, sample: list<string>} the OpenStreetMap preview */
    public ?array $osm = null;

    public ?string $flash = null;

    /** Hydrantenwart: what OpenStreetMap has in the visible map section (the objects wait in the cache) */
    public function osmPreview(float $south, float $west, float $north, float $east, OsmImporter $importer): void
    {
        $this->resetErrorBag();
        $this->flash = null;
        abort_unless(WaterService::canManage(auth()->user()), 403);
        try {
            $objects = $importer->fetch($south, $west, $north, $east);
        } catch (ValidationException $e) {
            $this->addError('osm', collect($e->errors())->flatten()->first());

            return;
        }
        $plan = $importer->plan($objects);
        $key = 'wk_osm_'.Str::uuid();
        Cache::put($key, $objects, now()->addMinutes(20));
        $this->osm = ['key' => $key, 'total' => count($objects), 'new' => count($plan['new']), 'link' => count($plan['link']), 'known' => $plan['known'],
            'sample' => collect($plan['new'])->take(8)->map(fn ($o) => (Source::KINDS[$o['kind']] ?? $o['kind']).($o['number'] ? ' '.$o['number'] : '').($o['pipe_dn'] ? ' · DN '.$o['pipe_dn'] : ''))->all()];
    }

    public function osmImport(OsmImporter $importer): void
    {
        $objects = $this->osm ? Cache::pull($this->osm['key']) : null;
        if (! $objects) {
            $this->addError('osm', 'Die Vorschau ist abgelaufen. Bitte noch einmal abrufen.');
            $this->osm = null;

            return;
        }
        try {
            $result = $importer->import(auth()->user(), $objects);
        } catch (ValidationException $e) {
            $this->addError('osm', collect($e->errors())->flatten()->first());

            return;
        }
        $this->osm = null;
        $this->flash = 'Aus OpenStreetMap übernommen: '.$result['created'].' neu, '.$result['linked'].' mit vorhandenen verknüpft'.($result['updated'] ? ', '.$result['updated'].' ergänzt' : '').'.';
        $this->dispatchMarkers(force: true);
    }

    public function osmCancel(): void
    {
        if ($this->osm) {
            Cache::forget($this->osm['key']);
        }
        $this->osm = null;
    }

    public function nearPoint(float $lat, float $lng): void
    {
        $this->point = ['lat' => $lat, 'lng' => $lng];
        $this->dispatchMarkers(force: true);
    }

    public function updated(): void
    {
        $this->dispatchMarkers(force: true);
    }

    /** Every minute: new markers only when something changed */
    public function refresh(): void
    {
        $this->dispatchMarkers();
    }

    private function dispatchMarkers(bool $force = false): void
    {
        $markers = WaterService::markers($this->sources());
        $signature = md5(json_encode($markers));
        if ($force || $signature !== $this->signature) {
            $this->signature = $signature;
            $this->dispatch('water-markers', markers: $markers, highlight: $this->highlight());
        }
    }

    private function sources()
    {
        return Source::active()->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->when(array_key_exists($this->status, Source::STATUSES), fn ($q) => $q->where('status', $this->status))
            ->when($this->status === 'due', fn ($q) => $q->whereDate('next_inspection_on', '<=', now('Europe/Berlin')->addDays(30)->toDateString()))
            ->orderBy('number')->get();
    }

    private function einsatz(): ?Report
    {
        if (! $this->reportId || ! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return null;
        }
        $report = Report::find($this->reportId);

        return $report && $report->latitude && $report->longitude ? $report : null;
    }

    /** @return list<int> ids of the nearest sources to the Einsatzstelle or my position */
    private function highlight(): array
    {
        $at = $this->einsatz() ? ['lat' => (float) $this->einsatz()->latitude, 'lng' => (float) $this->einsatz()->longitude] : $this->point;

        return $at ? app(WaterService::class)->nearest($at['lat'], $at['lng'])->pluck('source.id')->all() : [];
    }

    public function render(WaterService $water)
    {
        $sources = $this->sources();
        $einsatz = $this->einsatz();
        $at = $einsatz ? ['lat' => (float) $einsatz->latitude, 'lng' => (float) $einsatz->longitude] : $this->point;
        $markers = WaterService::markers($sources);
        $this->signature = $this->signature ?: md5(json_encode($markers));

        return view('wasserkarte::livewire.map-view', [
            'sources' => $sources,
            'markers' => $markers,
            'einsatz' => $einsatz,
            'nearest' => $at ? $water->nearest($at['lat'], $at['lng']) : collect(),
            'highlight' => $this->highlight(),
            'counts' => Source::active()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'due' => Source::active()->whereDate('next_inspection_on', '<=', now('Europe/Berlin')->addDays(30)->toDateString())->count(),
            'canManage' => WaterService::canManage(auth()->user()),
        ]);
    }
}
