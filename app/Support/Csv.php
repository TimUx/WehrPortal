<?php

namespace App\Support;

/**
 * fputcsv() for exports that people open in Excel or LibreOffice: a cell that starts with = + - @
 * (or a tab/CR) would run as a formula there – e.g. =HYPERLINK(…) typed into a comment. Such cells
 * get a leading apostrophe; plain numbers (also negative ones like -12,50) stay numbers.
 */
class Csv
{
    public static function put($handle, array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\'): int|false
    {
        return fputcsv($handle, array_map(self::cell(...), $fields), $separator, $enclosure, $escape);
    }

    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || preg_match('/^[-+]?[\d.,]+%?$/', $value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
