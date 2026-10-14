<?php

namespace App\Filament;

use App\Filament\Concerns\IsModuleArea;
use BackedEnum;
use Filament\Pages\Dashboard;

// The start page of a module's area with widgets (/admin/<key>); its navigation group holds the
// module's resources and pages (see ModuleArea).
abstract class ModuleDashboard extends Dashboard implements ModuleArea
{
    use IsModuleArea;

    protected static ?string $navigationLabel = 'Übersicht';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -10;
}
