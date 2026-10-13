<?php

namespace Modules\Einsatzbericht\src\Support;

use Illuminate\Support\Collection;

/**
 * The guided Zusatzangaben: depending on the Einsatzart the report asks for what matters there
 * (Brandobjekt and -ursache, Art der Technischen Hilfe, Ursache eines Fehlalarms …). Answers are
 * stored in eb_reports.details; the select fields are counted in the Jahresstatistik.
 *
 * Field: [label, type (select|text|number|bool|water), options?, required?]
 */
class ReportDetails
{
    private const BRAND = [
        'object_type' => ['Brandobjekt', 'select', ['Wohngebäude', 'Gewerbe / Industrie', 'Landwirtschaft', 'Öffentliches Gebäude', 'Fahrzeug', 'Wald / Flur / Vegetation', 'Müll / Container', 'Sonstiges'], true],
        'spread' => ['Ausdehnung', 'select', ['Entstehungsbrand', 'Auf den Brandherd beschränkt', 'Raum', 'Geschoss', 'Gebäude / Objekt', 'Übergegriffen auf Nachbarobjekte']],
        'cause' => ['Vermutete Brandursache', 'select', ['Unbekannt', 'Elektrizität', 'Feuerstätte / Heizung', 'Offenes Feuer / Rauchen', 'Brandstiftung', 'Blitzschlag', 'Selbstentzündung', 'Technischer Defekt', 'Fahrlässigkeit', 'Sonstiges']],
        'smoke_detector' => ['Rauchwarnmelder', 'select', ['Vorhanden und ausgelöst', 'Vorhanden, nicht ausgelöst', 'Nicht vorhanden', 'Unbekannt']],
        'agent' => ['Löschmittel', 'select', ['Wasser', 'Schaum', 'Pulver', 'CO₂', 'Kein Löschmittel nötig']],
        'water_source' => ['Wasserentnahme', 'water'],
    ];

    private const FIELDS = [
        'brand' => self::BRAND,
        'th' => [
            'kind' => ['Art der Hilfeleistung', 'select', ['Verkehrsunfall', 'Person in Notlage / Türöffnung', 'Tierrettung', 'Unwetter / Sturm', 'Wasserschaden', 'Ölspur / Betriebsmittel', 'Gefahrstoff', 'Unterstützung Rettungsdienst', 'Sonstiges'], true],
            'trapped' => ['Eingeklemmte / eingeschlossene Personen', 'number'],
            'vehicles_involved' => ['Beteiligte Fahrzeuge (bei Verkehrsunfall)', 'number'],
            'hazmat' => ['Gefahrstoff (Stoff / UN-Nummer)', 'text'],
        ],
        'fehlalarm' => [
            'alarm_kind' => ['Art des Fehlalarms', 'select', ['Brandmeldeanlage', 'Rauchwarnmelder (privat)', 'Gut gemeint (blinder Alarm)', 'Böswillig', 'Sonstiges'], true],
            'bma_cause' => ['Auslöser (Brandmeldeanlage)', 'select', ['Täuschung (Staub, Dampf, Rauch)', 'Technischer Defekt', 'Arbeiten an der Anlage', 'Handfeuermelder missbräuchlich', 'Unbekannt']],
            'bma_group' => ['Meldergruppe / Melder', 'text'],
            'chargeable' => ['Kostenersatz prüfen', 'bool'],
        ],
        'first_responder' => [
            'outcome' => ['Verlauf', 'select', ['An Rettungsdienst übergeben', 'Reanimation durchgeführt', 'Kein Patient angetroffen', 'Sonstiges'], true],
            'aed' => ['AED eingesetzt', 'bool'],
        ],
    ];

    /** Which group of fields a category asks for */
    public static function group(?string $category): ?string
    {
        return match (true) {
            $category === null => null,
            str_starts_with($category, 'brand') => 'brand',
            isset(self::FIELDS[$category]) => $category,
            default => null,
        };
    }

    /** @return array<string, array{0: string, 1: string, 2?: list<string>, 3?: bool}> */
    public static function fields(?string $category): array
    {
        $group = self::group($category);

        return $group ? self::FIELDS[$group] : [];
    }

    /** Only the answers that belong to the category, cleaned up */
    public static function clean(?string $category, array $answers): array
    {
        $clean = [];
        foreach (self::fields($category) as $key => $field) {
            $value = $answers[$key] ?? null;
            $value = match ($field[1]) {
                'bool' => (bool) $value,
                'number' => is_numeric($value) ? max(0, min(9999, (int) $value)) : null,
                'select' => in_array($value, $field[2], true) ? $value : null,
                default => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 200) : null,
            };
            if ($value !== null && $value !== false) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @return list<string> labels of the required Zusatzangaben still empty */
    public static function missing(?string $category, ?array $answers): array
    {
        return collect(self::fields($category))->filter(fn ($f, $key) => ($f[3] ?? false) && blank($answers[$key] ?? null))
            ->map(fn ($f) => $f[0])->values()->all();
    }

    /** @return list<array{label: string, value: string}> for the report page and print */
    public static function display(?string $category, ?array $answers): array
    {
        return collect(self::fields($category))->filter(fn ($f, $key) => filled($answers[$key] ?? null))
            ->map(fn ($f, $key) => ['label' => $f[0], 'value' => $f[1] === 'bool' ? 'ja' : (string) $answers[$key]])->values()->all();
    }

    /**
     * Counts of the select answers over reports, for the Jahresstatistik.
     *
     * @return Collection<int, array{label: string, rows: Collection}>
     */
    public static function statistics(Collection $reports): Collection
    {
        $out = collect();
        foreach (self::FIELDS as $group => $fields) {
            $inGroup = $reports->filter(fn ($r) => self::group($r->category) === $group);
            foreach ($fields as $key => $field) {
                if (! in_array($field[1], ['select', 'bool'], true)) {
                    continue;
                }
                $rows = $inGroup->map(fn ($r) => $r->details[$key] ?? null)->filter()
                    ->map(fn ($v) => $v === true ? 'ja' : $v)->countBy()->sortDesc();
                if ($rows->isNotEmpty()) {
                    $out->push(['label' => $field[0], 'rows' => $rows]);
                }
            }
        }

        return $out;
    }
}
