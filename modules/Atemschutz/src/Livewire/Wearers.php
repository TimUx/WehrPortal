<?php

namespace Modules\Atemschutz\src\Livewire;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;

/**
 * The Atemschutz pool for the Atemschutzwart: who is einsatzbereit and until when, G26.3 dates,
 * AGT-Lehrgang and RFID chip per Kamerad, Unterweisung or Belastungsübung for several at once,
 * and the basis of the calculation (cylinder, consumption, reserve).
 */
class Wearers extends Component
{
    #[Url]
    public string $filter = '';

    /** Abteilung, from the member data */
    #[Url]
    public string $group = '';

    public string $addUserId = '';

    public ?int $editing = null;

    /** @var array{is_agt: bool, agt_qualified_on: string, g263_examined_on: string, g263_valid_until: string, rfid_uid: string, notes: string, is_active: bool} */
    public array $form = [];

    public string $trainingKind = 'unterweisung';

    public string $trainingDate = '';

    public string $trainingNotes = '';

    /** @var list<int> */
    public array $selected = [];

    public string $liters = '';

    public string $consumption = '';

    public string $reserve = '';

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(MonitorService::canManage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->trainingDate = ReadinessService::today()->toDateString();
        $settings = MonitorService::settings();
        [$this->liters, $this->consumption, $this->reserve] = [str_replace('.', ',', (string) $settings['cylinder_liters']), (string) $settings['consumption_lpm'], (string) $settings['reserve_bar']];
    }

    public function addWearer(): void
    {
        $user = User::active()->find((int) $this->addUserId);
        if (! $user) {
            $this->addError('addUserId', 'Bitte einen Kameraden wählen.');

            return;
        }
        $wearer = Wearer::firstOrCreate(['user_id' => $user->id]);
        if (! $wearer->is_active) {
            $wearer->update(['is_active' => true]);
        }
        $this->addUserId = '';
        $this->edit($wearer->id);
    }

    public function edit(int $id): void
    {
        $this->resetErrorBag();
        $w = Wearer::findOrFail($id);
        $this->editing = $w->id;
        $this->form = [
            'is_agt' => $w->is_agt, 'agt_qualified_on' => (string) $w->agt_qualified_on?->toDateString(), 'g263_examined_on' => (string) $w->g263_examined_on?->toDateString(),
            'g263_valid_until' => (string) $w->g263_valid_until?->toDateString(), 'rfid_uid' => (string) $w->rfid_uid, 'notes' => (string) $w->notes, 'is_active' => $w->is_active,
        ];
    }

    /** G26.3 is usually valid for three years (under 50) – a suggestion when only the examination date is typed */
    public function updatedFormG263ExaminedOn(string $value): void
    {
        if ($value !== '' && ($this->form['g263_valid_until'] ?? '') === '') {
            try {
                $this->form['g263_valid_until'] = Carbon::parse($value)->addYears(3)->toDateString();
            } catch (\Throwable) {
            }
        }
    }

    public function save(): void
    {
        $wearer = Wearer::findOrFail($this->editing);
        $this->form['rfid_uid'] = trim($this->form['rfid_uid']);
        $data = $this->validate([
            'form.is_agt' => ['boolean'], 'form.agt_qualified_on' => ['nullable', 'date', 'before_or_equal:today'],
            'form.g263_examined_on' => ['nullable', 'date', 'before_or_equal:today'], 'form.g263_valid_until' => ['nullable', 'date', 'after_or_equal:form.g263_examined_on'],
            'form.rfid_uid' => ['nullable', 'string', 'max:100', Rule::unique('as_wearers', 'rfid_uid')->ignore($wearer->id)],
            'form.notes' => ['nullable', 'string', 'max:500'], 'form.is_active' => ['boolean'],
        ], [
            'form.rfid_uid.unique' => 'Dieser Chip gehört schon einem anderen Kameraden.', 'form.g263_valid_until.after_or_equal' => '„Gültig bis“ liegt vor der Untersuchung.',
            '*.before_or_equal' => 'Das Datum liegt in der Zukunft.', '*.date' => 'Bitte ein gültiges Datum angeben.',
        ])['form'];

        $wearer->update(collect($data)->map(fn ($v) => $v === '' ? null : $v)->all());
        $this->flash = 'Gespeichert: '.$wearer->user->name.'.';
        $this->editing = null;
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function selectAll(): void
    {
        $this->selected = Wearer::active()->pluck('user_id')->all();
    }

    public function recordTraining(): void
    {
        $this->resetErrorBag();
        $date = null;
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $this->trainingDate);
        } catch (\Throwable) {
        }
        $errors = array_filter([
            'trainingKind' => array_key_exists($this->trainingKind, Training::KINDS) ? null : 'Bitte die Art wählen.',
            'trainingDate' => ! $date ? 'Bitte ein gültiges Datum angeben.' : ($date->gt(ReadinessService::today()) ? 'Das Datum liegt in der Zukunft.' : null),
            'selected' => $this->selected === [] ? 'Bitte mindestens einen Geräteträger auswählen.' : null,
        ]);
        if ($errors) {
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }

        $ids = Wearer::whereIn('user_id', $this->selected)->pluck('user_id');
        foreach ($ids as $userId) {
            Training::create(['user_id' => $userId, 'kind' => $this->trainingKind, 'done_on' => $date->toDateString(),
                'notes' => trim($this->trainingNotes) !== '' ? mb_substr(trim($this->trainingNotes), 0, 300) : null, 'created_by' => auth()->id()]);
        }
        $this->flash = Training::KINDS[$this->trainingKind].' für '.$ids->count().' Geräteträger eingetragen.';
        $this->reset('selected', 'trainingNotes');
    }

    public function deleteTraining(int $id): void
    {
        Training::findOrFail($id)->delete();
        $this->flash = 'Eintrag gelöscht.';
    }

    public function saveSettings(MonitorService $monitor): void
    {
        $this->resetErrorBag();
        try {
            $monitor->saveSettings(auth()->user(), (float) str_replace(',', '.', $this->liters), (int) $this->consumption, (int) $this->reserve);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->flash = 'Berechnungsgrundlagen gespeichert. Sie gelten für neu registrierte Trupps.';
    }

    public function render(ReadinessService $readiness)
    {
        $wearers = Wearer::with('user.groups')
            ->when($this->group !== '', fn ($q) => $q->whereHas('user.groups', fn ($g) => $g->whereKey((int) $this->group)))->get();
        $states = $readiness->forWearers($wearers)
            ->filter(fn ($s) => match ($this->filter) {
                'ready' => $s['wearer']->is_active && $s['ready'] && ! $s['soon'],
                'soon' => $s['wearer']->is_active && $s['soon'],
                'not_ready' => $s['wearer']->is_active && ! $s['ready'],
                'inactive' => ! $s['wearer']->is_active,
                default => $s['wearer']->is_active,
            })
            ->sortBy(fn ($s) => $s['wearer']->user->name)->values();

        return view('atemschutz::livewire.wearers', [
            'states' => $states,
            'counts' => $readiness->poolCounts(),
            'groups' => Group::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'candidates' => User::active()->whereNotIn('id', Wearer::where('is_active', true)->pluck('user_id'))->orderBy('name')->get(['id', 'name']),
            'editingWearer' => $this->editing ? Wearer::with('user')->find($this->editing) : null,
            'trainings' => $this->editing ? Training::where('user_id', Wearer::find($this->editing)?->user_id)->latest('done_on')->limit(20)->get() : collect(),
        ]);
    }
}
