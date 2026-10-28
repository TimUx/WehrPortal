<?php

namespace Modules\Expositionen\src\Livewire;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Services\ExposureService;

/** The Expositionsbeauftragte: all exposures, what still has to go to the ZED, marking it reported, CSV */
class Manage extends Component
{
    #[Url]
    public string $status = 'recorded';

    #[Url]
    public string $year = '';

    /** @var list<string> */
    public array $selected = [];

    public string $reportedOn = '';

    public string $reference = '';

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(ExposureService::canManage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->reportedOn = ExposureService::today()->toDateString();
    }

    public function markReported(ExposureService $service): void
    {
        $this->resetErrorBag();
        if ($this->selected === []) {
            $this->addError('selected', 'Bitte die gemeldeten Expositionen auswählen.');

            return;
        }
        try {
            $count = $service->markReported(auth()->user(), array_map('intval', $this->selected), $this->reportedOn, $this->reference);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->flash = $count.' als an die ZED gemeldet vermerkt.';
        $this->reset('selected', 'reference');
    }

    public function query()
    {
        $query = ExposureService::managedExposures(auth()->user())->with('user');
        if (config('locations.enabled') && Schema::hasTable('x_locations')) {
            $query->with('organizationalLocation');
        }

        return $query
            ->when(array_key_exists($this->status, Exposure::STATUSES), fn ($q) => $q->where('status', $this->status))
            ->when($this->year !== '', fn ($q) => $q->whereYear('occurred_on', (int) $this->year))
            ->orderByDesc('occurred_on')->orderBy('person_name');
    }

    public function render()
    {
        return view('expositionen::livewire.manage', [
            'exposures' => $this->query()->get(),
            'counts' => ExposureService::managedExposures(auth()->user())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'showLocations' => config('locations.enabled') && Schema::hasTable('x_locations'),
        ]);
    }
}
