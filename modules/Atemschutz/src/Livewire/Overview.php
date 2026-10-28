<?php

namespace Modules\Atemschutz\src\Livewire;

use App\Services\ModuleRegistry;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Models\TeamMember;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;
use Modules\Einsatzbericht\src\Models\Report;

/**
 * Start page: running Überwachungen and a new one (optionally for an Einsatzbericht, ?bericht=),
 * the pool with the AGT who can go right now, and the last Überwachungen with their protocol.
 */
class Overview extends Component
{
    public string $title = '';

    public string $kind = 'einsatz';

    public string $reportId = '';

    public string $locationId = '';

    public function updatedReportId(): void
    {
        $this->resetErrorBag('location_id');
        if (! $this->reportId || ! Schema::hasTable('eb_reports') || ! Schema::hasColumn('eb_reports', 'ext_location_id')) {
            $this->locationId = '';

            return;
        }

        $locationId = Report::whereKey((int) $this->reportId)->value('ext_location_id');
        $this->locationId = $locationId === null ? '' : (string) $locationId;
    }

    public function mount(LocationContext $locationContext): void
    {
        if (request()->filled('bericht') && Report::whereKey((int) request('bericht'))->exists()) {
            $this->reportId = (string) (int) request('bericht');
            $this->kind = 'einsatz';
        }

        if ($this->reportId === '' && config('locations.enabled')) {
            $current = $locationContext->forUser(auth()->user())['current'];
            if ($current !== null && MonitorService::operationLocations(auth()->user())->contains('id', $current)) {
                $this->locationId = (string) $current;
            }
        }
    }

    public function start(MonitorService $monitor)
    {
        $this->resetErrorBag();
        try {
            $operation = $monitor->startOperation(
                auth()->user(), $this->title, $this->kind, $this->reportId ? (int) $this->reportId : null,
                filled($this->locationId) ? (int) $this->locationId : null,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        return redirect()->route('atemschutz.board', $operation);
    }

    public function render(ReadinessService $readiness)
    {
        $user = auth()->user();
        $monitor = MonitorService::canMonitor($user);
        $locations = $monitor ? MonitorService::operationLocations($user) : collect();
        $reportLocationId = null;
        if ($this->reportId && Schema::hasTable('eb_reports') && Schema::hasColumn('eb_reports', 'ext_location_id')) {
            $reportLocationId = Report::whereKey((int) $this->reportId)->value('ext_location_id');
        }
        $states = $monitor ? $readiness->forWearers(Wearer::active()->with('user')->get()) : collect();
        $busy = TeamMember::whereNotNull('user_id')->whereHas('team', fn ($q) => $q->where('status', '!=', 'done')->whereHas('operation', fn ($o) => $o->whereNull('ended_at')))->pluck('user_id')->flip();

        return view('atemschutz::livewire.overview', [
            'canMonitor' => $monitor,
            'locations' => $locations,
            'reportLocationId' => $reportLocationId === null ? null : (int) $reportLocationId,
            'running' => MonitorService::applyOperationVisibility(Operation::running(), $user)
                ->when(config('locations.enabled'), fn ($query) => $query->with('location'))
                ->withCount(['teams as active_count' => fn ($q) => $q->where('status', 'active')])
                ->latest('started_at')->get(),
            'recent' => $monitor ? MonitorService::applyOperationVisibility(Operation::whereNotNull('ended_at'), $user)
                ->when(config('locations.enabled'), fn ($query) => $query->with('location'))
                ->withCount('teams')->latest('started_at')->limit(15)->get() : collect(),
            'available' => $states->filter(fn ($s) => $s['ready'] && ! $busy->has($s['wearer']->user_id))->sortBy(fn ($s) => $s['wearer']->user->name)->values(),
            'busyCount' => $busy->count(),
            'counts' => $readiness->poolCounts(),
            'reports' => $monitor && app(ModuleRegistry::class)->isActive('einsatzbericht')
                ? Report::where(fn ($q) => $q->whereIn('status', Report::OPEN)->orWhere('id', (int) $this->reportId))->latest('alarmed_at')->limit(20)->get(['id', 'number', 'keyword', 'alarmed_at'])
                : collect(),
        ]);
    }
}
