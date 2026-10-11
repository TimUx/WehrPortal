<?php

namespace App\Filament\Widgets;

use App\Services\ServiceHealth;
use Filament\Widgets\Widget;

/** Admin dashboard: do mail, Google and OpenRouter work? */
class ServiceStatusWidget extends Widget
{
    protected static bool $isLazy = true;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.service-status';

    public bool $fresh = false;

    public function recheck(): void
    {
        $this->fresh = true;
    }

    protected function getViewData(): array
    {
        $status = app(ServiceHealth::class)->status($this->fresh);
        $this->fresh = false;

        return ['status' => $status];
    }
}
