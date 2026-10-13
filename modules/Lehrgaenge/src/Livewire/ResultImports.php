<?php

namespace Modules\Lehrgaenge\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Lehrgaenge\src\Services\ResultImport;

/** Ausbildungsleiter: import the results of an external Träger, with a preview */
class ResultImports extends Component
{
    use WithFileUploads;

    public $file = null;

    public string $csv = '';

    public string $source = '';

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->canManageLehrgaenge(), 403);
    }

    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'max:2048']], ['file.max' => 'Die Datei ist zu groß (höchstens 2 MB).']);
        $this->csv = (string) file_get_contents($this->file->getRealPath());
        $this->source = $this->file->getClientOriginalName();
        $this->flash = null;
    }

    public function apply(ResultImport $import): void
    {
        try {
            $count = $import->apply($this->csv, $this->source);
        } catch (ValidationException $e) {
            $this->addError('import', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = $count.' Lehrgänge übernommen.';
        $this->reset('file', 'csv', 'source');
    }

    public function render(ResultImport $import)
    {
        $rows = [];
        if ($this->csv !== '') {
            try {
                $rows = $import->preview($this->csv);
            } catch (ValidationException $e) {
                $this->addError('import', collect($e->errors())->flatten()->first());
            }
        }

        return view('lehrgaenge::livewire.result-imports', ['rows' => collect($rows)]);
    }
}
