<?php

namespace Modules\Kleiderkammer\src\Enums;

enum TreatmentType: string
{
    case Washing = 'washing';
    case Impregnation = 'impregnation';

    public function label(): string
    {
        return match ($this) {
            self::Washing => 'Wäsche',
            self::Impregnation => 'Imprägnierung',
        };
    }
}
