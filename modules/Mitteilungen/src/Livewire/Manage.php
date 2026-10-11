<?php

namespace Modules\Mitteilungen\src\Livewire;

use App\Models\Group;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Services\AnnouncementService;

/** Write and edit the notices on the start page (Wehrführung, admins) */
class Manage extends Component
{
    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public bool $important = false;

    public string $visibleFrom = '';

    public string $visibleUntil = '';

    /** @var array<int, string> */
    public array $groupIds = [];

    public bool $mail = false;

    public ?string $flash = null;

    public function edit(int $id): void
    {
        $a = Announcement::with('groups')->findOrFail($id);
        $this->editingId = $a->id;
        $this->title = $a->title;
        $this->body = (string) $a->body;
        $this->important = $a->important;
        $this->visibleFrom = $a->visible_from?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '';
        $this->visibleUntil = $a->visible_until?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '';
        $this->groupIds = $a->groups->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->mail = false;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'title', 'body', 'important', 'visibleFrom', 'visibleUntil', 'groupIds', 'mail');
    }

    public function save(AnnouncementService $announcements): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:5000'],
            'visibleFrom' => ['nullable', 'date_format:d.m.Y'],
            'visibleUntil' => ['nullable', 'date_format:d.m.Y'],
            'groupIds.*' => ['exists:groups,id'],
        ], [
            'title.required' => 'Bitte gib einen Titel an.',
            'visibleFrom.date_format' => 'Bitte im Format TT.MM.JJJJ.',
            'visibleUntil.date_format' => 'Bitte im Format TT.MM.JJJJ.',
        ]);

        // Dates are whole days in Berlin: from the start of the first to the end of the last
        $from = filled($this->visibleFrom) ? Carbon::createFromFormat('!d.m.Y', $this->visibleFrom, 'Europe/Berlin')->startOfDay()->utc() : null;
        $until = filled($this->visibleUntil) ? Carbon::createFromFormat('!d.m.Y', $this->visibleUntil, 'Europe/Berlin')->endOfDay()->utc() : null;

        if ($from && $until && $until->lt($from)) {
            $this->addError('visibleUntil', '„Bis“ liegt vor „ab“.');

            return;
        }

        $announcement = $announcements->save(
            $this->editingId ? Announcement::findOrFail($this->editingId) : null,
            ['title' => trim($this->title), 'body' => trim($this->body) ?: null, 'important' => $this->important, 'visible_from' => $from, 'visible_until' => $until],
            array_map('intval', $this->groupIds),
            auth()->user(),
            $this->mail,
        );

        $this->flash = 'Mitteilung „'.$announcement->title.'“ gespeichert'.($this->mail ? ' und per E-Mail verschickt.' : '.');
        $this->cancel();
    }

    public function delete(int $id): void
    {
        Announcement::findOrFail($id)->delete();
        $this->flash = 'Mitteilung gelöscht.';
    }

    public function render()
    {
        return view('mitteilungen::livewire.manage', [
            'announcements' => Announcement::with(['groups', 'author'])->latest()->get(),
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
