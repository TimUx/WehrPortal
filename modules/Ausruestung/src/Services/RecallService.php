<?php

namespace Modules\Ausruestung\src\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Recall;
use Modules\Ausruestung\src\Notifications\RecallMatchedNotification;

/**
 * Manufacturer recalls: every item matching manufacturer, model and the given serial range, batch or
 * production period is found automatically and locked until the recall is resolved for it.
 * Items registered later are checked against all open recalls as well.
 */
class RecallService
{
    public function __construct(
        private readonly EquipmentService $equipment,
        private readonly AusruestungMailer $mailer,
    ) {}

    /** Items the recall applies to (retired ones excluded) */
    public function matches(Recall $recall): Collection
    {
        return Equipment::inService()
            ->whereRaw('lower(trim(manufacturer)) = ?', [mb_strtolower(trim($recall->manufacturer))])
            ->when(filled($recall->model), fn ($q) => $q->whereRaw('lower(trim(model)) = ?', [mb_strtolower(trim($recall->model))]))
            ->when(filled($recall->batch_number), fn ($q) => $q->where('batch_number', $recall->batch_number))
            ->when($recall->manufactured_from, fn ($q) => $q->whereDate('manufactured_at', '>=', $recall->manufactured_from))
            ->when($recall->manufactured_to, fn ($q) => $q->whereDate('manufactured_at', '<=', $recall->manufactured_to))
            ->get()
            ->filter(fn (Equipment $e) => $this->inSerialRange($e->serial_number, $recall->serial_from, $recall->serial_to))
            ->values();
    }

    /** Locks every newly matching item; returns how many were added */
    public function apply(Recall $recall, ?User $by = null): int
    {
        if ($by !== null && ! EquipmentService::canManageGlobal($by)) {
            throw ValidationException::withMessages([
                'recall' => 'Nur globale Gerätewarte dürfen Rückrufdefinitionen anwenden.',
            ]);
        }

        if (! $recall->isOpen()) {
            return 0;
        }

        $known = $recall->equipment()->pluck('ar_equipment.id')->all();
        $new = $this->matches($recall)->reject(fn (Equipment $e) => in_array($e->id, $known, true));

        DB::transaction(function () use ($recall, $new, $by) {
            foreach ($new as $equipment) {
                $this->lock($recall, $equipment, $by);
            }
        });

        if ($new->isNotEmpty()) {
            $this->mailer->toManagers(new RecallMatchedNotification($recall, $new));
            $this->notifyLocationManagers($recall, $new);
        }

        return $new->count();
    }

    /** A new or changed item is checked against all open recalls */
    public function check(Equipment $equipment): void
    {
        Recall::whereNull('closed_at')->get()
            ->filter(fn (Recall $recall) => ! $recall->equipment()->whereKey($equipment->id)->exists()
                && $this->matches($recall)->contains('id', $equipment->id))
            ->each(function (Recall $recall) use ($equipment) {
                DB::transaction(fn () => $this->lock($recall, $equipment, null));
                $this->mailer->toManagers(new RecallMatchedNotification($recall, collect([$equipment])));
                $this->notifyLocationManagers($recall, collect([$equipment]));
            });
    }

    /** The recall is dealt with for this item (checked, repaired, exchanged …) */
    public function resolve(Recall $recall, Equipment $equipment, ?User $by, ?string $resolution = null): void
    {
        if ($by !== null) {
            $this->equipment->assertCanManageEquipment($by, $equipment);
        }

        DB::transaction(function () use ($recall, $equipment, $by, $resolution) {
            $pivot = DB::table('ar_recall_equipment')->where('recall_id', $recall->id)->where('equipment_id', $equipment->id)->first();

            if (! $pivot || $pivot->resolved_at) {
                return;
            }

            DB::table('ar_recall_equipment')->where('id', $pivot->id)->update(['resolved_at' => now(), 'resolution' => $resolution, 'updated_at' => now()]);
            $this->equipment->log($equipment, $by, 'recall', 'Rückruf „'.$recall->title.'“ erledigt'.(filled($resolution) ? ': '.$resolution : ''));

            // Unlocked only when no other recall still holds it
            $stillLocked = DB::table('ar_recall_equipment')->where('equipment_id', $equipment->id)->whereNull('resolved_at')->exists();
            $equipment->refresh();

            if (! $stillLocked && $equipment->status === EquipmentStatus::Locked) {
                $this->equipment->setStatus($equipment, $by, EquipmentStatus::from($pivot->previous_status), 'Rückruf erledigt');
            }
        });
    }

    public function close(Recall $recall, ?User $by): void
    {
        if ($by !== null && ! EquipmentService::canManageGlobal($by)) {
            throw ValidationException::withMessages([
                'recall' => 'Nur globale Gerätewarte dürfen einen Rückruf schließen.',
            ]);
        }

        foreach ($recall->equipment()->wherePivotNull('resolved_at')->get() as $equipment) {
            $this->resolve($recall, $equipment, $by, 'Rückruf geschlossen');
        }

        $recall->update(['closed_at' => now()]);
    }

    private function lock(Recall $recall, Equipment $equipment, ?User $by): void
    {
        // An item already locked by another recall keeps its original status for later
        $previous = $equipment->status === EquipmentStatus::Locked
            ? (DB::table('ar_recall_equipment')->where('equipment_id', $equipment->id)->whereNull('resolved_at')->value('previous_status') ?? EquipmentStatus::Available->value)
            : $equipment->status->value;

        $recall->equipment()->attach($equipment->id, ['previous_status' => $previous]);
        $equipment->updateQuietly(['status' => EquipmentStatus::Locked]);
        $this->equipment->log($equipment, $by, 'recall', 'Gesperrt wegen Rückruf „'.$recall->title.'“');
    }

    private function notifyLocationManagers(Recall $recall, Collection $equipment): void
    {
        $equipment->groupBy(fn (Equipment $item) => $item->getAttribute('ext_location_id'))
            ->each(function (Collection $items, int|string|null $locationId) use ($recall) {
                if ($locationId === null || $locationId === '') {
                    return;
                }

                $this->mailer->toLocationManagers(new RecallMatchedNotification($recall, $items), (int) $locationId);
            });
    }

    /** Serial numbers compare numerically when both bounds are numbers, otherwise in natural order */
    private function inSerialRange(?string $serial, ?string $from, ?string $to): bool
    {
        if (blank($from) && blank($to)) {
            return true;
        }

        if (blank($serial)) {
            return false;
        }

        $compare = fn (string $a, string $b) => ctype_digit($a) && ctype_digit($b)
            ? (int) $a <=> (int) $b
            : strnatcasecmp($a, $b);

        return (blank($from) || $compare($serial, $from) >= 0) && (blank($to) || $compare($serial, $to) <= 0);
    }
}
