<?php

namespace Modules\Ausruestung\src\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Loan;

/**
 * Internal lending. A reservation blocks a period; handing out turns it into a loan.
 * Mutations lock the equipment row, so two people cannot book the same period at once.
 */
class LoanService
{
    public function __construct(private readonly EquipmentService $equipment) {}

    public function reserve(Equipment $equipment, User $borrower, ?User $by, Carbon $from, Carbon $until, ?string $notes = null): Loan
    {
        if ($until->lte($from)) {
            throw ValidationException::withMessages(['due_at' => 'Das Rückgabedatum muss nach dem Beginn liegen.']);
        }

        return DB::transaction(function () use ($equipment, $borrower, $by, $from, $until, $notes) {
            $equipment = Equipment::whereKey($equipment->id)->lockForUpdate()->firstOrFail();

            if (in_array($equipment->status, [EquipmentStatus::Retired, EquipmentStatus::Locked, EquipmentStatus::Defective, EquipmentStatus::InRepair], true)) {
                throw ValidationException::withMessages(['equipment_id' => 'Das Gerät ist zurzeit nicht ausleihbar ('.$equipment->status->label().').']);
            }

            if ($equipment->loans()->open()->overlapping($from, $until)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Das Gerät ist in diesem Zeitraum schon reserviert oder ausgeliehen.']);
            }

            $loan = $equipment->loans()->create([
                'user_id' => $borrower->id,
                'status' => LoanStatus::Reserved,
                'starts_at' => $from,
                'due_at' => $until,
                'notes' => $notes,
                'created_by' => $by?->id,
            ]);

            $this->equipment->log($equipment, $by, 'loan', 'Reserviert für '.$borrower->name.' vom '.$from->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' bis '.$until->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i'));

            return $loan;
        });
    }

    /** Lend right away: a reservation from now that is handed out at once */
    public function lend(Equipment $equipment, User $borrower, ?User $by, Carbon $until, ?string $notes = null): Loan
    {
        return DB::transaction(function () use ($equipment, $borrower, $by, $until, $notes) {
            $loan = $this->reserve($equipment, $borrower, $by, now(), $until, $notes);
            $this->handOut($loan, $by);

            return $loan->refresh();
        });
    }

    public function handOut(Loan $loan, ?User $by): void
    {
        DB::transaction(function () use ($loan, $by) {
            $equipment = Equipment::whereKey($loan->equipment_id)->lockForUpdate()->firstOrFail();
            $loan->refresh();

            if ($loan->status !== LoanStatus::Reserved) {
                throw ValidationException::withMessages(['loan' => 'Nur eine Reservierung kann ausgegeben werden.']);
            }

            // Safety first: nothing goes out that is not fit for use
            if ($equipment->status !== EquipmentStatus::Available || ! $equipment->isUsable()) {
                $reason = $equipment->status !== EquipmentStatus::Available ? $equipment->status->label() : implode(', ', $equipment->warnings());

                throw ValidationException::withMessages(['loan' => 'Das Gerät kann nicht ausgegeben werden: '.$reason.'.']);
            }

            $loan->update(['status' => LoanStatus::Active, 'handed_out_at' => now()]);
            $equipment->update(['status' => EquipmentStatus::Loaned]);
            $this->equipment->log($equipment, $by, 'loan', 'Ausgegeben an '.$loan->user->name.', zurück bis '.$loan->due_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i'));
        });
    }

    /** Back in the store; a damage noticed on return is reported at once */
    public function return(Loan $loan, ?User $by, ?string $damage = null): void
    {
        DB::transaction(function () use ($loan, $by, $damage) {
            $equipment = Equipment::whereKey($loan->equipment_id)->lockForUpdate()->firstOrFail();
            $loan->refresh();

            if ($loan->status !== LoanStatus::Active) {
                throw ValidationException::withMessages(['loan' => 'Diese Ausleihe ist nicht aktiv.']);
            }

            $loan->update(['status' => LoanStatus::Returned, 'returned_at' => now()]);

            if ($equipment->status === EquipmentStatus::Loaned) {
                $equipment->update(['status' => EquipmentStatus::Available]);
            }

            $this->equipment->log($equipment, $by, 'return', 'Zurückgegeben von '.$loan->user->name);

            if (filled($damage)) {
                $this->equipment->reportDamage($equipment->refresh(), $by, $damage);
            }
        });
    }

    public function cancel(Loan $loan, ?User $by): void
    {
        if ($loan->status !== LoanStatus::Reserved) {
            throw ValidationException::withMessages(['loan' => 'Nur eine Reservierung kann storniert werden.']);
        }

        $loan->update(['status' => LoanStatus::Cancelled]);
        $this->equipment->log($loan->equipment, $by, 'loan', 'Reservierung von '.$loan->user->name.' storniert');
    }
}
