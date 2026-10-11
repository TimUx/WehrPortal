<?php

namespace Modules\Ausruestung\src\Enums;

enum DamageStatus: string
{
    case Open = 'open';
    case InRepair = 'in_repair';
    case Repaired = 'repaired';
    case Scrapped = 'scrapped';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Gemeldet',
            self::InRepair => 'In Reparatur',
            self::Repaired => 'Repariert',
            self::Scrapped => 'Ausgesondert',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::InRepair => 'warning',
            self::Repaired => 'success',
            self::Scrapped => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::InRepair], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
