<?php

namespace App\Support;

/** Amounts are kept in cent; shown and typed the German way ("1.234,56 €"). Used by Finanzen and Wartung. */
class Money
{
    public static function format(int $cents, bool $sign = false): string
    {
        $text = number_format(abs($cents) / 100, 2, ',', '.').' €';

        return ($cents < 0 ? '−' : ($sign && $cents > 0 ? '+' : '')).$text;
    }

    /** "1.234,56", "12,5", "12.50", "12" → cent; null when it is no amount */
    public static function parse(?string $input): ?int
    {
        $value = str_replace([' ', '€', "\u{00A0}"], '', trim((string) $input));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);   // "1.234" is a thousand
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /** For an input field: 1234,50 */
    public static function input(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    }
}
