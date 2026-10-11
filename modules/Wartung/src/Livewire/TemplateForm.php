<?php

namespace Modules\Wartung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Wartung\src\Models\Provider;
use Modules\Wartung\src\Models\Template;
use Modules\Wartung\src\Services\WartungService;

/** Create or change a Wartungsvorlage: checklist, interval and Voranzeige, which devices, who checks */
class TemplateForm extends Component
{
    public ?Template $template = null;

    public string $name = '';

    public string $description = '';

    public string $categoryId = '';

    /** @var list<int> */
    public array $equipmentIds = [];

    public string $deviceSearch = '';

    public string $intervalMonths = '12';

    public string $leadDays = '30';

    /** @var list<string> */
    public array $checklist = [];

    public string $newItem = '';

    public bool $countsAsInspection = true;

    public string $performer = 'internal';

    public string $providerId = '';

    public string $requiredQualification = '';

    public string $estimatedMinutes = '';

    public bool $isActive = true;

    public function mount(?Template $template = null): void
    {
        $this->template = $template?->exists ? $template : null;
        if ($this->template) {
            $t = $this->template;
            $this->fill([
                'name' => $t->name, 'description' => (string) $t->description, 'categoryId' => (string) ($t->category_id ?? ''),
                'equipmentIds' => $t->equipment()->pluck('ar_equipment.id')->all(), 'intervalMonths' => (string) ($t->interval_months ?? ''),
                'leadDays' => (string) $t->lead_days, 'checklist' => $t->checklist, 'countsAsInspection' => $t->counts_as_inspection,
                'performer' => $t->performer, 'providerId' => (string) ($t->provider_id ?? ''), 'requiredQualification' => (string) $t->required_qualification,
                'estimatedMinutes' => (string) ($t->estimated_minutes ?? ''), 'isActive' => $t->is_active,
            ]);
        }
    }

    /** The category's interval as a suggestion */
    public function updatedCategoryId(): void
    {
        $interval = Category::find((int) $this->categoryId)?->inspection_interval_months;
        if ($interval && ! $this->template) {
            $this->intervalMonths = (string) $interval;
        }
    }

    public function addItem(): void
    {
        foreach (preg_split('/\R/', $this->newItem) as $line) {
            if (trim($line) !== '') {
                $this->checklist[] = mb_substr(trim($line), 0, 200);
            }
        }
        $this->newItem = '';
    }

    public function removeItem(int $index): void
    {
        unset($this->checklist[$index]);
        $this->checklist = array_values($this->checklist);
    }

    public function moveItem(int $index, int $by): void
    {
        $to = $index + $by;
        if (isset($this->checklist[$index], $this->checklist[$to])) {
            [$this->checklist[$index], $this->checklist[$to]] = [$this->checklist[$to], $this->checklist[$index]];
        }
    }

    public function toggleDevice(int $id): void
    {
        $this->equipmentIds = in_array($id, $this->equipmentIds, true)
            ? array_values(array_diff($this->equipmentIds, [$id]))
            : [...$this->equipmentIds, $id];
    }

    public function save(WartungService $service)
    {
        $this->resetErrorBag();
        try {
            $template = $service->saveTemplate(auth()->user(), $this->template, [
                'name' => $this->name, 'description' => $this->description, 'category_id' => $this->categoryId ?: null, 'equipment_ids' => $this->equipmentIds,
                'interval_months' => $this->intervalMonths, 'lead_days' => $this->leadDays === '' ? 0 : $this->leadDays, 'checklist' => $this->checklist,
                'counts_as_inspection' => $this->countsAsInspection, 'performer' => $this->performer, 'provider_id' => $this->providerId ?: null,
                'required_qualification' => $this->requiredQualification, 'estimated_minutes' => $this->estimatedMinutes, 'is_active' => $this->isActive,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        session()->flash('wartung.flash', 'Vorlage „'.$template->name.'“ gespeichert.');

        return redirect()->route('wartung.templates');
    }

    public function delete(WartungService $service)
    {
        if ($this->template) {
            $deleted = $service->deleteTemplate(auth()->user(), $this->template);
            session()->flash('wartung.flash', $deleted ? 'Vorlage gelöscht.' : 'Die Vorlage hat schon Protokolle und wurde deshalb nur ausgeschaltet.');
        }

        return redirect()->route('wartung.templates');
    }

    public function render()
    {
        $term = trim($this->deviceSearch);

        return view('wartung::livewire.template-form', [
            'categories' => Category::orderBy('sort_order')->orderBy('name')->get(),
            'providers' => Provider::active()->orderBy('name')->get(['id', 'name']),
            'picked' => Equipment::whereIn('id', $this->equipmentIds)->orderBy('code')->get(['id', 'code', 'name']),
            'matches' => $term === '' ? collect() : Equipment::inService()
                ->where(fn ($q) => $q->where('name', 'ilike', '%'.$term.'%')->orWhere('code', 'ilike', '%'.$term.'%'))
                ->orderBy('code')->limit(20)->get(['id', 'code', 'name']),
            'categoryCount' => $this->categoryId !== '' ? Equipment::inService()->where('category_id', (int) $this->categoryId)->count() : 0,
        ]);
    }
}
