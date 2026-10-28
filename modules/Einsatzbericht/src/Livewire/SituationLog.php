<?php

namespace Modules\Einsatzbericht\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\SituationMessage;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Einsatzbericht\src\Services\SituationService;

/** The Einsatztagebuch: write a Lagemeldung with photos, see all of them; refreshes itself */
class SituationLog extends Component
{
    use WithFileUploads;

    public Report $report;

    public string $text = '';

    public bool $important = false;

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(SituationService::canSee(auth()->user(), $this->report), 403);
    }

    public function send(SituationService $situations): void
    {
        $this->validate([
            'text' => ['nullable', 'string', 'max:2000'],
            'photos' => ['array', 'max:'.SituationService::MAX_PHOTOS],
            'photos.*' => ['image', 'max:'.SituationService::MAX_PHOTO_KB],
        ], [
            'photos.max' => 'Höchstens '.SituationService::MAX_PHOTOS.' Fotos je Meldung.',
            'photos.*.image' => 'Bitte nur Fotos (JPG, PNG, WebP) anhängen.',
            'photos.*.max' => 'Ein Foto ist größer als 15 MB.',
        ]);

        try {
            $situations->post(auth()->user(), $this->report, $this->text, $this->important, $this->photos);
        } catch (ValidationException $e) {
            $this->addError('message', collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('text', 'important', 'photos');
        $this->flash = 'Lagemeldung gesendet.';
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function toggleImportant(int $messageId, SituationService $situations): void
    {
        $this->attempt(fn () => $situations->toggleImportant(auth()->user(), $this->message($messageId)));
    }

    public function delete(int $messageId, SituationService $situations): void
    {
        $this->attempt(fn () => $situations->delete(auth()->user(), $this->message($messageId)));
    }

    public function render()
    {
        return view('einsatzbericht::livewire.situation-log', [
            'messages' => $this->report->messages()->with(['user', 'externalUnit', 'photos:id,message_id'])->get(),
            'lead' => ReportService::canLeadReport(auth()->user(), $this->report),
            'running' => $this->report->isRunning(),
        ]);
    }

    private function message(int $id): SituationMessage
    {
        return $this->report->messages()->findOrFail($id);
    }

    private function attempt(callable $change): void
    {
        try {
            $change();
        } catch (ValidationException $e) {
            $this->addError('message', collect($e->errors())->flatten()->first());
        }
    }
}
