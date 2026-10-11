<?php

namespace Modules\Kleiderkammer\src\Enums;

enum ItemStatus: string
{
    case Available = 'available';
    case Issued = 'issued';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Verfügbar',
            self::Issued => 'Ausgegeben',
            self::Retired => 'Ausgemustert',
        };
    }
}
