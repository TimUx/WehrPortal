<?php

namespace App\Filament\Pages;

use App\Filament\ModuleDashboard;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

// /admin itself. Admins see the core widgets; module managers (Kleiderwarte …) have no core area
// and are sent on to the first module area they manage.
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $user = auth()->user();

        if (! $user->is_admin && ($first = ModuleDashboard::accessibleFor($user)[0] ?? null)) {
            $this->redirect($first::getUrl());
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function getWidgets(): array
    {
        // The module dashboards bring their own widgets
        return array_values(array_filter(
            Filament::getWidgets(),
            fn ($widget) => is_string($widget) && str_starts_with($widget, 'App\\Filament\\Widgets\\'),
        ));
    }
}
