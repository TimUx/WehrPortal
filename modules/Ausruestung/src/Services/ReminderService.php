<?php

namespace Modules\Ausruestung\src\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Ausruestung\src\Enums\DamageStatus;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Models\Damage;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Loan;
use Modules\Ausruestung\src\Notifications\LoanOverdueNotification;
use Modules\Ausruestung\src\Notifications\MaintenanceDigestNotification;

/** Daily reminders: what is due for the Gerätewarte, and overdue loans for the borrowers */
class ReminderService
{
    public const INSPECTION_LEAD_DAYS = 30;

    public const LIFETIME_LEAD_DAYS = 90;

    public function __construct(private readonly AusruestungMailer $mailer) {}

    /** @return array{digest: bool, overdue_loans: int} */
    public function run(): array
    {
        return [
            'digest' => $this->sendDigest(),
            'overdue_loans' => $this->remindOverdueLoans(),
        ];
    }

    /** What needs attention, for the digest and the dashboard */
    public function summary(): array
    {
        $inService = Equipment::inService()->with('category')->get();

        return [
            'inspections' => Equipment::inspectionDue(self::INSPECTION_LEAD_DAYS)->orderBy('next_inspection_at')->get(),
            'lifetime' => $inService
                ->filter(fn (Equipment $e) => $e->lifetimeEndsAt()?->lte(today()->addDays(self::LIFETIME_LEAD_DAYS)))
                ->sortBy(fn (Equipment $e) => $e->lifetimeEndsAt())
                ->values(),
            'locked' => Equipment::where('status', EquipmentStatus::Locked->value)->orderBy('code')->get(),
            'damages' => Damage::with('equipment')->whereIn('status', [DamageStatus::Open->value, DamageStatus::InRepair->value])->oldest()->get(),
            'overdue_loans' => Loan::with(['equipment', 'user'])->where('status', LoanStatus::Active->value)->where('due_at', '<', now())->orderBy('due_at')->get(),
        ];
    }

    /** One digest per day, even with a second scheduler running */
    public function sendDigest(): bool
    {
        $summary = $this->summary();

        if (collect($summary)->every(fn ($items) => $items->isEmpty())) {
            return false;
        }

        if (! Cache::add('ausruestung-digest-'.today()->toDateString(), true, now()->addDays(2))) {
            return false;
        }

        $this->mailer->toManagers(new MaintenanceDigestNotification($summary));
        $this->sendLocationDigests($summary);

        return true;
    }

    /** Each overdue loan is claimed with an atomic update before mailing, so nobody is told twice */
    public function remindOverdueLoans(): int
    {
        $sent = 0;

        $candidates = Loan::where('status', LoanStatus::Active->value)
            ->where('due_at', '<', now())
            ->whereNull('overdue_reminded_at')
            ->pluck('id');

        foreach ($candidates as $id) {
            $claimed = DB::table('ar_loans')->where('id', $id)->whereNull('overdue_reminded_at')->update(['overdue_reminded_at' => now()]);

            if ($claimed === 1) {
                $loan = Loan::with(['equipment', 'user'])->find($id);
                $this->mailer->send($loan->user, new LoanOverdueNotification($loan));
                $sent++;
            }
        }

        return $sent;
    }

    private function sendLocationDigests(array $summary): void
    {
        $locationIds = collect($summary)
            ->flatMap(fn ($items) => $items->map(fn ($item) => $this->equipmentForSummaryItem($item)?->getAttribute('ext_location_id')))
            ->filter()
            ->unique();

        foreach ($locationIds as $locationId) {
            $scopedSummary = collect($summary)->map(
                fn ($items) => $items->filter(fn ($item) => (int) $this->equipmentForSummaryItem($item)?->getAttribute('ext_location_id') === (int) $locationId)->values(),
            )->all();

            $this->mailer->toLocationManagers(new MaintenanceDigestNotification($scopedSummary), (int) $locationId);
        }
    }

    private function equipmentForSummaryItem(Equipment|Damage|Loan $item): ?Equipment
    {
        return $item instanceof Equipment ? $item : $item->equipment;
    }
}
