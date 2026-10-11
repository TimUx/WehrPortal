<?php

namespace Modules\Wartung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Models\Provider;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Models\Template;
use Modules\Wartung\src\Services\WartungService;

/**
 * One device from the Wartung's view: Prüffrist and DGUV lifetime, the templates that apply with
 * their next date, open Wartungen, all protocols; the Gerätewart plans an ad hoc Wartung here.
 */
class DeviceDetail extends Component
{
    public Equipment $equipment;

    public string $templateId = '';

    public string $dueOn = '';

    public string $providerId = '';

    public string $notes = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->dueOn = WartungService::today()->toDateString();
    }

    public function plan(WartungService $service): void
    {
        $this->resetErrorBag();
        try {
            $service->planAdHoc(auth()->user(), $this->equipment, $this->templateId ? Template::find((int) $this->templateId) : null,
                $this->dueOn, $this->providerId ? (int) $this->providerId : null, $this->notes);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->flash = 'Wartung geplant.';
        $this->reset('templateId', 'providerId', 'notes');
    }

    public function render(WartungService $service)
    {
        $equipment = $this->equipment->load('category');
        $templates = Template::active()->get()->filter(fn (Template $t) => $t->devices()->whereKey($equipment->id)->exists())->values();

        return view('wartung::livewire.device-detail', [
            'templates' => $templates->map(fn (Template $t) => ['template' => $t, 'next' => $service->nextDue($t, $equipment)]),
            'tasks' => Task::open()->where('equipment_id', $equipment->id)->with(['template', 'provider'])->orderBy('due_on')->get(),
            'protocols' => Protocol::where('equipment_id', $equipment->id)->orderByDesc('performed_on')->orderByDesc('id')->get(),
            'allTemplates' => Template::active()->orderBy('name')->get(['id', 'name']),
            'providers' => Provider::active()->orderBy('name')->get(['id', 'name']),
            'canManage' => WartungService::canManage(auth()->user()),
            'canPerform' => WartungService::canPerform(auth()->user()),
        ]);
    }
}
