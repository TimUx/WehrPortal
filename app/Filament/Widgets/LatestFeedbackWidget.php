<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FeedbackThreadResource;
use App\Models\FeedbackThread;
use Filament\Widgets\Widget;

/** Admin dashboard: the last five feedback conversations, newest message first */
class LatestFeedbackWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.latest-feedback';

    protected function getViewData(): array
    {
        return [
            'threads' => FeedbackThread::with('user')->orderByDesc('last_message_at')->orderByDesc('id')->limit(5)->get(),
            'url' => FeedbackThreadResource::getUrl('index'),
        ];
    }
}
