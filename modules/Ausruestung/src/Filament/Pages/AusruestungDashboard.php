<?php

namespace Modules\Ausruestung\src\Filament\Pages;

use App\Filament\ModuleDashboard;
use App\Models\User;
use Modules\Ausruestung\src\Filament\Widgets\AusruestungStatsWidget;

// /admin/ausruestung: the Ausrüstung area of the admin panel
class AusruestungDashboard extends ModuleDashboard
{
    protected static string $routePath = 'ausruestung';

    protected static ?string $slug = 'ausruestung';

    protected static ?string $title = 'Ausrüstung';

    protected static string|\UnitEnum|null $navigationGroup = 'Ausrüstung';

    // Admins and Gerätewarte
    public static function allows(User $user): bool
    {
        return $user->canManageAusruestung();
    }

    public function getWidgets(): array
    {
        return [
            AusruestungStatsWidget::class,
        ];
    }
}
