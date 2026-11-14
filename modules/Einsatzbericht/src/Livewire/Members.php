<?php

namespace Modules\Einsatzbericht\src\Livewire;

use App\Models\User;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ReportService;

/**
 * Wehrführung: every Kamerad with the number of Einsätze (closed and released reports). The
 * Wehrführung of a Standort sees its own Kameraden and the Einsätze of its Standorte.
 */
class Members extends Component
{
    public string $search = '';

    public function mount(): void
    {
        abort_unless(ReportService::canLead(auth()->user()), 403);
    }

    public function render()
    {
        $term = trim($this->search);
        $year = now('Europe/Berlin')->year;
        $memberIds = ReportService::evaluatedMemberIds(auth()->user());
        // Through the model, so the reports are limited to the Standorte like on the Kamerad's page
        $count = fn (bool $thisYear) => Report::query()->join('eb_report_crew', 'eb_reports.id', '=', 'eb_report_crew.report_id')
            ->whereColumn('eb_report_crew.user_id', 'users.id')->whereIn('eb_reports.status', Report::DONE)
            ->when($thisYear, fn ($q) => $q->whereRaw("extract(year from eb_reports.alarmed_at at time zone 'Europe/Berlin') = ?", [$year]))
            ->selectRaw('count(*)');

        return view('einsatzbericht::livewire.members', [
            'members' => User::active()->select('users.*')
                ->selectSub($count(false), 'missions')->selectSub($count(true), 'missions_year')
                ->when($memberIds !== null, fn ($q) => $q->whereIn('users.id', $memberIds))
                ->when($term !== '', fn ($q) => $q->where('name', 'ilike', "%$term%"))
                ->orderByDesc('missions')->orderBy('name')->get(),
            'year' => $year,
        ]);
    }
}
