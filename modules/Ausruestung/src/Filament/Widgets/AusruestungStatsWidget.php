<?php

namespace Modules\Ausruestung\src\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Ausruestung\src\Enums\DamageStatus;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Enums\LoanStatus;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Models\Damage;
use Modules\Ausruestung\src\Models\Loan;
use Modules\Ausruestung\src\Services\EquipmentService;

class AusruestungStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $equipment = EquipmentService::managedEquipmentFor($user);
        $locationIds = EquipmentService::equipmentManagementScope($user);
        $damages = Damage::query()->when($locationIds !== null, fn ($query) => $query->whereHas(
            'equipment',
            fn ($equipmentQuery) => $equipmentQuery->whereIn('ext_location_id', $locationIds),
        ));
        $loans = Loan::query()->when($locationIds !== null, fn ($query) => $query->whereHas(
            'equipment',
            fn ($equipmentQuery) => $equipmentQuery->whereIn('ext_location_id', $locationIds),
        ));
        $inService = (clone $equipment)->inService()->count();
        $available = (clone $equipment)->where('status', EquipmentStatus::Available->value)->count();
        $due = (clone $equipment)->inspectionDue(30)->count();
        $overdueInspections = (clone $equipment)->inspectionDue()->count();
        $expired = EquipmentResource::whereLifetimeEndsBefore(clone $equipment, today()->addDay())->count();
        $locked = (clone $equipment)->where('status', EquipmentStatus::Locked->value)->count();
        $damaged = $damages->whereIn('status', [DamageStatus::Open->value, DamageStatus::InRepair->value])->count();
        $overdueLoans = $loans->where('status', LoanStatus::Active->value)->where('due_at', '<', now())->count();

        return [
            Stat::make('Geräte im Bestand', $inService)->description($available.' einsatzbereit'),
            Stat::make('Prüfungen in 30 Tagen', $due)->description($overdueInspections.' überfällig')->color($overdueInspections > 0 ? 'danger' : ($due > 0 ? 'warning' : 'success')),
            Stat::make('Lebensdauer abgelaufen', $expired)->color($expired > 0 ? 'danger' : 'success'),
            Stat::make('Gesperrt / Schäden', $locked.' / '.$damaged)->description('Rückruf / offene Schäden')->color($locked + $damaged > 0 ? 'danger' : 'success'),
            Stat::make('Überfällige Ausleihen', $overdueLoans)->color($overdueLoans > 0 ? 'danger' : 'success'),
        ];
    }
}
