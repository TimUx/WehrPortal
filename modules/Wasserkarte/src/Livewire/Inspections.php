<?php

namespace Modules\Wasserkarte\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Wasserkarte\src\Models\Defect;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Services\WaterService;

/** For the Hydrantenwart: inspections due, open defects, CSV import of the Wasserversorger's list */
class Inspections extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $csv = null;

    public ?array $importResult = null;

    public function import(WaterService $water): void
    {
        $this->resetErrorBag();
        $this->validate(['csv' => ['required', 'file', 'max:5120', 'mimes:csv,txt']], ['csv.required' => 'Bitte eine CSV-Datei wählen.', 'csv.*' => 'Bitte eine CSV-Datei bis 5 MB.']);
        try {
            $this->importResult = $water->import(auth()->user(), (string) file_get_contents($this->csv->getRealPath()));
        } catch (ValidationException $e) {
            $this->addError('csv', collect($e->errors())->flatten()->first());
        }
        $this->csv = null;
    }

    public function render()
    {
        $limit = now('Europe/Berlin')->addDays(30)->toDateString();

        return view('wasserkarte::livewire.inspections', [
            'due' => Source::active()->whereNotNull('next_inspection_on')->where('next_inspection_on', '<=', $limit)->orderBy('next_inspection_on')->get(),
            'defects' => Defect::with(['source', 'reporter'])->whereNull('resolved_at')->latest('id')->get(),
            'never' => Source::active()->whereNull('last_inspected_on')->whereIn('kind', ['unterflur', 'ueberflur'])->count(),
        ]);
    }
}
