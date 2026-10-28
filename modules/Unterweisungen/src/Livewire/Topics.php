<?php

namespace Modules\Unterweisungen\src\Livewire;

use App\Models\Group;
use App\Models\MemberFunction;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Unterweisungen\src\Models\Topic;
use Modules\Unterweisungen\src\Services\InstructionService;

/** The kinds of Unterweisung and Einweisung, with Kürzel, interval and who needs them */
class Topics extends Component
{
    public ?int $editing = null;

    public array $form = [];

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(InstructionService::canManage(auth()->user()), 403);
    }

    public function mount(): void
    {
        $this->blank();
    }

    private function blank(): void
    {
        $this->editing = null;
        $this->form = ['name' => '', 'short' => '', 'kind' => 'unterweisung', 'interval_months' => '12', 'vehicle_id' => '', 'counts_as' => InstructionService::isAtemschutzOnlyManager(auth()->user()) ? 'atemschutz' : '', 'description' => '',
            'for_all' => false, 'group_ids' => [], 'function_ids' => [], 'is_active' => true];
    }

    public function edit(int $id): void
    {
        $t = Topic::findOrFail($id);
        abort_unless(InstructionService::canManageTopic(auth()->user(), $t), 403);
        $this->editing = $t->id;
        $this->form = ['name' => $t->name, 'short' => (string) $t->short, 'kind' => $t->kind, 'interval_months' => (string) ($t->interval_months ?? ''),
            'vehicle_id' => (string) ($t->vehicle_id ?? ''), 'counts_as' => (string) $t->counts_as, 'description' => (string) $t->description, 'for_all' => $t->for_all,
            'group_ids' => array_map('strval', $t->group_ids ?? []), 'function_ids' => array_map('strval', $t->function_ids ?? []), 'is_active' => $t->is_active];
    }

    public function save(InstructionService $service): void
    {
        $this->resetErrorBag();
        try {
            $topic = $service->saveTopic(auth()->user(), $this->editing ? Topic::findOrFail($this->editing) : null, $this->form);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->flash = '„'.$topic->name.'“ gespeichert.';
        $this->blank();
    }

    public function presets(InstructionService $service): void
    {
        $count = $service->addPresets(auth()->user());
        $this->flash = $count ? $count.' übliche Unterweisungen angelegt – Intervalle und Zielgruppen bitte anpassen.' : 'Die üblichen Unterweisungen gibt es schon.';
    }

    public function cancel(): void
    {
        $this->blank();
    }

    public function render()
    {
        return view('unterweisungen::livewire.topics', [
            'topics' => InstructionService::topicsForManager(auth()->user())->with('vehicle')->withCount('sessions')->orderByDesc('is_active')->orderBy('name')->get(),
            'groups' => Group::options(),
            'functions' => MemberFunction::topLevel()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'canManageAll' => InstructionService::canManageAll(auth()->user()),
        ]);
    }
}
