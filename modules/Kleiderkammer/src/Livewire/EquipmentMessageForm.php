<?php

namespace Modules\Kleiderkammer\src\Livewire;

use Livewire\Component;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Services\EquipmentMessageService;

/** "Meine Ausrüstung": write to the Kleiderwart about one issued item */
class EquipmentMessageForm extends Component
{
    public int $issuedItemId;

    public string $body = '';

    public bool $sent = false;

    private function issued(): IssuedItem
    {
        return IssuedItem::where('user_id', auth()->id())
            ->whereNull('returned_at')
            ->findOrFail($this->issuedItemId);
    }

    public function send(EquipmentMessageService $service): void
    {
        $this->validate(
            ['body' => ['required', 'string', 'max:2000']],
            ['body.required' => 'Bitte schreib eine Nachricht.', 'body.max' => 'Die Nachricht ist zu lang.'],
        );

        $service->send(auth()->user(), $this->issued(), trim($this->body));

        $this->reset('body');
        $this->sent = true;
    }

    public function render()
    {
        return view('kleiderkammer::livewire.equipment-message-form', [
            'messages' => EquipmentMessage::where('user_id', auth()->id())
                ->where('issued_item_id', $this->issuedItemId)
                ->latest()
                ->get(),
        ]);
    }
}
