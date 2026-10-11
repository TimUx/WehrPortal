<?php

namespace App\Filament\Widgets;

use App\Services\Birthdays;
use App\Support\MemberFields;
use Filament\Widgets\Widget;

/** Admin dashboard: the next birthdays with age */
class BirthdaysWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.birthdays';

    /** Off while the birth date is switched off in MemberFields */
    public static function canView(): bool
    {
        return MemberFields::BIRTH_DATE;
    }

    protected function getViewData(): array
    {
        return ['birthdays' => app(Birthdays::class)->upcoming(5)];
    }
}
