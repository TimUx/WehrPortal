<?php

namespace Modules\Einsatzbericht\src\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ReportService;

/** Wehrführung: Einsätze of a year by type, month and vehicle, hours and Stärke */
class Statistics extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    public function mount(): void
    {
        abort_unless(ReportService::canRelease(auth()->user()), 403);
        $this->year ??= now('Europe/Berlin')->year;
    }

    public function render(ReportService $reports)
    {
        $from = Carbon::create($this->year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc();

        return view('einsatzbericht::livewire.statistics', [
            'stats' => $reports->statistics($this->year),
            'consumption' => $reports->consumption($this->year),
            // Map of all Einsätze with a point (escaped for the popup)
            'points' => Report::whereNull('parent_id')->whereIn('status', ReportService::countedStatuses())->whereNotNull('latitude')->whereNotNull('longitude')
                ->whereBetween('alarmed_at', [$from, $from->copy()->addYear()])->get()
                ->map(fn (Report $r) => ['lat' => $r->latitude, 'lng' => $r->longitude, 'title' => e($r->keyword ?: $r->number), 'date' => $r->alarmed_at->setTimezone('Europe/Berlin')->format('d.m.Y')])
                ->values(),
        ]);
    }
}
