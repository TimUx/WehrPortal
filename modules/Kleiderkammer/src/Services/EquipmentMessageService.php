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
    public function send(User $user, IssuedItem $issued, string $body, ?User $actor = null): EquipmentMessage
    {
        $actor ??= auth()->user();
        $access = app(PersonalAccess::class);
        $access->authorizeSelf($actor, $user);
        if ($access->enabled()) {
            $issued = IssuedItem::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('returned_at')->whereNull('deleted_at')->findOrFail($issued->id);
        }
        $message = $access->asActor($actor, fn () => EquipmentMessage::create([
            'user_id' => $user->id,
            'issued_item_id' => $issued->id,
            'item_label' => $this->label($issued),
            'body' => $body,
            'kind' => 'defect',
        ]));

        $message->setRelation('user', $user);
        $this->notify($this->recipients($issued), new EquipmentMessageNotification($message));

        return $message;
    }

    /**
     * The member asks for one or more of their items to be washed, with a reason (e.g. after a Brandeinsatz).
     * Items that already wait for the Wäsche are skipped; the Kleiderwarte get one notification.
     *
     * @param  list<int>  $issuedIds
     * @return Collection<int, EquipmentMessage> the new requests
     */
    public function requestWashing(User $user, array $issuedIds, string $reason, ?User $actor = null): Collection
    {
        $actor ??= auth()->user();
        $access = app(PersonalAccess::class);
        $access->authorizeSelf($actor, $user);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Bitte begründe, warum die Wäsche nötig ist.']);
        }
        $items = $access->asActor($actor, fn () => IssuedItem::where('user_id', $user->id)->whereNull('returned_at')->whereIn('id', array_map('intval', $issuedIds))
            ->with(['item.itemType', 'item.size', 'itemType', 'size'])->get());
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Bitte mindestens ein Teil wählen.']);
        }

        $waiting = $access->asActor($actor, fn () => EquipmentMessage::where('kind', 'washing')->whereNull('handled_at')->whereIn('issued_item_id', $items->pluck('id'))->pluck('issued_item_id'));
        $requests = $access->asActor($actor, fn () => $items->reject(fn (IssuedItem $i) => $waiting->contains($i->id))->map(fn (IssuedItem $issued) => EquipmentMessage::create([
            'user_id' => $user->id, 'issued_item_id' => $issued->id, 'item_label' => $this->label($issued),
            'body' => mb_substr($reason, 0, 2000), 'kind' => 'washing',
        ]))->values());
        if ($requests->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Für die gewählten Teile ist die Wäsche schon angefordert.']);
        }

        if ($access->enabled()) {
            // A shared member never makes the other station's request visible in a combined mail.
            foreach (User::withoutGlobalScopes()->where('is_active', true)->get() as $recipient) {
                $visible = $requests->filter(fn (EquipmentMessage $message) => $access->canManageIssuance($recipient, $items->firstWhere('id', $message->issued_item_id)))->values();
                if ($visible->isNotEmpty()) {
                    $this->notify(collect([$recipient]), new WashingRequestedNotification($user, $visible));
                }
            }
        } else {
            $this->notify($this->recipients(), new WashingRequestedNotification($user, $requests));
        }

        return $requests;
    }

    /** Kleiderwart: washed – a tracked item gets the Wäsche in its history, the member is told */
    public function markWashed(EquipmentMessage $message, User $by): void
    {
        $access = app(PersonalAccess::class);
        if ($access->enabled()) {
            $message = $access->asActor($by, fn () => EquipmentMessage::with(['issuedItem', 'user'])->findOrFail($message->id));
            $access->authorizeIssuance($by, $message->issuedItem);
        }
        if (! $message->isWashing() || $message->isHandled()) {
            return;
        }
        $access->asActor($by, fn () => DB::transaction(function () use ($message, $by) {
            $message->update(['handled_at' => now(), 'handled_by' => $by->id]);
            if ($itemId = $message->issuedItem?->getAttribute('item_id')) {
                ItemTreatment::create(['item_id' => $itemId, 'type' => TreatmentType::Washing, 'treated_at' => now('Europe/Berlin')->toDateString(),
                    'notes' => 'Angefordert von '.$message->user->getAttribute('name').': '.$message->body]);
            }
        }));
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
    private function recipients(?IssuedItem $issued = null): Collection
    {
        $access = app(PersonalAccess::class);
        if ($access->enabled()) {
            return User::withoutGlobalScopes()->where('is_active', true)->get()
                ->filter(fn (User $user) => $issued !== null && $access->canManageIssuance($user, $issued))->values();
        }
        $wardens = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', 'kleiderkammer')->where('role', 'kleiderwart'))
            ->get();

        return $wardens->isNotEmpty() ? $wardens : User::active()->where('is_admin', true)->get();
    }
}
