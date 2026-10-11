<?php

namespace App\Enums;

enum MemberGender: string
{
    case Male = 'male';
    case Female = 'female';
    case Diverse = 'diverse';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Männlich',
            self::Female => 'Weiblich',
            self::Diverse => 'Divers',
        };
    }
}
