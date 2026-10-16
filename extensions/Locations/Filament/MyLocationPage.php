<?php

namespace Extensions\Locations\Filament;

use App\Filament\Concerns\IsModuleArea;
use App\Filament\ModuleArea;
use App\Models\User;
use Extensions\Locations\Filament\Resources\LocationResource;
use Extensions\Locations\Services\LocationAccess;
use Filament\Pages\Page;

// Entry of the Standort-Admins into /admin: it lets them into the panel (ModuleArea) and sends them on
// to their Standort. Global admins use the Standorte resource directly.
class MyLocationPage extends Page implements ModuleArea
{
    use IsModuleArea;

    protected static ?string $slug = 'standort';

    protected static ?string $title = 'Mein Standort';

    protected string $view = 'locations::filament.my-location';

    public static function allows(User $user): bool
    {
        return app(LocationAccess::class)->isLocationAdmin($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->redirect(LocationResource::getUrl('index'));
    }
}
