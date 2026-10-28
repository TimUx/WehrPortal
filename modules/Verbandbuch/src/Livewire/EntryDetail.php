<?php

namespace Modules\Verbandbuch\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Verbandbuch\src\Models\Entry;
use Modules\Verbandbuch\src\Services\VerbandbuchService;

/** One entry with signatures and Nachträge; for the managers also Unfallanzeige, Storno and who opened it */
class EntryDetail extends Component
{
    public Entry $entry;

    public string $amendment = '';

    public string $reportedAt = '';

    public string $reportNote = '';

    public string $voidReason = '';

    public ?string $flash = null;

    public function mount(VerbandbuchService $service): void
    {
        abort_unless(VerbandbuchService::canView(auth()->user(), $this->entry), 403);
        $this->flash = session('verbandbuch.flash');
        $this->reportedAt = VerbandbuchService::now()->toDateString();
        $service->log($this->entry, auth()->user(), 'viewed');
    }

    public function addAmendment(VerbandbuchService $service): void
    {
        $this->run(fn () => $service->amend(auth()->user(), $this->entry, $this->amendment), 'Nachtrag gespeichert.');
    }

    public function markReported(VerbandbuchService $service): void
    {
        $this->run(fn () => $service->markReported(auth()->user(), $this->entry, $this->reportedAt, $this->reportNote), 'Unfallanzeige vermerkt.');
    }

    public function void(VerbandbuchService $service): void
    {
        $this->run(fn () => $service->void(auth()->user(), $this->entry, $this->voidReason), 'Eintrag storniert.');
    }

    public function toggleHold(VerbandbuchService $service): void
    {
        $hold = ! $this->entry->hold;
        $this->run(fn () => $service->setHold(auth()->user(), $this->entry, $hold), $hold ? 'Der Eintrag wird nicht automatisch gelöscht.' : 'Der Eintrag wird nach Ablauf der Frist gelöscht.');
    }

    private function run(callable $action, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->flash = $done;
        $this->reset('amendment', 'reportNote', 'voidReason');
        $this->entry->refresh();
    }

    public function render(VerbandbuchService $service)
    {
        $user = auth()->user();
        $manage = VerbandbuchService::canManageEntry($user, $this->entry);

        return view('verbandbuch::livewire.entry-detail', [
            'signatures' => $this->entry->signatures()->get(),
            'amendments' => $this->entry->amendments()->get(),
            'intact' => $service->verify($this->entry),
            'canManage' => $manage,
            'canAmend' => VerbandbuchService::canAmend($user, $this->entry),
            'log' => $manage ? $this->entry->accessLogs()->with('user')->limit(50)->get() : collect(),
        ]);
    }
}
