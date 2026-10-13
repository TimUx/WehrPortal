<?php

namespace Modules\Ausruestung\src\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\DamageStatus;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Models\Damage;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\EquipmentEvent;
use Modules\Ausruestung\src\Models\Inspection;
use Modules\Ausruestung\src\Notifications\DamageReportedNotification;

/** Status, inspections, damages and history of an item; every change leaves a history entry */
class EquipmentService
{
    public function __construct(private readonly AusruestungMailer $mailer) {}

    public function log(Equipment $equipment, ?User $by, string $type, ?string $body = null, ?Carbon $at = null, ?float $cost = null): EquipmentEvent
    {
        return $equipment->events()->create([
            'user_id' => $by?->id,
            'type' => $type,
            'occurred_at' => $at ?? now(),
            'body' => filled($body) ? $body : null,
            'cost' => $cost,
        ]);
    }

    /** Reinigung or Wartung by a Gerätewart, with its costs, in the Bestandsverlauf */
    public function logWork(Equipment $equipment, User $by, string $type, ?string $body, Carbon $at, ?float $cost): EquipmentEvent
    {
        if (! $by->canManageAusruestung() || ! in_array($type, EquipmentEvent::WORK_TYPES, true)) {
            throw ValidationException::withMessages(['workType' => 'Reinigungen und Wartungen tragen die Gerätewarte ein.']);
        }
        if ($cost !== null && ($cost < 0 || $cost > 1_000_000)) {
            throw ValidationException::withMessages(['workCost' => 'Bitte einen gültigen Betrag angeben.']);
        }

        return $this->log($equipment, $by, $type, $body, $at, $cost);
    }

    /** Give the item an RFID/NFC tag (or remove it); a tag belongs to one item only */
    public function setTag(Equipment $equipment, User $by, ?string $tag): void
    {
        if (! $by->canManageAusruestung()) {
            throw ValidationException::withMessages(['tag' => 'Tags ordnen die Gerätewarte zu.']);
        }
        $tag = Equipment::normalizeTag($tag);
        if ($tag !== null && mb_strlen($tag) > 64) {
            throw ValidationException::withMessages(['tag' => 'Der Tag ist zu lang.']);
        }
        if ($tag !== null && ($other = Equipment::where('rfid', $tag)->whereKeyNot($equipment->id)->first())) {
            throw ValidationException::withMessages(['tag' => 'Dieser Tag gehört schon zu „'.$other->name.'“ ('.$other->code.').']);
        }
        if ($tag === $equipment->rfid) {
            return;
        }
        $equipment->update(['rfid' => $tag]);
        $this->log($equipment, $by, 'note', $tag ? 'RFID/NFC-Tag '.$tag.' zugeordnet' : 'RFID/NFC-Tag entfernt');
    }

    /**
     * What the item cost over its life: purchase, repairs (Schäden) and Reinigungen/Wartungen.
     *
     * @return array{purchase: float, repairs: float, work: float, total: float}
     */
    public function lifecycleCosts(Equipment $equipment): array
    {
        $purchase = (float) $equipment->purchase_price;
        $repairs = (float) $equipment->damages()->sum('repair_cost');
        $work = (float) $equipment->events()->sum('cost');

        return ['purchase' => $purchase, 'repairs' => $repairs, 'work' => $work, 'total' => $purchase + $repairs + $work];
    }

    public function setStatus(Equipment $equipment, ?User $by, EquipmentStatus $status, ?string $note = null): void
    {
        if ($equipment->status === $status) {
            return;
        }

        $from = $equipment->status;
        $equipment->update(['status' => $status]);
        $this->log($equipment, $by, 'status', $from->label().' → '.$status->label().(filled($note) ? ': '.$note : ''));
    }

    public function move(Equipment $equipment, ?User $by, ?int $locationId, ?int $compartmentId): void
    {
        $before = $equipment->placeLabel();
        $equipment->update(['location_id' => $locationId, 'compartment_id' => $compartmentId]);
        $after = $equipment->refresh()->placeLabel();

        if ($before !== $after) {
            $this->log($equipment, $by, 'move', $before.' → '.$after);
        }
    }

