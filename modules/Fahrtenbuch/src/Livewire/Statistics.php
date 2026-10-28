<?php

namespace Modules\Fahrtenbuch\src\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Fahrtenbuch\src\Services\LogbookService;

/** Fahrleistung and fuel per vehicle and year, km by purpose */
class Statistics extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= now('Europe/Berlin')->year;
    }

    public function render(LogbookService $logbook)
    {
        $rows = $logbook->statistics($this->year, auth()->user());

        return view('fahrtenbuch::livewire.statistics', [
            'rows' => $rows,
            'totals' => ['trips' => $rows->sum('trips'), 'km' => $rows->sum('km'), 'liters' => $rows->sum('liters'), 'cost' => $rows->sum('cost')],
            'purposes' => $rows->flatMap(fn ($r) => array_keys($r['by_purpose']))->unique()->values(),
        ]);
    }
}
