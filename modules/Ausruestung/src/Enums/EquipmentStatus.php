<?php

namespace Modules\Ausruestung\src\Enums;

enum EquipmentStatus: string
{
    case Available = 'available';
    case Loaned = 'loaned';
    case Defective = 'defective';
    case InRepair = 'in_repair';
    case Locked = 'locked';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Einsatzbereit',
            self::Loaned => 'Ausgeliehen',
            self::Defective => 'Defekt',
            self::InRepair => 'In Reparatur',
            self::Locked => 'Gesperrt (Rückruf)',
            self::Retired => 'Ausgesondert',
        };
    }

    /** Filament colour name */
    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Loaned => 'info',
            self::Defective, self::Locked => 'danger',
            self::InRepair => 'warning',
            self::Retired => 'gray',
        };
    }

    /** Tailwind classes for the member pages */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Available => 'bg-green-100 text-green-800',
            self::Loaned => 'bg-blue-100 text-blue-800',
            self::Defective, self::Locked => 'bg-red-100 text-red-800',
            self::InRepair => 'bg-amber-100 text-amber-800',
            self::Retired => 'bg-gray-100 text-gray-700',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
