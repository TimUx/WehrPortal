<?php

namespace Modules\Maengelmanagement\src\Enums;

enum Urgency: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Niedrig',
            self::Medium => 'Mittel',
            self::High => 'Hoch',
            self::Critical => 'Kritisch',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Low => 'Kann warten, stört nicht.',
            self::Medium => 'Sollte bald behoben werden.',
            self::High => 'Beeinträchtigt den Einsatz oder Dienst.',
            self::Critical => 'Gefahr oder nicht einsatzbereit – sofort.',
        };
    }

    /** Filament colour name */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'info',
            self::High => 'warning',
            self::Critical => 'danger',
        };
    }

    /** Tailwind classes for the member pages */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Low => 'bg-gray-100 text-gray-700',
            self::Medium => 'bg-blue-100 text-blue-800',
            self::High => 'bg-amber-100 text-amber-800',
            self::Critical => 'bg-red-100 text-red-800',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $u) => [$u->value => $u->label()])->all();
    }
}
