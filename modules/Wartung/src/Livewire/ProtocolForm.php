<?php

namespace Modules\Wartung\src\Livewire;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Wartung\src\Models\Provider;
use Modules\Wartung\src\Models\Qualification;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Models\Template;
use Modules\Wartung\src\Services\WartungService;

/**
 * Carry out a Wartung: from an open one (?aufgabe=), or ad hoc for a device (?geraet=, optionally
 * with ?vorlage=). Work through the checklist, note time, costs and photos, sign.
 */
class ProtocolForm extends Component
{
    use WithFileUploads;

    public ?int $taskId = null;

    public string $equipmentId = '';

    public string $equipmentSearch = '';

    public string $templateId = '';

    public string $title = '';

    public string $performedOn = '';

    public string $result = '';

    /** @var list<array{item: string, result: string, note: string}> */
    public array $checklist = [];

    public string $newItem = '';

    public string $findings = '';

    public string $minutes = '';

    public string $materialCost = '';

    public string $performer = 'internal';

    public string $qualificationId = '';

    public string $providerId = '';

    public string $technician = '';

    public string $inspectorNumber = '';

    public string $externalCost = '';

    public bool $countsAsInspection = false;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public string $signature = '';

    public function mount(): void
    {
        $this->performedOn = WartungService::today()->toDateString();
        $task = request()->filled('aufgabe') ? Task::open()->with('template')->find((int) request('aufgabe')) : null;

        if ($task) {
            $this->taskId = $task->id;
            $this->equipmentId = (string) $task->equipment_id;
            $this->templateId = (string) ($task->template_id ?? '');
            $this->title = $task->template ? '' : (string) $task->notes;
            $this->providerId = (string) ($task->provider_id ?? '');
        } else {
            $this->equipmentId = (string) (Equipment::inService()->find((int) request('geraet'))?->id ?? '');
            $this->templateId = (string) (Template::active()->find((int) request('vorlage'))?->id ?? '');
        }

        $this->applyTemplate();
        $own = Qualification::valid()->where('user_id', auth()->id())->get();
        $required = $this->template()?->required_qualification;
        $this->qualificationId = (string) (($required ? $own->first(fn ($q) => mb_strtolower($q->name) === mb_strtolower($required)) : $own->first())?->id ?? '');
    }

    public function updatedTemplateId(): void
    {
        $this->applyTemplate();
    }

    public function addItem(): void
    {
        if (trim($this->newItem) !== '' && ! $this->template()) {
            $this->checklist[] = ['item' => mb_substr(trim($this->newItem), 0, 200), 'result' => 'ok', 'note' => ''];
            $this->newItem = '';
        }
    }

    public function removeItem(int $index): void
    {
        if (! $this->template()) {
            unset($this->checklist[$index]);
            $this->checklist = array_values($this->checklist);
        }
    }

    public function removeFile(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function save(WartungService $service)
    {
        $this->resetErrorBag();
        $this->validate(['uploads.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf']], [
            'uploads.*.max' => 'Eine Datei darf höchstens 10 MB groß sein.', 'uploads.*.mimes' => 'Bitte Fotos (JPG, PNG) oder PDF-Dateien hochladen.',
        ]);

        $costs = ['material' => Money::parse($this->materialCost) ?? 0, 'external' => Money::parse($this->externalCost) ?? 0];
        foreach (['materialCost', 'externalCost'] as $field) {
            if (trim($this->{$field}) !== '' && Money::parse($this->{$field}) === null) {
                $this->addError('costs', 'Bitte Kosten als Betrag angeben, z. B. 12,50.');

                return null;
            }
        }

        try {
            $protocol = $service->recordProtocol(auth()->user(), [
                'task_id' => $this->taskId, 'template_id' => $this->templateId ?: null, 'equipment_id' => $this->equipmentId ?: null,
                'title' => $this->title, 'performed_on' => $this->performedOn, 'result' => $this->result, 'checklist' => $this->checklist,
                'findings' => $this->findings, 'minutes' => (int) $this->minutes, 'material_cost_cents' => $costs['material'],
                'performer' => $this->performer, 'qualification_id' => $this->qualificationId ?: null, 'provider_id' => $this->providerId ?: null,
                'technician' => $this->technician, 'inspector_number' => $this->inspectorNumber, 'external_cost_cents' => $costs['external'],
                'counts_as_inspection' => $this->countsAsInspection,
            ], $this->signature, $this->uploads);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        session()->flash('wartung.flash', 'Prüfprotokoll '.$protocol->code().' gespeichert und unterschrieben.');

        return redirect()->route('wartung.protocol', $protocol);
    }

    private function template(): ?Template
    {
        return $this->templateId !== '' ? Template::find((int) $this->templateId) : null;
    }

    /** The checklist, performer and estimate of the chosen template */
    private function applyTemplate(): void
    {
        $template = $this->template();
        if (! $template) {
            $this->checklist = [];
            $this->countsAsInspection = false;

            return;
        }

        $this->checklist = collect($template->checklist)->map(fn (string $item) => ['item' => $item, 'result' => '', 'note' => ''])->all();
        $this->performer = $template->performer;
        $this->providerId = $this->providerId ?: (string) ($template->provider_id ?? '');
        $this->minutes = $this->minutes ?: (string) ($template->estimated_minutes ?? '');
        $this->countsAsInspection = $template->counts_as_inspection;
    }

    public function render()
    {
        $term = trim($this->equipmentSearch);
        $equipment = $this->equipmentId !== '' ? Equipment::with('category')->find((int) $this->equipmentId) : null;

        return view('wartung::livewire.protocol-form', [
            'task' => $this->taskId ? Task::find($this->taskId) : null,
            'equipment' => $equipment,
            'template' => $this->template(),
            'matches' => $equipment || $term === '' ? collect() : Equipment::inService()
                ->where(fn ($q) => $q->where('name', 'ilike', '%'.$term.'%')->orWhere('code', 'ilike', '%'.$term.'%')->orWhere('serial_number', 'ilike', '%'.$term.'%'))
                ->orderBy('code')->limit(20)->get(),
            'templates' => Template::active()->orderBy('name')->get(['id', 'name']),
            'qualifications' => Qualification::valid()->where('user_id', auth()->id())->orderBy('name')->get(),
            'providers' => Provider::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
