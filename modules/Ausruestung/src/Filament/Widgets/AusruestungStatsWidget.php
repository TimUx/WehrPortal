<?php

namespace Modules\Ausruestung\src\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Ausruestung\src\Enums\DamageStatus;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Models\Damage;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Loan;

class AusruestungStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $inService = Equipment::inService()->count();
        $available = Equipment::where('status', EquipmentStatus::Available->value)->count();
        $due = Equipment::inspectionDue(30)->count();
        $overdueInspections = Equipment::inspectionDue()->count();
        $expired = EquipmentResource::whereLifetimeEndsBefore(Equipment::query(), today()->addDay())->count();
        $locked = Equipment::where('status', EquipmentStatus::Locked->value)->count();
        $damaged = Damage::whereIn('status', [DamageStatus::Open->value, DamageStatus::InRepair->value])->count();
        $overdueLoans = Loan::where('status', LoanStatus::Active->value)->where('due_at', '<', now())->count();

        return [
            Stat::make('Geräte im Bestand', $inService)->description($available.' einsatzbereit'),
            Stat::make('Prüfungen in 30 Tagen', $due)->description($overdueInspections.' überfällig')->color($overdueInspections > 0 ? 'danger' : ($due > 0 ? 'warning' : 'success')),
            Stat::make('Lebensdauer abgelaufen', $expired)->color($expired > 0 ? 'danger' : 'success'),
            Stat::make('Gesperrt / Schäden', $locked.' / '.$damaged)->description('Rückruf / offene Schäden')->color($locked + $damaged > 0 ? 'danger' : 'success'),
            Stat::make('Überfällige Ausleihen', $overdueLoans)->color($overdueLoans > 0 ? 'danger' : 'success'),
        ];
    }
}
