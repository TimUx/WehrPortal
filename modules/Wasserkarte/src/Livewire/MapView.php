<?php

namespace Modules\Wasserkarte\src\Livewire;

use App\Services\ModuleRegistry;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Wasserkarte\src\Models\Source;
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
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->orderBy('number')->get();
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
            'canManage' => WaterService::canManage(auth()->user()),
        ]);
    }
}
