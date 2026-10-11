<?php

namespace Modules\Einsatzbericht\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ReportService;

/** Einsatzleiter and Wehrführung: all Einsätze of a year, newest first */
class ReportList extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'suche')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(ReportService::canLead(auth()->user()), 403);
        $this->year ??= now('Europe/Berlin')->year;
    }

    public function create(ReportService $reports)
    {
        try {
            $report = $reports->create(auth()->user());
        } catch (ValidationException $e) {
            $this->addError('report', collect($e->errors())->flatten()->first());

            return null;
        }

        return redirect()->route('einsatzbericht.show', $report);
    }

    public function render()
    {
        $term = trim($this->search);
        $from = now('Europe/Berlin')->setDate($this->year, 1, 1)->startOfDay()->utc();

        $reports = Report::with(['vehicles.crew', 'commander'])
            ->where(fn ($q) => $q->whereBetween('alarmed_at', [$from, $from->copy()->addYear()])->orWhereNull('alarmed_at'))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('keyword', 'ilike', "%$term%")->orWhere('address', 'ilike', "%$term%")
                ->orWhere('number', 'ilike', "%$term%")->orWhere('dispatch_number', 'ilike', "%$term%")))
            ->orderByDesc('alarmed_at')->orderByDesc('id')->get();

        return view('einsatzbericht::livewire.report-list', [
            'reports' => $reports,
            // toBase(): an empty Eloquent collection would not take the plain years pushed below
            'years' => Report::whereNotNull('alarmed_at')->get(['alarmed_at'])->toBase()->map(fn ($r) => $r->alarmed_at->setTimezone('Europe/Berlin')->year)
                ->push(now('Europe/Berlin')->year)->push($this->year)->unique()->sortDesc()->values(),
        ]);
    }
}
