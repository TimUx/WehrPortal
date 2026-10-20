<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Kleiderkammer\src\Enums\TreatmentType;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\ItemTreatment;
use Modules\Kleiderkammer\src\Notifications\EquipmentMessageNotification;
use Modules\Kleiderkammer\src\Notifications\WashingDoneNotification;
use Modules\Kleiderkammer\src\Notifications\WashingRequestedNotification;
use Throwable;

/** Mängel and Wäsche requests of the members, and the Kleiderwart dealing with them */
class EquipmentMessageService
{
    public function send(User $user, IssuedItem $issued, string $body): EquipmentMessage
    {
        $message = EquipmentMessage::create([
            'user_id' => $user->id,
            'issued_item_id' => $issued->id,
            'item_label' => $this->label($issued),
            'body' => $body,
            'kind' => 'defect',
        ]);

        $this->notify($this->recipients(), new EquipmentMessageNotification($message));

        return $message;
    }

    /**
     * The member asks for one or more of their items to be washed, with a reason (e.g. after a Brandeinsatz).
     * Items that already wait for the Wäsche are skipped; the Kleiderwarte get one notification.
     *
     * @param  list<int>  $issuedIds
     * @return Collection<int, EquipmentMessage> the new requests
     */
    public function requestWashing(User $user, array $issuedIds, string $reason): Collection
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Bitte begründe, warum die Wäsche nötig ist.']);
        }
        $items = IssuedItem::where('user_id', $user->id)->whereNull('returned_at')->whereIn('id', array_map('intval', $issuedIds))
            ->with(['item.itemType', 'item.size', 'itemType', 'size'])->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Bitte mindestens ein Teil wählen.']);
        }

        $waiting = EquipmentMessage::where('kind', 'washing')->whereNull('handled_at')->whereIn('issued_item_id', $items->pluck('id'))->pluck('issued_item_id');
        $requests = $items->reject(fn (IssuedItem $i) => $waiting->contains($i->id))->map(fn (IssuedItem $issued) => EquipmentMessage::create([
            'user_id' => $user->id, 'issued_item_id' => $issued->id, 'item_label' => $this->label($issued),
            'body' => mb_substr($reason, 0, 2000), 'kind' => 'washing',
        ]))->values();
        if ($requests->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Für die gewählten Teile ist die Wäsche schon angefordert.']);
        }

        $this->notify($this->recipients(), new WashingRequestedNotification($user, $requests));

        return $requests;
    }

    /** Kleiderwart: washed – a tracked item gets the Wäsche in its history, the member is told */
    public function markWashed(EquipmentMessage $message, User $by): void
    {
        if (! $message->isWashing() || $message->isHandled()) {
            return;
        }
        DB::transaction(function () use ($message, $by) {
            $message->update(['handled_at' => now(), 'handled_by' => $by->id]);
            if ($itemId = $message->issuedItem?->getAttribute('item_id')) {
                ItemTreatment::create(['item_id' => $itemId, 'type' => TreatmentType::Washing, 'treated_at' => now('Europe/Berlin')->toDateString(),
                    'notes' => 'Angefordert von '.$message->user->getAttribute('name').': '.$message->body]);
            }
        });
        $this->notify(collect([$message->user]), new WashingDoneNotification($message));
    }

    private function label(IssuedItem $issued): string
    {
        $type = $issued->item?->itemType ?? $issued->itemType;
        $size = $issued->item?->size ?? $issued->size;

        return collect([
            $type?->name ?? 'Artikel',
            $size ? 'Größe '.$size->name : null,
            $issued->item?->code,
        ])->filter()->implode(' · ');
    }

    private function notify(Collection $users, Notification $notification): void
    {
        $users->filter()->each(function (User $recipient) use ($notification) {
            // The message is stored either way; a mail server problem ends up in the log
            try {
                $recipient->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Kleiderwarte of the module; the admins if nobody holds the role */
    private function recipients(): Collection
    {
        $wardens = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', 'kleiderkammer')->where('role', 'kleiderwart'))
            ->get();

        return $wardens->isNotEmpty() ? $wardens : User::active()->where('is_admin', true)->get();
    }
}
