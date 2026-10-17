<?php

namespace Modules\Gefahrstoffe\src\Services;

use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Gefahrstoffe\src\Models\Material;

class MaterialImport
{
    private const MAX_ROWS = 10000;

    private const FIELDS = [
        'un_number' => ['un', 'un-nr', 'un-nr.', 'un nr', 'un-nummer', 'un-nummer (un no)', 'un number', 'un_number'],
        'name' => ['name', 'stoff', 'stoffname', 'stoffbezeichnung', 'gefahrstoffbezeichnung', 'offizielle bezeichnung', 'proper shipping name', 'bezeichnung'],
        'synonyms' => ['synonyme', 'synonyms', 'alias'],
        'description' => ['beschreibung', 'description'],
        'adr_class' => ['adr-klasse', 'adr klasse', 'klasse', 'class', 'adr_class'],
        'packing_group' => ['verpackungsgruppe', 'packing group', 'packing_group', 'vg'],
        'hazard_number' => ['gefahrnummer', 'gefahrnummer (kemler)', 'kemler', 'hazard number'],
        'hazard_labels' => ['gefahrzettel', 'gefahrzettelnummern', 'hazard labels', 'hazard_labels'],
        'ghs_pictograms' => ['ghs', 'ghs-piktogramme', 'ghs pictograms', 'ghs_pictograms'],
        'dangers' => ['gefahren', 'dangers'],
        'first_aid' => ['erste hilfe', 'erste-hilfe', 'first aid', 'first_aid'],
        'firefighting' => ['brandbekämpfung', 'brandbekaempfung', 'firefighting'],
        'spillage' => ['freisetzung', 'leckage', 'spillage'],
    ];

