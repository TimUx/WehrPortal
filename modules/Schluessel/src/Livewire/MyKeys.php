<?php

namespace Modules\Schluessel\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Schluessel\src\Models\Handover;
use Modules\Schluessel\src\Services\KeyService;

/** Meine Schlüssel: what the Kamerad has, until when, and "verloren melden" */
class MyKeys extends Component
{
    public ?string $flash = null;

    public function reportLost(int $handoverId, KeyService $keys): void
    {
        $handover = Handover::where('user_id', auth()->id())->whereNull('returned_at')->findOrFail($handoverId);
        try {
            $keys->reportLost(auth()->user(), $handover->key);
        } catch (ValidationException $e) {
            $this->addError('issue', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Verlust gemeldet – der Schlüsselwart ist informiert.';
    }

    public function render()
    {
        return view('schluessel::livewire.my-keys', [
            'held' => Handover::with('key.place')->where('user_id', auth()->id())->whereNull('returned_at')->orderBy('issued_at')->get(),
            'manage' => KeyService::canManage(auth()->user()),
        ]);
    }
}
