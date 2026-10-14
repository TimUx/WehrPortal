<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use Filament\Pages\Page;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Services\InventoryReport;

class InventoryReportPage extends Page
{
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/bestandsbericht';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Bestandsbericht';

    protected static ?string $title = 'Bestandsbericht';

    protected static ?int $navigationSort = 10;

    protected string $view = 'kleiderkammer::filament.pages.inventory-report';

    protected function getViewData(): array
    {
        $report = app(InventoryReport::class);

        return [
            'trackedByType' => $report->trackedByType(),
            'stockByType' => $report->stockByType(),
            'openLoans' => $report->openLoans(),
            'summary' => $report->summary(),
        ];
    }
}
