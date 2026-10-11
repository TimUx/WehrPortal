<?php

namespace Modules\Wasserkarte\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Wasserkarte\src\Models\Defect;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Services\WaterService;

/** One source: data, status, defects (report for everyone), inspections and history */
class SourceDetail extends Component
{
    use WithFileUploads;

    public Source $source;

    public string $status = '';

    public string $statusNote = '';

    public string $defectDescription = '';

    public ?TemporaryUploadedFile $defectPhoto = null;

    /** @var array<string, string> */
    public array $inspection = [];

    public string $resolution = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->flash = session('wasserkarte.flash');
        $this->status = $this->source->status;
        $this->resetInspection();
    }

    public function reportDefect(WaterService $water): void
    {
        $this->validate(['defectPhoto' => ['nullable', 'image', 'max:10240']], ['defectPhoto.*' => 'Bitte ein Foto bis 10 MB.']);
        $this->run(fn () => $water->reportDefect(auth()->user(), $this->source, $this->defectDescription, $this->defectPhoto),
            'Danke! Der Mangel ist gemeldet, die Wasserquelle steht auf „defekt“.');
        if (! $this->getErrorBag()->any()) {
            $this->reset('defectDescription', 'defectPhoto');
        }
    }

    public function resolveDefect(int $id, WaterService $water): void
    {
        $this->run(fn () => $water->resolveDefect(auth()->user(), Defect::where('source_id', $this->source->id)->findOrFail($id), $this->resolution), 'Als behoben eingetragen.');
        $this->resolution = '';
    }

    public function saveStatus(WaterService $water): void
    {
        $this->run(fn () => $water->setStatus(auth()->user(), $this->source, $this->status, $this->statusNote), 'Status gespeichert.');
        $this->statusNote = '';
    }

    public function saveInspection(WaterService $water): void
    {
        if ($this->run(fn () => $water->recordInspection(auth()->user(), $this->source, $this->inspection), 'Prüfung eingetragen.')) {
            $this->resetInspection();
        }
    }

    private function resetInspection(): void
    {
        $this->inspection = ['inspected_on' => now('Europe/Berlin')->toDateString(), 'result' => 'passed', 'flow_lpm' => '', 'static_pressure_bar' => '', 'flow_pressure_bar' => '', 'notes' => ''];
    }

    private function run(callable $action, string $done): bool
    {
        $this->resetErrorBag();
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->flash = $done;
        $this->source->refresh();
        $this->status = $this->source->status;

        return true;
    }

    public function render()
    {
        return view('wasserkarte::livewire.source-detail', [
            'defects' => $this->source->defects()->with(['reporter', 'resolver'])->get(),
            'inspections' => $this->source->inspections()->with('user')->limit(20)->get(),
            'events' => $this->source->events()->with('user')->limit(15)->get(),
            'canManage' => WaterService::canManage(auth()->user()),
        ]);
    }
}
