<?php

namespace Modules\Finanzen\src\Livewire;

use App\Models\ActivityLog;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Models\Receipt;
use Modules\Finanzen\src\Services\FinanceService;

/** One Buchung with its Belege: approve or reject, pay, change, withdraw – depending on who looks */
class EntryDetail extends Component
{
    public Entry $entry;

    public string $comment = '';

    public string $paymentNote = '';

    public ?string $flash = null;

    public function mount(): void
    {
        abort_unless(FinanceService::canView(auth()->user(), $this->entry), 403);
        $this->flash = session('finanzen.flash');
    }

    public function approve(FinanceService $finance): void
    {
        $this->run(fn () => $finance->decide(auth()->user(), $this->entry, true, $this->comment), 'Genehmigt. '.($this->entry->submitter?->name ?? 'Der Kamerad').' ist informiert.');
    }

    public function reject(FinanceService $finance): void
    {
        $this->run(fn () => $finance->decide(auth()->user(), $this->entry, false, $this->comment), 'Abgelehnt. '.($this->entry->submitter?->name ?? 'Der Kamerad').' ist informiert.');
    }

    public function pay(FinanceService $finance): void
    {
        $this->run(fn () => $finance->pay(auth()->user(), $this->entry, $this->paymentNote), 'Als bezahlt eingetragen.');
    }

    public function removeReceipt(int $receiptId, FinanceService $finance): void
    {
        $receipt = Receipt::where('entry_id', $this->entry->id)->findOrFail($receiptId);
        $this->run(fn () => $finance->removeReceipt(auth()->user(), $receipt), 'Beleg entfernt.');
    }

    public function delete(FinanceService $finance)
    {
        $mine = $this->entry->submitted_by === auth()->id() && $this->entry->status === 'submitted';

        if ($this->run(fn () => $finance->delete(auth()->user(), $this->entry), '')) {
            session()->flash('finanzen.flash', $mine ? 'Ausgabe zurückgezogen.' : 'Buchung gelöscht.');

            return redirect()->route(FinanceService::canReview(auth()->user()) ? 'finanzen.entries' : 'finanzen.index');
        }

        return null;
    }

    private function run(callable $action, string $done): bool
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'comment' ? 'comment' : 'entry', $messages[0]);
            }

            return false;
        }

        $this->flash = $done ?: null;
        $this->reset('comment', 'paymentNote');
        $this->entry->refresh();

        return true;
    }

    public function render()
    {
        $user = auth()->user();
        $relations = ['costCenter', 'submitter', 'decider', 'payer'];
        if (FinanceService::locationsEnabled()) {
            $relations[] = 'location';
        }
        $entry = $this->entry->load($relations);
        $canReviewEntry = FinanceService::canReviewAt($user, FinanceService::locationsEnabled() ? $entry->location_id : null);

        return view('finanzen::livewire.entry-detail', [
            'receipts' => $entry->receipts()->get(['id', 'entry_id', 'name', 'mime', 'size']),
            'canDecide' => $canReviewEntry && $entry->status === 'submitted' && ($entry->submitted_by !== $user->id || $user->is_admin) && ! FinanceService::isClosed($entry->year),
            'canPay' => FinanceService::canManage($user) && $entry->status === 'approved' && ! FinanceService::isClosed($entry->year),
            'canEdit' => FinanceService::canEdit($user, $entry),
            'closed' => FinanceService::isClosed($entry->year),
            'locationsEnabled' => FinanceService::locationsEnabled(),
            // Who changed what, for Kassenwart and Wehrführung
            'history' => $canReviewEntry
                ? ActivityLog::with('user')->where('subject_type', Entry::class)->where('subject_id', $entry->id)->latest('id')->limit(20)->get()
                : collect(),
        ]);
    }
}