    /**
     * @return list<array{line: int, un_number: string, name: string, action: string, label: string, errors: list<string>}>
     */
    public function preview(string $contents): array
    {
        $rows = $this->readRows($contents);
        if ($rows === []) {
            throw ValidationException::withMessages(['import' => 'Die Datei enthält keine Datensätze.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['import' => 'Die Datei darf höchstens '.self::MAX_ROWS.' Datensätze enthalten.']);
        }

        $existing = Material::query()->pluck('un_number')->flip();
        $seen = [];
        $preview = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $validated = Validator::make($row, [
                'un_number' => ['required', 'regex:/^\d{4}$/'],
                'name' => ['required', 'string', 'max:255'],
                'synonyms' => ['nullable', 'string', 'max:2000'],
                'description' => ['nullable', 'string', 'max:10000'],
                'adr_class' => ['nullable', 'string', 'max:30'],
                'packing_group' => ['nullable', 'string', 'max:20'],
                'hazard_number' => ['nullable', 'string', 'max:20'],
                'hazard_labels' => ['array'],
                'hazard_labels.*' => ['string', 'max:100'],
                'ghs_pictograms' => ['array'],
                'ghs_pictograms.*' => ['string', 'max:100'],
                'dangers' => ['nullable', 'string', 'max:10000'],
                'first_aid' => ['nullable', 'string', 'max:10000'],
                'firefighting' => ['nullable', 'string', 'max:10000'],
                'spillage' => ['nullable', 'string', 'max:10000'],
            ])->errors();

            $un = (string) ($row['un_number'] ?? '');
            $errors = $validated->all();
            if ($un !== '' && isset($seen[$un])) {
                $errors['un_number'][] = 'UN-Nummer kommt in der Datei mehrfach vor.';
            }
            if ($un !== '') {
                $seen[$un] = true;
            }

            $action = $errors !== [] ? 'invalid' : (isset($existing[$un]) ? 'update' : 'new');
            $preview[] = [
                'line' => $line,
                'un_number' => $un,
                'name' => (string) ($row['name'] ?? ''),
                'action' => $action,
                'label' => match ($action) {
                    'new' => 'wird hinzugefügt',
                    'update' => 'wird aktualisiert',
                    default => implode(' ', array_merge(...array_values($errors))),
                },
                'errors' => array_merge(...array_values($errors)),
            ];
        }

        return $preview;
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function apply(string $contents, string $sourceName, ?string $sourceVersion, ?string $sourceReference, int $userId): array
    {
        $preview = $this->preview($contents);
        $invalid = collect($preview)->where('action', 'invalid');
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages(['import' => 'Bitte behebe zuerst alle fehlerhaften oder doppelten Zeilen in der Datei.']);
        }

        $rows = $this->readRows($contents);
        $now = now();
        $payload = collect($rows)->map(fn (array $row) => [
            ...$row,
            'hazard_labels' => json_encode($row['hazard_labels'] ?? [], JSON_THROW_ON_ERROR),
            'ghs_pictograms' => json_encode($row['ghs_pictograms'] ?? [], JSON_THROW_ON_ERROR),
            'source_name' => trim($sourceName),
            'source_version' => filled($sourceVersion) ? trim($sourceVersion) : null,
            'source_reference' => filled($sourceReference) ? trim($sourceReference) : null,
            'imported_by' => $userId,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $existingCount = collect($preview)->where('action', 'update')->count();
        DB::transaction(function () use ($payload) {
            foreach (array_chunk($payload, 500) as $batch) {
                Material::upsert($batch, ['un_number'], [
                    'name', 'synonyms', 'description', 'adr_class', 'packing_group', 'hazard_number',
                    'hazard_labels', 'ghs_pictograms', 'dangers', 'first_aid', 'firefighting', 'spillage',
                    'source_name', 'source_version', 'source_reference', 'imported_by', 'imported_at', 'updated_at',
                ]);
            }
        });

        $result = ['created' => count($payload) - $existingCount, 'updated' => $existingCount];
        app(ActivityRecorder::class)->record(
            'imported',
            Material::class,
            label: 'Import · '.trim($sourceName),
            changes: ['neu' => [null, $result['created']], 'aktualisiert' => [null, $result['updated']]],
            userId: $userId,
        );

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function readRows(string $contents): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (str_starts_with(ltrim($contents), '[') || str_starts_with(ltrim($contents), '{')) {
            $decoded = json_decode($contents, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages(['import' => 'Die JSON-Datei ist ungültig: '.json_last_error_msg()]);
            }
            if (is_array($decoded) && isset($decoded['materials']) && is_array($decoded['materials'])) {
                $decoded = $decoded['materials'];
            }
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw ValidationException::withMessages(['import' => 'JSON muss ein Array von Gefahrstoff-Datensätzen enthalten.']);
            }

            return array_map(fn ($row) => $this->complete(is_array($row) ? $this->normalize($row) : []), $decoded);
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $header = fgetcsv($stream, null, $delimiter, '"', '');
        if (! is_array($header)) {
            fclose($stream);
            throw ValidationException::withMessages(['import' => 'Die CSV-Datei enthält keine Kopfzeile.']);
        }
        $columns = [];
        foreach ($header as $index => $name) {
            $normalized = mb_strtolower(trim((string) $name));
            foreach (self::FIELDS as $field => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }
        if (! isset($columns['un_number'], $columns['name'])) {
            fclose($stream);
            throw ValidationException::withMessages(['import' => 'Die CSV-Kopfzeile braucht mindestens „UN-Nummer“ und „Name“.']);
        }

        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                continue;
            }
            $row = [];
            foreach ($columns as $field => $index) {
                $row[$field] = $this->value($field, (string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($stream);

        return array_map(fn (array $row) => $this->complete($row), $rows);
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $key = mb_strtolower(trim((string) $key));
            foreach (self::FIELDS as $field => $aliases) {
                if ($key === $field || in_array($key, $aliases, true)) {
                    $normalized[$field] = $this->value($field, $value);
                    break;
                }
            }
        }

        return $normalized;
    }

    private function value(string $field, mixed $value): mixed
    {
        if (in_array($field, ['hazard_labels', 'ghs_pictograms'], true)) {
            if (is_array($value)) {
                if (! array_is_list($value) || collect($value)->contains(fn ($item) => ! is_scalar($item))) {
                    throw ValidationException::withMessages(['import' => 'Listenwerte müssen einfache Textwerte enthalten.']);
                }

                return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $value)));
            }
            $text = trim((string) $value);
            if ($text === '') {
                return [];
            }
            $decoded = json_decode($text, true);

            if (is_array($decoded)) {
                if (! array_is_list($decoded) || collect($decoded)->contains(fn ($item) => ! is_scalar($item))) {
                    throw ValidationException::withMessages(['import' => 'Listenwerte müssen einfache Textwerte enthalten.']);
                }

                return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $decoded)));
            }

            return array_values(array_filter(array_map('trim', preg_split('/[|;]/', $text) ?: [])));
        }
        if (is_array($value)) {
            if (collect($value)->contains(fn ($item) => ! is_scalar($item))) {
                throw ValidationException::withMessages(['import' => 'Textfelder dürfen keine verschachtelten Werte enthalten.']);
            }
            $value = implode(' | ', array_map(fn ($item) => trim((string) $item), $value));
        }

        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function complete(array $row): array
    {
        return array_replace([
            'un_number' => null,
            'name' => null,
            'synonyms' => null,
            'description' => null,
            'adr_class' => null,
            'packing_group' => null,
            'hazard_number' => null,
            'hazard_labels' => [],
            'ghs_pictograms' => [],
            'dangers' => null,
            'first_aid' => null,
            'firefighting' => null,
            'spillage' => null,
        ], $row);
    }
}
