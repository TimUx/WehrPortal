<?php

namespace Modules\Kleiderkammer\src\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Kleiderkammer\src\Services\InventoryReport;

class OverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $report = app(InventoryReport::class)->summary();

        return [
            Stat::make('Verfügbar', $report['available'])
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Ausgegeben', $report['issued'])
                ->icon('heroicon-o-arrow-right-circle')
                ->color('warning'),

            Stat::make('Ausgemustert', $report['retired'])
                ->icon('heroicon-o-x-circle')
                ->color('danger'),

            Stat::make('Offene Leihen', $report['open_loans'])
                ->icon('heroicon-o-clock')
                ->color('primary'),
        ];
    }
}
