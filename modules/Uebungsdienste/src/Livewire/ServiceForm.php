<?php

namespace Modules\Uebungsdienste\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/** Admin: a new Übungsdienst (single or series) or changing date, time and place of one */
class ServiceForm extends Component
{
    public ?Service $service = null;

    public string $date = '';

    public string $until = '';

    public int $every = 2;

    public string $time = '19:00';

    public string $location = 'Wache';

    public bool $series = false;

    public function mount(?Service $service = null): void
    {
        $this->authorize('plan-uebungsdienste');

        if ($service?->exists) {
            $this->service = $service;
            $this->date = $service->date->toDateString();
            $this->time = (string) $service->starts_at;
            $this->location = (string) $service->location;
        }
    }

    public function save(ServicePlan $plan)
    {
        $this->authorize('plan-uebungsdienste');
        $user = auth()->user();

        try {
            if ($this->service) {
                $plan->reschedule($user, $this->service, $this->date, $this->time, $this->location);
                $message = 'Dienst am '.$this->service->refresh()->dateLabel().' geändert.';
            } elseif ($this->series) {
                $message = $plan->addSeries($user, $this->date, $this->until, $this->every, $this->time, $this->location)->count().' Dienste angelegt.';
            } else {
                $message = 'Dienst am '.$plan->add($user, $this->date, $this->time, $this->location)->dateLabel().' angelegt.';
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
        return view('uebungsdienste::livewire.service-form');
    }
}
