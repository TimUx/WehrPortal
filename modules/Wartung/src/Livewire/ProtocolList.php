<?php

namespace Modules\Wartung\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Services\WartungService;

/** All protocols with filters and the year's time and costs */
class ProtocolList extends Component
{
    use WithPagination;

    #[Url(as: 'jahr')]
    public ?int $year = null;

    #[Url(as: 'ergebnis')]
    public string $result = '';

    #[Url(as: 'suche')]
    public string $search = '';

    public function mount(): void
    {
        // ?jahr= (empty) shows all years, e.g. from the Prüfer page
        if (! request()->has('jahr')) {
            $this->year ??= WartungService::today()->year;
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(WartungService $service)
    {
        $term = trim($this->search);
        $protocols = Protocol::query()
            ->when($this->year, fn ($q) => $q->where('year', $this->year))
            ->when($this->result === 'voided', fn ($q) => $q->whereNotNull('voided_at'), fn ($q) => $q->when($this->result !== '', fn ($q) => $q->where('result', $this->result)))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('equipment_code', 'ilike', '%'.$term.'%')->orWhere('equipment_name', 'ilike', '%'.$term.'%')
                ->orWhere('title', 'ilike', '%'.$term.'%')->orWhere('inspector_name', 'ilike', '%'.$term.'%')->orWhere('inspector_number', 'ilike', '%'.$term.'%')
                ->orWhere('provider_name', 'ilike', '%'.$term.'%')))
            ->orderByDesc('performed_on')->orderByDesc('id')->paginate(50);

        return view('wartung::livewire.protocol-list', [
            'protocols' => $protocols,
            'years' => Protocol::query()->distinct()->orderByDesc('year')->pluck('year')->push(WartungService::today()->year)->unique()->sortDesc()->values(),
            'totals' => $this->year ? $service->yearTotals($this->year) : null,
        ]);
    }
}
