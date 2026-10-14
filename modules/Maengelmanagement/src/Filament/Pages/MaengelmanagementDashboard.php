<?php

namespace Modules\Maengelmanagement\src\Filament\Pages;

use App\Filament\ModuleDashboard;
use App\Models\User;

// /admin/maengelmanagement: the Mängelmanagement area of the admin panel
class MaengelmanagementDashboard extends ModuleDashboard
{
    protected static string $routePath = 'maengelmanagement';

    protected static ?string $slug = 'maengelmanagement';

    protected static ?string $title = 'Mängelmanagement';

    protected static string|\UnitEnum|null $navigationGroup = 'Mängelmanagement';

    // Admins and Verantwortliche
    public static function allows(User $user): bool
    {
        return $user->canManageMaengel();
    }

    public function getWidgets(): array
    {
        return [];
    }
}
