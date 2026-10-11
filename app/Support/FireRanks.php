<?php

namespace App\Support;

use App\Enums\MemberGender;

/** Dienstgrade der Feuerwehr in Schleswig-Holstein, in aufsteigender Reihenfolge, je Geschlecht */
class FireRanks
{
    private const MALE = [
        'Feuerwehrmann-Anwärter',
        'Feuerwehrmann',
        'Oberfeuerwehrmann',
        'Hauptfeuerwehrmann **',
        'Hauptfeuerwehrmann ***',
        'Löschmeister',
        'Oberlöschmeister',
        'Hauptlöschmeister **',
        'Hauptlöschmeister ***',
        'Brandmeister',
        'Oberbrandmeister',
        'Hauptbrandmeister **',
        'Hauptbrandmeister ***',
        'Erster Hauptbrandmeister',
        'Erster Hauptbrandmeister *',
        'Kreisbrandmeister (bzw. Stadtbrandmeister)',
        'Landesbrandmeister',
    ];

    private const FEMALE = [
        'Feuerwehrfrau-Anwärterin',
        'Feuerwehrfrau',
        'Oberfeuerwehrfrau',
        'Hauptfeuerwehrfrau **',
        'Hauptfeuerwehrfrau ***',
        'Löschmeisterin',
        'Oberlöschmeisterin',
        'Hauptlöschmeisterin **',
        'Hauptlöschmeisterin ***',
        'Brandmeisterin',
        'Oberbrandmeisterin',
        'Hauptbrandmeisterin **',
        'Hauptbrandmeisterin ***',
        'Erste Hauptbrandmeisterin',
        'Erste Hauptbrandmeisterin *',
        'Kreisbrandmeisterin (bzw. Stadtbrandmeisterin)',
        'Landesbrandmeisterin',
    ];

    /** The fixed list for male or female; null for diverse or unknown, where the rank is free text */
    public static function for(MemberGender|string|null $gender): ?array
    {
        $gender = $gender instanceof MemberGender ? $gender : MemberGender::tryFrom((string) $gender);

        return match ($gender) {
            MemberGender::Male => self::MALE,
            MemberGender::Female => self::FEMALE,
            default => null,
        };
    }

    /** @return array<string, string> value => label, as Select options */
    public static function options(MemberGender|string|null $gender): array
    {
        $list = self::for($gender) ?? [];

        return array_combine($list, $list);
    }

    /** Position of a rank in the lists (0 = Anwärter … 16 = Landesbrandmeister), whichever list it is on */
    public static function stepOf(?string $rank): ?int
    {
        foreach ([self::MALE, self::FEMALE] as $list) {
            $index = array_search($rank, $list, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    public static function maxStep(): int
    {
        return count(self::MALE) - 1;
    }

    /** "Oberfeuerwehrmann / Oberfeuerwehrfrau" for a step, or the form for the gender */
    public static function label(int $step, MemberGender|string|null $gender = null): ?string
    {
        $list = self::for($gender);

        return $list ? ($list[$step] ?? null) : (isset(self::MALE[$step]) ? self::MALE[$step].' / '.self::FEMALE[$step] : null);
    }

    /** The same rank in the other list ("Oberfeuerwehrmann" → "Oberfeuerwehrfrau"); null if it is not on a list */
    public static function translate(?string $rank, MemberGender|string|null $from, MemberGender|string|null $to): ?string
    {
        $fromList = self::for($from);
        $toList = self::for($to);

        if ($rank === null || $fromList === null || $toList === null) {
            return $rank;
        }

        $index = array_search($rank, $fromList, true);

        return $index === false ? $rank : $toList[$index];
    }
}
