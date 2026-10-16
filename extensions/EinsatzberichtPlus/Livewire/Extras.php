<?php

namespace Extensions\EinsatzberichtPlus\Livewire;

use Extensions\EinsatzberichtPlus\Models\EarningLoss;
use Extensions\EinsatzberichtPlus\Models\InvolvedPerson;
use Extensions\EinsatzberichtPlus\Models\ReportExtra;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ReportService;

/** Kostenpflicht, beteiligte Personen, Verdienstausfall and external vehicles of one Einsatzbericht */
class Extras extends Component
{
    #[Locked]
    public int $reportId;

    public bool $chargeable = false;

    public string $chargeableTo = '';

    public string $chargeableNote = '';

    /** @var array<string, string> */
    public array $person = ['role' => 'betroffen', 'name' => '', 'contact' => '', 'note' => ''];

    /** @var array<string, string> */
    public array $loss = ['user' => '', 'employer' => '', 'hours' => '', 'note' => ''];

    public string $externalVehicle = '';

    public function mount(int $reportId): void
    {
        $this->reportId = $reportId;
        $report = $this->report();
        $extra = ReportExtra::where('report_id', $report->id)->first();
        $this->chargeable = (bool) $extra?->chargeable;
        $this->chargeableTo = (string) $extra?->chargeable_to;
        $this->chargeableNote = (string) $extra?->chargeable_note;
    }

    /** Loaded through the (location scoped) model and checked against the editing right of the report */
    private function report(): Report
    {
        $report = Report::findOrFail($this->reportId);
        abort_unless(ReportService::canLead(auth()->user()), 403);

        return $report;
    }

    private function editable(): Report
    {
        $report = $this->report();
        abort_unless(ReportService::canEdit(auth()->user(), $report), 403);

        return $report;
    }

    public function saveCharge(): void
    {
        $report = $this->editable();
        $data = $this->validate([
            'chargeable' => ['boolean'],
            'chargeableTo' => ['nullable', 'string', 'max:150'],
            'chargeableNote' => ['nullable', 'string', 'max:2000'],
        ]);
        ReportExtra::updateOrCreate(['report_id' => $report->id], [
            'chargeable' => $data['chargeable'],
            'chargeable_to' => $data['chargeableTo'] ?: null,
            'chargeable_note' => $data['chargeableNote'] ?: null,
        ]);
        session()->flash('ebp-saved', 'Gespeichert.');
    }

    public function addPerson(): void
    {
        $report = $this->editable();
        $data = $this->validate([
            'person.role' => ['required', Rule::in(array_keys(InvolvedPerson::ROLES))],
            'person.name' => ['required', 'string', 'max:150'],
            'person.contact' => ['nullable', 'string', 'max:500'],
            'person.note' => ['nullable', 'string', 'max:2000'],
        ])['person'];
        InvolvedPerson::create([
            'report_id' => $report->id, 'role' => $data['role'], 'name' => $data['name'],
            'contact' => $data['contact'] ?: null, 'note' => $data['note'] ?: null,
        ]);
        $this->person = ['role' => 'betroffen', 'name' => '', 'contact' => '', 'note' => ''];
    }

    public function removePerson(int $id): void
    {
        $report = $this->editable();
        InvolvedPerson::where('report_id', $report->id)->whereKey($id)->delete();
    }

    public function addLoss(): void
    {
        $report = $this->editable();
        $crewIds = $this->crewUsers($report)->keys()->all();
        $this->loss['hours'] = str_replace(',', '.', trim($this->loss['hours']));
        $data = $this->validate([
            'loss.user' => ['required', Rule::in($crewIds)],
            'loss.employer' => ['nullable', 'string', 'max:150'],
            'loss.hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'loss.note' => ['nullable', 'string', 'max:2000'],
        ], ['loss.user.required' => 'Bitte eine Einsatzkraft wählen.', 'loss.user.in' => 'Nur Einsatzkräfte dieses Berichts.'])['loss'];
        EarningLoss::updateOrCreate(['report_id' => $report->id, 'user_id' => (int) $data['user']], [
            'employer' => $data['employer'] ?: null,
            'hours' => $data['hours'] === '' ? null : $data['hours'],
            'note' => $data['note'] ?: null,
        ]);
        $this->loss = ['user' => '', 'employer' => '', 'hours' => '', 'note' => ''];
    }

    public function setLossStatus(int $id, string $status): void
    {
        $report = $this->editable();
        abort_unless(array_key_exists($status, EarningLoss::STATUS), 422);
        EarningLoss::where('report_id', $report->id)->whereKey($id)->update(['status' => $status]);
    }

    public function removeLoss(int $id): void
    {
        $report = $this->editable();
        EarningLoss::where('report_id', $report->id)->whereKey($id)->delete();
    }

    /** A vehicle that is not in the list (other Wehr, Dritte): free text, counts as a vehicle with crew */
    public function addExternalVehicle(): void
    {
        $report = $this->editable();
        $name = $this->validate(['externalVehicle' => ['required', 'string', 'max:100']], ['externalVehicle.required' => 'Bitte eine Bezeichnung eingeben.'])['externalVehicle'];
        $report->vehicles()->create([
            'vehicle_id' => null, 'name' => $name, 'is_standby' => false,
            'sort_order' => (int) $report->vehicles()->max('sort_order') + 1,
            'departed_at' => $report->departed_at, 'arrived_at' => $report->arrived_at,
        ]);
        $this->externalVehicle = '';
        $this->redirectRoute('einsatzbericht.show', ['report' => $report->id]);
    }

    /** @return Collection<int, string> crew of the report by user id */
    private function crewUsers(Report $report)
    {
        $report->loadMissing('vehicles.crew.user');

        return $report->vehicles->flatMap->crew->filter(fn ($c) => $c->user)->mapWithKeys(fn ($c) => [$c->user_id => $c->user->name])->sort();
    }

    public function render(): View
    {
        $report = $this->report();

        return view('ebp::livewire.extras', [
            'canEdit' => ReportService::canEdit(auth()->user(), $report),
            'persons' => InvolvedPerson::where('report_id', $report->id)->orderBy('id')->get(),
            'losses' => EarningLoss::with('user')->where('report_id', $report->id)->orderBy('id')->get(),
            'crew' => $this->crewUsers($report),
        ]);
    }
}
