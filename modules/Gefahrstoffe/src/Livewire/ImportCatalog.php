<?php

namespace Modules\Gefahrstoffe\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Gefahrstoffe\src\Services\MaterialImport as MaterialImporter;

class ImportCatalog extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    public string $sourceName = '';

    public string $sourceVersion = '';

    public string $sourceReference = '';

    public ?array $result = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gefahrstoffe-manage'), 403);
    }

    public function import(MaterialImporter $importer): void
    {
        $this->resetErrorBag();
        $this->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,json,txt'],
            'sourceName' => ['required', 'string', 'max:255'],
            'sourceVersion' => ['nullable', 'string', 'max:100'],
            'sourceReference' => ['nullable', 'string', 'max:2000'],
        ], [
            'file.required' => 'Bitte wähle eine CSV- oder JSON-Datei.',
            'file.max' => 'Die Datei darf höchstens 5 MB groß sein.',
            'file.mimes' => 'Bitte wähle eine CSV- oder JSON-Datei.',
            'sourceName.required' => 'Bitte gib die Quelle des Datenbestands an.',
        ]);

        try {
            $contents = file_get_contents($this->file->getRealPath());
            if ($contents === false) {
                throw ValidationException::withMessages(['file' => 'Die Datei konnte nicht gelesen werden.']);
            }
            $this->result = $importer->apply($contents, $this->sourceName, $this->sourceVersion, $this->sourceReference, (int) auth()->id());
        } catch (ValidationException $e) {
            $this->addError('import', collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('file');
    }

    public function render(MaterialImporter $importer)
    {
        $rows = [];
        if ($this->file) {
            try {
                $contents = file_get_contents($this->file->getRealPath());
                if ($contents !== false) {
                    $rows = $importer->preview($contents);
                }
            } catch (ValidationException $e) {
                $this->addError('import', collect($e->errors())->flatten()->first());
            }
        }

        return view('gefahrstoffe::livewire.import-catalog', ['rows' => collect($rows)]);
    }
}
