<?php

namespace Modules\Expositionen\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Expositionen\src\Livewire\Concerns\ExposureFields;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Services\ExposureService;

/** Record an exposure (own, or for a Kamerad by the Beauftragte), confirm a proposal, correct one */
class ExposureForm extends Component
{
    use ExposureFields;

    #[Locked]
    public ?int $exposureId = null;

    #[Locked]
    public ?int $reportId = null;

    public string $memberId = '';

    public function mount(?Exposure $exposure = null): void
    {
        $me = auth()->user();
        if ($exposure?->exists) {
            abort_unless($exposure->user_id === $me->id || ExposureService::canManage($me), 403);
            $this->exposureId = $exposure->id;
            $this->form = $this->formOf($exposure);

            return;
        }
        $report = request()->filled('einsatz') ? Report::find(request()->integer('einsatz')) : null;
        $this->reportId = $report?->id;
        $this->form = $this->blankForm($report);
        // Who wore Atemschutz in this Einsatz has it as protection already
        if ($report?->crew()->where('user_id', $me->id)->where('breathing_apparatus', true)->exists()) {
            $this->form['protection'] = ['atemschutz'];
        }
        $this->memberId = (string) $me->id;
    }

    public function save(ExposureService $service)
    {
        $me = auth()->user();
        try {
            if ($this->exposureId) {
                $service->update($me, Exposure::findOrFail($this->exposureId), $this->form);
            } else {
                $member = ExposureService::canManage($me) ? User::findOrFail((int) $this->memberId) : $me;
                $service->record($me, $member, $this->form, $this->reportId ? Report::find($this->reportId) : null);
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return null;
        }
        session()->flash('expositionen', 'Exposition gespeichert.');

        return $this->redirectRoute('expositionen.index');
    }

    public function discard(ExposureService $service)
    {
        try {
            $service->discard(auth()->user(), Exposure::findOrFail($this->exposureId));
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return null;
        }
        session()->flash('expositionen', 'Vorschlag verworfen.');

        return $this->redirectRoute('expositionen.index');
    }

    public function render()
    {
        $exposure = $this->exposureId ? Exposure::with(['events.user', 'user'])->find($this->exposureId) : null;

        return view('expositionen::livewire.exposure-form', [
            'exposure' => $exposure,
            'canManage' => ExposureService::canManage(auth()->user()),
            'members' => ! $exposure && ExposureService::canManage(auth()->user()) ? User::active()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
