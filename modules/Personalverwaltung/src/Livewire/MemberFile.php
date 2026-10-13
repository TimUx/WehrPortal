<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** The Personalakte of one Kamerad for the Personalverwaltung: data, career, Ehrungen, Dienstausweis, Dienstbeteiligung */
class MemberFile extends Component
{
    public User $member;

    public array $idCard = ['number' => '', 'valid_until' => ''];

    public array $stage = ['kind' => 'rank', 'label' => '', 'since' => '', 'until' => '', 'note' => ''];

    public ?int $editing = null;

    public array $edit = ['label' => '', 'since' => '', 'until' => '', 'note' => ''];

    public array $honor = ['honor_type_id' => '', 'title' => '', 'awarded_on' => '', 'awarded_by' => '', 'note' => ''];

    /** Switched-on personal data (Anschrift, Geburtsdatum, Mobilnummer) */
    public array $data = [];

    public ?string $flash = null;

    public function mount(PersonnelService $personnel): void
    {
        abort_unless(PersonnelService::mayView(auth()->user(), $this->member), 403);
        $personnel->syncHistory($this->member);
        $profile = MemberProfile::find($this->member->id);
        $this->idCard = ['number' => (string) $profile?->id_card_number, 'valid_until' => (string) $profile?->id_card_valid_until?->toDateString()];
        $this->honor['awarded_on'] = PersonnelService::today()->toDateString();
        $m = $this->member;
        $this->data = ['street' => (string) $m->street, 'postal_code' => (string) $m->postal_code, 'city' => (string) $m->city,
            'birth_date' => (string) $m->birth_date?->toDateString(), 'phone' => (string) $m->phone];
    }

    public function savePersonalData(PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->savePersonalData(auth()->user(), $this->member, $this->data), 'Persönliche Daten gespeichert.');
    }

    public function saveIdCard(PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->saveIdCard(auth()->user(), $this->member, $this->idCard['number'], $this->idCard['valid_until'] ?: null), 'Dienstausweis gespeichert.');
    }

    public function addStage(PersonnelService $personnel): void
    {
        if ($this->run(fn () => $personnel->addHistory(auth()->user(), $this->member, $this->stage), 'Frühere Station nachgetragen.')) {
            $this->stage = ['kind' => $this->stage['kind'], 'label' => '', 'since' => '', 'until' => '', 'note' => ''];
        }
    }

    public function startEdit(int $id): void
    {
        $entry = $this->entry($id);
        $this->editing = $entry->id;
        $this->edit = ['label' => $entry->label, 'since' => (string) $entry->since?->toDateString(), 'until' => (string) $entry->until?->toDateString(), 'note' => (string) $entry->note];
    }

    public function saveEdit(PersonnelService $personnel): void
    {
        if ($this->run(fn () => $personnel->updateHistory(auth()->user(), $this->entry($this->editing), $this->edit), 'Gespeichert.')) {
            $this->editing = null;
        }
    }

    public function deleteStage(int $id, PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->deleteHistory(auth()->user(), $this->entry($id)), 'Station gelöscht.');
    }

    public function addHonor(PersonnelService $personnel): void
    {
        if ($this->run(fn () => $personnel->addHonor(auth()->user(), $this->member, $this->honor), 'Ehrung eingetragen.')) {
            $this->honor = ['honor_type_id' => '', 'title' => '', 'awarded_on' => PersonnelService::today()->toDateString(), 'awarded_by' => '', 'note' => ''];
        }
    }

    public function deleteHonor(int $id, PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->deleteHonor(auth()->user(), Honor::where('user_id', $this->member->id)->findOrFail($id)), 'Ehrung gelöscht.');
    }

    private function entry(?int $id): HistoryEntry
    {
        return HistoryEntry::where('user_id', $this->member->id)->findOrFail($id);
    }

    private function run(callable $action, string $done): bool
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->flash = $done;

        return true;
    }

    public function render(PersonnelService $personnel)
    {
        $member = $this->member->load(['groups', 'functions.parent']);
        $year = (int) now('Europe/Berlin')->year;

        return view('personalverwaltung::livewire.member-file', [
            'history' => HistoryEntry::where('user_id', $member->id)->orderByRaw('until is not null')->orderByDesc('since')->orderByDesc('id')->get()->groupBy('kind'),
            'honors' => Honor::where('user_id', $member->id)->orderByDesc('awarded_on')->get(),
            'types' => HonorType::orderBy('sort_order')->orderBy('name')->get(),
            'due' => $personnel->dueHonors(auth()->user(), $year, $member),
            'years' => PersonnelService::serviceYears($member),
            'attendance' => PersonnelService::attendanceAvailable()
                ? collect([$year, $year - 1, $year - 2])->mapWithKeys(fn ($y) => [$y => $personnel->attendance($member, $y)]) : collect(),
            'isAdmin' => auth()->user()->is_admin,
            'fields' => PersonnelService::activeFields(),
        ]);
    }
}
