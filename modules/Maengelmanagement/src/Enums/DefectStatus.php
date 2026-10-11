<?php

namespace Modules\Maengelmanagement\src\Enums;

enum DefectStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::InProgress => 'In Bearbeitung',
            self::Resolved => 'Erledigt',
            self::Rejected => 'Abgelehnt',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::InProgress => 'warning',
            self::Resolved => 'success',
            self::Rejected => 'gray',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-red-100 text-red-800',
            self::InProgress => 'bg-amber-100 text-amber-800',
            self::Resolved => 'bg-green-100 text-green-800',
            self::Rejected => 'bg-gray-100 text-gray-700',
        };
    }

    /** Still needs someone to act */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::InProgress], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
