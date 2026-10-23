<?php

namespace Extensions\Anwesenheit\Livewire;

use Extensions\Anwesenheit\Models\AttendanceFile;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Services\AttendanceService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create or edit an Anwesenheitsliste. The fields marked data-offline-draft-field are kept as a local
 * draft by the Offline extension; nothing is sent in the background, the person submits it as usual.
 */
class ListForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $listId = null;

    /** @var array<string, mixed> times as typed (Y-m-d\TH:i, Berlin) */
    public array $form = [];

    /** @var array<int, bool> user id => present */
    public array $selected = [];

    /** @var array<int, mixed> */
    public array $uploads = [];

    public string $prefill = '';

    public string $mode = 'submit';

    public string $search = '';

    public function mount(?AttendanceList $list = null): void
    {
        $user = auth()->user();
        $service = app(AttendanceService::class);

        if ($list?->exists) {
            abort_unless(AttendanceService::canEdit($user, $list), 403);
            $this->listId = $list->id;
            $this->form = [
                'title' => $list->title, 'kind' => $list->kind, 'ext_location_id' => $list->ext_location_id ? (string) $list->ext_location_id : '',
                'starts_at' => $list->starts_at?->timezone('Europe/Berlin')->format('Y-m-d\TH:i') ?? '',
                'ends_at' => $list->ends_at?->timezone('Europe/Berlin')->format('Y-m-d\TH:i') ?? '',
                'leader_id' => $list->leader_id ? (string) $list->leader_id : '', 'guests' => (string) $list->guests, 'notes' => (string) $list->notes,
                'recipients' => (string) $list->recipients, 'template_id' => $list->template_id, 'source_event_id' => $list->source_event_id,
            ];
            $this->selected = $list->participants()->pluck('users.id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();

            return;
        }

        $this->form = [
            'title' => '', 'kind' => 'uebung', 'ext_location_id' => (string) ($service->defaultLocation($user) ?? ''),
            'starts_at' => '', 'ends_at' => '', 'leader_id' => '', 'guests' => '0', 'notes' => '',
            'recipients' => AttendanceService::defaultRecipients(), 'template_id' => null, 'source_event_id' => null,
        ];
    }

    /** Only a convenience: copies title, times, leader … into the form; the list stays standalone */
    public function applyPrefill(): void
    {
        $data = $this->prefill !== '' ? app(AttendanceService::class)->prefill(auth()->user(), $this->prefill) : null;
        if ($data === null) {
            return;
        }

        foreach ($data as $key => $value) {
            if ($value !== null && $value !== '') {
                $this->form[$key] = is_int($value) && in_array($key, ['leader_id'], true) ? (string) $value : $value;
            }
        }
        if (! empty($data['leader_id'])) {
            $this->selected[(int) $data['leader_id']] = true;
        }
        $this->prefill = '';
    }

    public function removeFile(int $fileId, AttendanceService $service): void
    {
        $file = AttendanceFile::where('list_id', $this->listId)->findOrFail($fileId);
        $service->removeFile(auth()->user(), $file);
    }

    public function save(AttendanceService $service)
    {
        $submit = $this->mode !== 'draft';
        $list = $this->listId ? AttendanceList::findOrFail($this->listId) : null;
        $ids = collect($this->selected)->filter()->keys()->map(fn ($id) => (int) $id)->all();

        $this->resetValidation();
        $list = $service->save(auth()->user(), $list, $this->form, $ids, $this->uploads, $submit);
        $this->uploads = [];

        if (! $submit) {
            session()->flash('flash', 'Entwurf gespeichert.');

            return $this->redirectRoute('anwesenheit.edit', $list, navigate: false);
        }

        $sent = $service->send(auth()->user(), $list);
        $recipients = AttendanceService::recipientsFor($list);
        session()->flash('flash', 'Liste abgeschlossen.'.($sent ? ' Die E-Mail mit PDF wurde versendet.' : ($recipients === [] ? ' Es ist kein E-Mail-Empfänger hinterlegt.' : ' Die E-Mail konnte nicht gesendet werden; sie lässt sich auf der Listenseite erneut senden.')));

        return $this->redirectRoute('anwesenheit.show', $list, navigate: false);
    }

    public function render()
    {
        $user = auth()->user();
        $service = app(AttendanceService::class);
        $locationId = (int) ($this->form['ext_location_id'] ?? 0) ?: null;
        $list = $this->listId ? AttendanceList::with('files')->find($this->listId) : null;

        return view('anwesenheit::livewire.list-form', [
            'kinds' => config('anwesenheit.kinds'),
            'locations' => $service->locationsFor($user),
            'members' => $service->members($locationId),
            'prefillOptions' => $list ? [] : $service->prefillOptions($user),
            'files' => $list?->files ?? collect(),
            'maxFiles' => config('anwesenheit.max_files'),
        ]);
    }
}
