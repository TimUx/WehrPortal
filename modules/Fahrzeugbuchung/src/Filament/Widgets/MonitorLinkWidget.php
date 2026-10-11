<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Widgets;

use Filament\Widgets\Widget;

class MonitorLinkWidget extends Widget
{
    protected string $view = 'fahrzeugbuchung::filament.widgets.monitor-link';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $token = config('monitor.token');

        return [
            'adminUrl' => route('fahrzeugbuchung.monitor'),
            'stationUrl' => $token ? route('fahrzeugbuchung.monitor', ['key' => $token]) : null,
        ];
    }
}
