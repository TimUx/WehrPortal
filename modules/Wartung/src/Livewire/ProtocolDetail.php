<?php

namespace Modules\Wartung\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Wartung\src\Models\Protocol;
use Modules\Wartung\src\Services\WartungService;

/** One signed protocol: checklist, photos, signature and check; the Gerätewart can cancel it */
class ProtocolDetail extends Component
{
    public Protocol $protocol;

    public string $voidReason = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->flash = session('wartung.flash');
    }

    public function void(WartungService $service): void
    {
        $this->resetErrorBag();
        try {
            $service->voidProtocol(auth()->user(), $this->protocol, $this->voidReason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->flash = 'Protokoll storniert. Bitte die Prüffrist am Gerät in der Ausrüstungsverwaltung prüfen.';
        $this->protocol->refresh();
    }

    public function render(WartungService $service)
    {
        return view('wartung::livewire.protocol-detail', [
            'attachments' => $this->protocol->attachments()->get(['id', 'protocol_id', 'name', 'mime', 'size']),
            'intact' => $service->verify($this->protocol),
            'canManage' => WartungService::canManage(auth()->user()),
        ]);
    }
}
