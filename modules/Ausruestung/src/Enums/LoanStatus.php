<?php

namespace Modules\Ausruestung\src\Enums;

enum LoanStatus: string
{
    case Reserved = 'reserved';
    case Active = 'active';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reserviert',
            self::Active => 'Ausgeliehen',
            self::Returned => 'Zurückgegeben',
            self::Cancelled => 'Storniert',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Reserved => 'info',
            self::Active => 'warning',
            self::Returned => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** Still blocks the equipment for that period */
    public function isOpen(): bool
    {
        return in_array($this, [self::Reserved, self::Active], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
