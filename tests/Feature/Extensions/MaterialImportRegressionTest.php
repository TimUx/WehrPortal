<?php

use Illuminate\Validation\ValidationException;
use Modules\Gefahrstoffe\src\Services\MaterialImport;

it('keeps invalid and duplicate import errors as flat strings in the preview', function () {
    $import = app(MaterialImport::class);
    $csv = "UN-Nummer,Name\n12,\n12,";
    $preview = $import->preview($csv);

    expect($preview)->toHaveCount(2);
    foreach ($preview as $row) {
        expect($row['action'])->toBe('invalid')
            ->and($row['errors'])->not->toBeEmpty()
            ->and($row['label'])->toBe(implode(' ', $row['errors']));
        foreach ($row['errors'] as $error) {
            expect($error)->toBeString();
        }
    }
    expect($preview[1]['errors'])->toContain('UN-Nummer kommt in der Datei mehrfach vor.');
    expect(fn () => $import->apply($csv, 'Quelle Muster', null, null, 1))
        ->toThrow(ValidationException::class);
});
