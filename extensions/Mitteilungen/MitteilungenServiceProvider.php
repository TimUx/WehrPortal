<?php

namespace Extensions\Mitteilungen;

use Extensions\Mitteilungen\Filament\MitteilungenChannelsPlugin;
use Extensions\Mitteilungen\Services\AnnouncementDelivery;
use Extensions\Mitteilungen\Services\ChannelSettings;
use Filament\Facades\Filament;
use Illuminate\Support\ServiceProvider;

class MitteilungenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelSettings::class);
        $this->app->singleton(AnnouncementDelivery::class);

        $this->app->booting(fn () => Filament::getPanel('admin')?->plugin(MitteilungenChannelsPlugin::make()));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'mitteilungen-channels');
    }
}
