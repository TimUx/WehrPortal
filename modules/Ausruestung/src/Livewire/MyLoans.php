<?php

namespace Modules\Ausruestung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Models\Loan;
use Modules\Ausruestung\src\Services\LoanService;

/** "Meine Ausleihen": what I have, until when, and my reservations */
class MyLoans extends Component
{
    public function cancel(int $loanId, LoanService $loans): void
    {
        $loan = Loan::where('user_id', auth()->id())->findOrFail($loanId);

        try {
            $loans->cancel($loan, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('loan', collect($e->errors())->flatten()->first());
        }
    }

    public function render()
    {
        return view('ausruestung::livewire.my-loans', [
            'loans' => Loan::with('equipment')
                ->where('user_id', auth()->id())
                ->whereIn('status', [LoanStatus::Reserved->value, LoanStatus::Active->value])
                ->orderByRaw("status = 'active' desc")
                ->orderBy('due_at')
                ->get(),
        ]);
    }
}
