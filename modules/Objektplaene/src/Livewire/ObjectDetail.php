<?php

namespace Modules\Objektplaene\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Objektplaene\src\Models\Document;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Services\ObjectPlanService;

/** One object: what to know on arrival, its plans (current version), history; uploads for Planverwalter */
class ObjectDetail extends Component
{
    use WithFileUploads;

    public PlanObject $object;

    #[Url]
    public bool $kiosk = false;

    /** New document or new version of document $versionFor */
    public ?string $mode = null;

    public ?int $versionFor = null;

    public array $doc = ['kind' => 'feuerwehrplan', 'title' => '', 'note' => '', 'valid_from' => ''];

    public $file = null;

    public string $reviewNote = '';

    public ?int $historyFor = null;

    public ?string $flash = null;

    public function startDocument(): void
    {
        $this->resetErrorBag();
        $this->mode = 'document';
        $this->versionFor = null;
        $this->doc = ['kind' => 'feuerwehrplan', 'title' => '', 'note' => '', 'valid_from' => ''];
        $this->file = null;
    }

    public function startVersion(int $documentId): void
    {
        $this->resetErrorBag();
        $document = $this->document($documentId);
        $this->mode = 'version';
        $this->versionFor = $document->id;
        $this->doc = ['kind' => $document->kind, 'title' => $document->title, 'note' => '', 'valid_from' => ''];
        $this->file = null;
    }

    public function cancel(): void
    {
        $this->mode = null;
        $this->versionFor = null;
        $this->file = null;
    }

    public function saveDocument(ObjectPlanService $plans): void
    {
        $this->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']], [
            'file.required' => 'Bitte eine Datei wählen.', 'file.max' => 'Die Datei darf höchstens 10 MB groß sein.', 'file.mimes' => 'Bitte eine PDF-Datei oder ein Bild hochladen.',
        ]);

        $done = $this->run(fn () => $this->mode === 'version'
            ? $plans->uploadVersion(auth()->user(), $this->document($this->versionFor), $this->file, $this->doc['note'], $this->doc['valid_from'] ?: null)
            : $plans->addDocument(auth()->user(), $this->object, $this->doc['kind'], $this->doc['title'], $this->file, $this->doc['note'], $this->doc['valid_from'] ?: null),
            $this->mode === 'version' ? 'Neue Version gespeichert – die bisherige bleibt im Verlauf.' : 'Unterlage gespeichert.');
        if ($done) {
            $this->cancel();
        }
    }

    public function renameDocument(int $documentId, ObjectPlanService $plans): void
    {
        $this->run(fn () => $plans->updateDocument(auth()->user(), $this->document($documentId), $this->doc['kind'], $this->doc['title']), 'Gespeichert.');
        $this->cancel();
    }

    public function deleteDocument(int $documentId, ObjectPlanService $plans): void
    {
        $this->run(fn () => $plans->deleteDocument(auth()->user(), $this->document($documentId)), 'Unterlage mit allen Versionen gelöscht.');
    }

    public function review(ObjectPlanService $plans): void
    {
        if ($this->run(fn () => $plans->review(auth()->user(), $this->object, $this->reviewNote), 'Als geprüft vermerkt.')) {
            $this->reviewNote = '';
            $this->object->refresh();
        }
    }

    public function deleteObject(ObjectPlanService $plans)
    {
        if ($this->run(fn () => $plans->deleteObject(auth()->user(), $this->object), 'Gelöscht.')) {
            return redirect()->route('objektplaene.index');
        }

        return null;
    }

    public function toggleHistory(int $documentId): void
    {
        $this->historyFor = $this->historyFor === $documentId ? null : $documentId;
    }

    private function document(?int $id): Document
    {
        return $this->object->documents()->findOrFail($id);
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

        return true;
    }

    public function render()
    {
        $this->object->load(['documents.current.uploader', 'reviews.user']);

        return view('objektplaene::livewire.object-detail', [
            'manage' => ObjectPlanService::canManage(auth()->user()),
            'history' => $this->historyFor ? $this->object->documents->firstWhere('id', $this->historyFor)?->versions()->with('uploader')->get() : null,
        ]);
    }
}
