<?php

namespace App\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktiv',
            self::Inactive => 'Inaktiv',
            self::Left => 'Ausgetreten',
        };
    }
}
