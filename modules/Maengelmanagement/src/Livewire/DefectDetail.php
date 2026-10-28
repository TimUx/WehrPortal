<?php

namespace Modules\Maengelmanagement\src\Livewire;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Services\DefectPhotoService;
use Modules\Maengelmanagement\src\Services\DefectService;

/**
 * One defect with photos and history, open to every member of the module: comment with photos,
 * answer a Rückfrage, reopen it as the reporter. Verantwortliche and the Zuständige work on it
 * right here – status, urgency, Zuständigkeit, Rückfrage – without the admin area.
 */
class DefectDetail extends Component
{
    use WithFileUploads;

    public Defect $defect;

    public string $comment = '';

    /** comment | question | reopen */
    public string $mode = 'comment';

    /** @var array<int, TemporaryUploadedFile> */
    public array $photos = [];

    public string $status = '';

    public string $urgency = '';

    public string $assignee = '';

    public string $note = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->fillWork();
    }

    public function send(DefectService $service): void
    {
        $this->validate([
            'comment' => ['required', 'string', 'max:3000'],
            'photos' => ['array', 'max:'.DefectPhotoService::MAX_PHOTOS],
            'photos.*' => ['image', 'max:12288'],
        ], [
            'comment.required' => match ($this->mode) {
                'question' => 'Bitte die Rückfrage formulieren.', 'reopen' => 'Bitte kurz sagen, was noch nicht passt.', default => 'Bitte schreib einen Kommentar.'
            },
            'photos.max' => 'Höchstens '.DefectPhotoService::MAX_PHOTOS.' Fotos.',
            'photos.*.image' => 'Bitte nur Bilder hochladen.',
        ]);
        $paths = collect($this->photos)->map(fn ($p) => $p->getRealPath())->all();
        try {
            match ($this->mode) {
                'question' => $service->ask($this->defect, auth()->user(), trim($this->comment), $paths),
                'reopen' => $service->reopen($this->defect, auth()->user(), trim($this->comment), $paths),
                default => $service->comment($this->defect, auth()->user(), trim($this->comment), $paths),
            };
        } catch (ValidationException $e) {
            $this->addError('comment', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = match ($this->mode) {
            'question' => 'Rückfrage gestellt – der Melder wird benachrichtigt.', 'reopen' => 'Wieder geöffnet.', default => null
        };
        $this->reset('comment', 'photos', 'mode');
        $this->defect->refresh();
        $this->fillWork();
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    /** Status, urgency and Zuständigkeit in one go, each change in the history */
    public function saveWork(DefectService $service): void
    {
        abort_unless(DefectService::canEdit(auth()->user(), $this->defect), 403);
        $this->validate([
            'status' => ['required', Rule::enum(DefectStatus::class)],
            'urgency' => ['required', Rule::enum(Urgency::class)],
            'assignee' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);
        $by = auth()->user();
        $service->setUrgency($this->defect, $by, Urgency::from($this->urgency));
        if ((string) $this->defect->assigned_to !== $this->assignee) {
            $service->assign($this->defect, $by, $this->assignee !== '' ? User::find((int) $this->assignee) : null);
        }
        $service->setStatus($this->defect->refresh(), $by, DefectStatus::from($this->status), trim($this->note) ?: null);
        $this->reset('note');
        $this->defect->refresh();
        $this->fillWork();
        $this->flash = 'Gespeichert.';
    }

    public function takeOver(DefectService $service): void
    {
        abort_unless(DefectService::canEdit(auth()->user(), $this->defect), 403);
        $service->assign($this->defect, auth()->user(), auth()->user());
        if ($this->defect->status === DefectStatus::Open) {
            $service->setStatus($this->defect, auth()->user(), DefectStatus::InProgress);
        }
        $this->defect->refresh();
        $this->fillWork();
        $this->flash = 'Du bist jetzt zuständig.';
    }

    private function fillWork(): void
    {
        $this->status = $this->defect->status->value;
        $this->urgency = $this->defect->urgency->value;
        $this->assignee = (string) ($this->defect->assigned_to ?? '');
    }

    public function render()
    {
        $user = auth()->user();
        $canEdit = DefectService::canEdit($user, $this->defect);

        return view('maengelmanagement::livewire.defect-detail', [
            'reportPhotos' => $this->defect->photos()->get(['id']),
            'events' => $this->defect->events()->with(['user', 'photos:id,event_id'])->get(),
            'canEdit' => $canEdit,
            'canManage' => $user->canManageMaengel(),
            'isReporter' => $user->id === $this->defect->reporter_id,
            'canReopen' => $this->defect->canReopen($user),
            'people' => $canEdit ? User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', 'maengelmanagement'))
                ->orWhere(fn ($q) => $q->where('is_admin', true)->where('is_active', true))->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
