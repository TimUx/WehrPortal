<?php

namespace Modules\Mitteilungen\src\Livewire;

use App\Models\Group;
use App\Models\MemberFunction;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Models\AnnouncementFile;
use Modules\Mitteilungen\src\Services\AnnouncementService;

/** Write and edit the notices on the start page (Wehrführung, admins) */
class Manage extends Component
{
    use WithFileUploads;

    /** @var array<int, string> audience by Funktion, besides the Abteilungen */
    public array $functionIds = [];

    /** Lesebestätigung: every Kamerad confirms having read it */
    public bool $needsAck = false;

    /** New attachments */
    public array $uploads = [];

    /** Mitteilung whose readers are shown */
    public ?int $statsFor = null;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public bool $important = false;

    public string $visibleFrom = '';

    public string $visibleUntil = '';

    /** @var array<int, string> */
    public array $groupIds = [];

    public bool $mail = false;

    /** Only bell and push, no mail: a short notice to the phones */
    public bool $push = false;

    public bool $ntfy = false;

    public bool $matrix = false;

    public string $locationId = '';

    public ?string $flash = null;

    public function edit(int $id, AnnouncementService $service): void
    {
        $a = Announcement::with('groups')->findOrFail($id);
        $service->assertCanManage(auth()->user(), $a);
        $this->editingId = $a->id;
        $this->title = $a->title;
        $this->body = (string) $a->body;
        $this->important = $a->important;
        $this->visibleFrom = $a->visible_from?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '';
        $this->visibleUntil = $a->visible_until?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '';
        $this->groupIds = $a->groups->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->functionIds = $a->functions()->pluck('member_functions.id')->map(fn ($id) => (string) $id)->all();
        $this->needsAck = $a->needs_ack;
        $this->locationId = $a->ext_location_id ? (string) $a->ext_location_id : '';
        $this->uploads = [];
        $this->mail = false;
        $this->push = false;
        $this->ntfy = false;
        $this->matrix = false;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'title', 'body', 'important', 'visibleFrom', 'visibleUntil', 'groupIds', 'functionIds', 'needsAck', 'uploads', 'mail', 'push', 'ntfy', 'matrix', 'locationId');
    }

    public function save(AnnouncementService $announcements): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:5000'],
            'visibleFrom' => ['nullable', 'date_format:d.m.Y'],
            'visibleUntil' => ['nullable', 'date_format:d.m.Y'],
            'groupIds.*' => ['exists:groups,id'],
            'functionIds.*' => ['exists:member_functions,id'],
            'locationId' => ['nullable', 'integer'],
            'uploads.*' => ['file', 'max:'.AnnouncementFile::MAX_KB],
        ], [
            'uploads.*.max' => 'Eine Datei ist zu groß (höchstens 10 MB).',
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

        try {
            $existing = $this->editingId ? Announcement::findOrFail($this->editingId) : null;
            $announcement = $announcements->save(
                $existing,
                ['title' => trim($this->title), 'body' => trim($this->body) ?: null, 'important' => $this->important, 'needs_ack' => $this->needsAck, 'visible_from' => $from, 'visible_until' => $until],
                array_map('intval', $this->groupIds),
                auth()->user(),
                $this->mail,
                $this->push,
                array_map('intval', $this->functionIds),
                $this->uploads,
                $this->ntfy,
                $this->matrix,
                filled($this->locationId) ? (int) $this->locationId : null,
            );
        } catch (ValidationException $e) {
            $this->addError('uploads', collect($e->errors())->flatten()->first());

            return;
        }

        $this->flash = 'Mitteilung „'.$announcement->title.'“ gespeichert'.($this->mail ? ' und per E-Mail verschickt.' : ($this->push ? ' und als Push verschickt.' : '.'));
        if ($announcements->externalStatus !== []) {
            $this->flash .= ' '.implode(' ', $announcements->externalStatus);
        }
        $this->cancel();
    }

    public function deleteFile(int $id): void
    {
        $file = AnnouncementFile::findOrFail($id);
        abort_unless($this->editingId && $file->announcement_id === $this->editingId, 403);
        app(AnnouncementService::class)->assertCanManage(auth()->user(), $file->announcement);
        $file->delete();
        $this->flash = 'Anhang gelöscht.';
    }

    public function showStats(?int $id): void
    {
        if ($id) {
            app(AnnouncementService::class)->assertCanManage(auth()->user(), Announcement::findOrFail($id));
        }
        $this->statsFor = $this->statsFor === $id ? null : $id;
    }

    /** Asks everyone again who hasn't confirmed (or read) yet */
    public function remind(int $id, AnnouncementService $announcements): void
    {
        $announcement = Announcement::findOrFail($id);
        $announcements->assertCanManage(auth()->user(), $announcement);
        $count = $announcements->remindUnacknowledged($announcement);
        $this->flash = $count ? $count.' Kameraden erinnert.' : 'Alle haben schon bestätigt.';
    }

    public function delete(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        app(AnnouncementService::class)->assertCanManage(auth()->user(), $announcement);
        $announcement->delete();
        $this->flash = 'Mitteilung gelöscht.';
    }

    public function render(AnnouncementService $announcements)
    {
        $user = auth()->user();
        $access = config('locations.enabled') && app()->bound(LocationAccess::class) ? app(LocationAccess::class) : null;
        $locationIds = $access
            ? ($user->is_admin ? Location::query()->pluck('id')->all() : ($access->isLocationAdmin($user) ? $access->adminLocationIds($user) : $access->visibleLocationIds($user)))
            : [];
        $selectedLocation = filled($this->locationId) ? (int) $this->locationId : null;

        return view('mitteilungen::livewire.manage', [
            'announcements' => $announcements->managementQuery($user)->with(['groups', 'functions', 'author', 'files'])->latest()->get(),
            'locations' => $access ? Location::query()->whereKey($locationIds)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id') : collect(),
            'locationsEnabled' => $access !== null && \Illuminate\Support\Facades\Schema::hasTable('x_locations'),
            'groups' => Group::when($selectedLocation, fn ($q) => $q->where(fn ($w) => $w->whereNull('ext_location_id')->orWhere('ext_location_id', $selectedLocation)))
                ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'functions' => MemberFunction::topLevel()->when($selectedLocation, fn ($q) => $q->where(fn ($w) => $w->whereNull('ext_location_id')->orWhere('ext_location_id', $selectedLocation)))
                ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'editingFiles' => $this->editingId ? AnnouncementFile::where('announcement_id', $this->editingId)->get(['id', 'filename', 'size']) : collect(),
            'stats' => $this->statsFor && ($a = $announcements->managementQuery($user)->find($this->statsFor)) ? $announcements->stats($a) : null,
        ]);
    }
}