    /** A passed inspection sets the next due date; a failed one takes the item out of service with a damage report */
    public function recordInspection(Equipment $equipment, ?User $by, Carbon $inspectedAt, bool $passed, ?string $notes = null): Inspection
    {
        return DB::transaction(function () use ($equipment, $by, $inspectedAt, $passed, $notes) {
            $months = $equipment->inspectionIntervalMonths();
            $nextDue = $passed && $months ? $inspectedAt->copy()->addMonths($months) : null;

            $inspection = $equipment->inspections()->create([
                'inspected_at' => $inspectedAt,
                'passed' => $passed,
                'user_id' => $by?->id,
                'next_due_at' => $nextDue,
                'notes' => $notes,
            ]);

            $equipment->update(['next_inspection_at' => $nextDue]);
            $this->log($equipment, $by, 'inspection', ($passed ? 'Bestanden' : 'Nicht bestanden').($nextDue ? ', nächste Prüfung '.$nextDue->format('d.m.Y') : '').(filled($notes) ? ': '.$notes : ''), $inspectedAt->copy()->setTimeFrom(now()));

            if (! $passed) {
                $this->reportDamage($equipment, $by, 'Prüfung nicht bestanden'.(filled($notes) ? ': '.$notes : ''));
            }

            return $inspection;
        });
    }

    public function reportDamage(Equipment $equipment, ?User $by, string $description): Damage
    {
        $damage = DB::transaction(function () use ($equipment, $by, $description) {
            $damage = $equipment->damages()->create([
                'reported_by' => $by?->id,
                'description' => $description,
                'status' => DamageStatus::Open,
            ]);

            $this->log($equipment, $by, 'damage', $description);
            $this->takeOutOfService($equipment, $by, EquipmentStatus::Defective);

            return $damage;
        });

        $this->mailer->toManagers(new DamageReportedNotification($damage), except: $by);

        return $damage;
    }

    public function updateDamage(Damage $damage, ?User $by, DamageStatus $status, ?string $notes = null, ?float $cost = null): void
    {
        DB::transaction(function () use ($damage, $by, $status, $notes, $cost) {
            $damage->update([
                'status' => $status,
                'repair_notes' => filled($notes) ? $notes : $damage->repair_notes,
                'repair_cost' => $cost ?? $damage->repair_cost,
                'resolved_at' => $status->isOpen() ? null : now(),
            ]);

            $equipment = $damage->equipment;
            $this->log($equipment, $by, 'repair', $status->label().(filled($notes) ? ': '.$notes : ''));

            match ($status) {
                DamageStatus::InRepair => $this->takeOutOfService($equipment, $by, EquipmentStatus::InRepair),
                DamageStatus::Scrapped => $this->setStatus($equipment, $by, EquipmentStatus::Retired, 'nach Schaden ausgesondert'),
                DamageStatus::Repaired => $equipment->damages()->get()->contains(fn (Damage $d) => $d->status->isOpen())
                    ? null
                    : $this->backInService($equipment, $by),
                default => null,
            };
        });
    }

    /** Defective or in repair; an item locked by a recall stays locked but comes back as defective */
    private function takeOutOfService(Equipment $equipment, ?User $by, EquipmentStatus $status): void
    {
        if ($equipment->status === EquipmentStatus::Retired) {
            return;
        }

        if ($equipment->status === EquipmentStatus::Locked) {
            DB::table('ar_recall_equipment')->where('equipment_id', $equipment->id)->whereNull('resolved_at')
                ->update(['previous_status' => $status->value]);

            return;
        }

        $this->setStatus($equipment, $by, $status);
    }

    private function backInService(Equipment $equipment, ?User $by): void
    {
        if ($equipment->status === EquipmentStatus::Locked) {
            DB::table('ar_recall_equipment')->where('equipment_id', $equipment->id)->whereNull('resolved_at')
                ->update(['previous_status' => EquipmentStatus::Available->value]);

            return;
        }

        if (in_array($equipment->status, [EquipmentStatus::Defective, EquipmentStatus::InRepair], true)) {
            $this->setStatus($equipment, $by, $equipment->activeLoan() ? EquipmentStatus::Loaned : EquipmentStatus::Available, 'repariert');
        }
    }
}
