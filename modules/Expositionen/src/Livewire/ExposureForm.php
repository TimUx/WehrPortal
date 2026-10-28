<?php

namespace Modules\Expositionen\src\Livewire;

use App\Models\User;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Support\Facades\Schema;
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
            abort_unless(ExposureService::canView($me, $exposure), 403);
            $this->exposureId = $exposure->id;
            $this->form = $this->formOf($exposure);

            return;
        }
        $report = request()->filled('einsatz') ? Report::find(request()->integer('einsatz')) : null;
        $this->reportId = $report?->id;
        $this->form = $this->blankForm($report);
        if (config('locations.enabled') && Schema::hasTable('x_locations')) {
            $context = app(LocationContext::class)->forUser($me);
            $locations = ExposureService::canManage($me) ? ExposureService::managedLocations($me) : $context['locations'];
            $current = $report->ext_location_id ?? $context['current'];
            if (! $locations->contains('id', $current)) {
                $current = $locations->first()?->id;
            }
            $this->form['ext_location_id'] = (string) ($current ?? '');
        }
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
        $user = auth()->user();
        $canManage = ExposureService::canManage($user);
        $showLocations = config('locations.enabled') && Schema::hasTable('x_locations');
        $locations = $showLocations
            ? ($canManage ? ExposureService::managedLocations($user) : app(LocationContext::class)->forUser($user)['locations'])
            : collect();
        $locationId = filter_var($this->form['ext_location_id'] ?? null, FILTER_VALIDATE_INT);
        $report = $this->reportId ? Report::find($this->reportId) : null;

        return view('expositionen::livewire.exposure-form', [
            'exposure' => $exposure,
            'canManage' => $canManage,
            'locations' => $locations,
            'showLocations' => $showLocations,
            'report' => $report,
            'reportLocation' => $locations->firstWhere('id', (int) ($report->ext_location_id ?? 0)),
            'members' => ! $exposure && $canManage
                ? ExposureService::manageableMembers($user, is_int($locationId) ? $locationId : null)
                : collect(),
        ]);
    }
}
