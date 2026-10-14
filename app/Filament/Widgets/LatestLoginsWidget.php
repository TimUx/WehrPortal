<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LogResource;
use App\Models\ActivityLog;
use Filament\Widgets\Widget;

/** Admin dashboard: the last five logins, newest first */
class LatestLoginsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.latest-logins';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    protected function getViewData(): array
    {
        return [
            'logins' => ActivityLog::with('user')->where('event', 'login')->latest('id')->limit(5)->get(),
            'url' => LogResource::getUrl('index'),
        ];
    }
}
