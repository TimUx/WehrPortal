<?php

namespace Modules\Uebungsdienste\src\Livewire;

use App\Models\DressCode;
use App\Models\Group;
use App\Models\Place;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\CalendarLink;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/** Wehrführung or admin: plan services for one or more Einsatzabteilungen */
class ServiceForm extends Component
{
    public ?Service $service = null;

    public string $date = '';

    public string $until = '';

    public int $every = 2;

    public string $time = '19:00';

    public string $endTime = '21:00';

    public string $location = 'Wache';

    public string $placeId = '';

    /** @var list<string> */
    public array $dressCodeIds = [];

    /** @var list<string> Standorte invited to the service */
    public array $targetLocationIds = [];

    /** @var list<string> Einsatzabteilungen when the Locations extension is disabled */
    public array $targetGroupIds = [];

    public bool $series = false;

    public function mount(?Service $service = null): void
    {
        $plan = app(ServicePlan::class);
        $this->authorize('plan-uebungsdienste');

        if ($service?->exists) {
            abort_unless($plan->canManageService(auth()->user(), $service), 403);
            $this->service = $service;
            $this->date = $service->date->toDateString();
            $this->time = (string) $service->starts_at;
            $this->endTime = (string) $service->ends_at;
            $this->location = (string) $service->location;
            $this->placeId = (string) ($service->place_id ?? '');
            $this->dressCodeIds = array_map('strval', $service->dress_code_ids ?? []);
            if ($plan->locationsEnabled()) {
                $this->targetLocationIds = $plan->targetIdsForGroups($service->group_ids ?? []);
            } else {
                $this->targetGroupIds = array_map('strval', $service->group_ids ?? []);
            }
        } elseif ($first = Place::options()->keys()->first()) {
            // A new Dienst starts at the first saved place
            $this->placeId = (string) $first;
            $this->location = (string) Place::find($first)?->name;
        }

        if (! $service?->exists) {
            if ($plan->locationsEnabled()) {
                $this->targetLocationIds = $plan->defaultTargetIds(auth()->user());
            } else {
                $this->targetGroupIds = $plan->defaultTargetIds(auth()->user());
            }
        }
    }

    public function updatedPlaceId(string $value): void
    {
        if ($place = Place::find((int) $value)) {
            $this->location = $place->name;
        }
    }

    private function details(): array
    {
        return ['ends_at' => $this->endTime, 'place_id' => $this->placeId ?: null, 'dress_code_ids' => $this->dressCodeIds,
            'target_location_ids' => $this->targetLocationIds, 'group_ids' => $this->targetGroupIds];
    }

    public function save(ServicePlan $plan)
    {
        $this->authorize('plan-uebungsdienste');
        $user = auth()->user();

        try {
            if ($this->service) {
                $plan->reschedule($user, $this->service, $this->date, $this->time, $this->location, $this->details());
                $message = 'Dienst am '.$this->service->refresh()->dateLabel().' geändert.';
            } elseif ($this->series) {
                $message = $plan->addSeries($user, $this->date, $this->until, $this->every, $this->time, $this->location, $this->details())->count().' Dienste angelegt.';
            } else {
                $message = 'Dienst am '.$plan->add($user, $this->date, $this->time, $this->location, $this->details())->dateLabel().' angelegt.';
            }
        } catch (ValidationException $e) {
            $this->addError('plan', collect($e->errors())->flatten()->first());

            return null;
        }

        session()->flash('uebungsdienste.flash', $message);

        return redirect()->route('uebungsdienste.index', ['jahr' => substr($this->date, 0, 4)]);
    }

    public function delete(ServicePlan $plan)
    {
        $this->authorize('plan-uebungsdienste');
        $label = $this->service->dateLabel();
        $plan->delete(auth()->user(), $this->service);
        session()->flash('uebungsdienste.flash', 'Dienst am '.$label.' gelöscht.');

        return redirect()->route('uebungsdienste.index');
    }

    public function render()
    {
        $plan = app(ServicePlan::class);

        return view('uebungsdienste::livewire.service-form', [
            'places' => Place::options(),
            'dressCodes' => DressCode::options(),
            'targetLocations' => $plan->locationOptions(auth()->user()),
            'targetGroups' => Group::options(fn ($query) => $query->whereRaw('lower(name) = ?', ['einsatzabteilung'])),
            'locationsEnabled' => $plan->locationsEnabled(),
            'inCalendar' => CalendarLink::available(),
        ]);
    }
}
