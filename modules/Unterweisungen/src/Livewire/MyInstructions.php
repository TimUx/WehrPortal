<?php

namespace Modules\Unterweisungen\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Unterweisungen\src\Models\Attendance;
use Modules\Unterweisungen\src\Services\InstructionService;

/** Meine Unterweisungen: what is required and how it stands; open signatures on the own phone */
class MyInstructions extends Component
{
    public ?int $signing = null;

    public string $signature = '';

    public function sign(InstructionService $service): void
    {
        $attendance = Attendance::where('user_id', auth()->id())->findOrFail((int) $this->signing);
        try {
            $service->sign(auth()->user(), $attendance, $this->signature);
        } catch (ValidationException $e) {
            $this->addError('signature', collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('signing', 'signature');
    }

    public function render(InstructionService $service)
    {
        return view('unterweisungen::livewire.my-instructions', [
            'rows' => $service->forUser(auth()->user()),
            'open' => Attendance::with('session.topic')->where('user_id', auth()->id())->whereNull('signed_at')->get(),
        ]);
    }
}
