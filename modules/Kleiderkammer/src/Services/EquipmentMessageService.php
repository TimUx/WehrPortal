<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Notifications\EquipmentMessageNotification;
use Throwable;

class EquipmentMessageService
{
    public function send(User $user, IssuedItem $issued, string $body): EquipmentMessage
    {
        $type = $issued->item?->itemType ?? $issued->itemType;
        $size = $issued->item?->size ?? $issued->size;

        $label = collect([
            $type?->name ?? 'Artikel',
            $size ? 'Größe '.$size->name : null,
            $issued->item?->code,
        ])->filter()->implode(' · ');

        $message = EquipmentMessage::create([
            'user_id' => $user->id,
            'issued_item_id' => $issued->id,
            'item_label' => $label,
            'body' => $body,
        ]);

        $this->recipients()->each(function (User $recipient) use ($message) {
            // The message is stored either way; a mail server problem ends up in the log
            try {
                $recipient->notify(new EquipmentMessageNotification($message));
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $message;
    }

    /** Kleiderwarte of the module; the admins if nobody holds the role */
    private function recipients()
    {
        $wardens = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', 'kleiderkammer')->where('role', 'kleiderwart'))
            ->get();

        return $wardens->isNotEmpty() ? $wardens : User::active()->where('is_admin', true)->get();
    }
}
