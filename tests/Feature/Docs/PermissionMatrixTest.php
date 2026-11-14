<?php

use App\Services\ModuleRegistry;
use Extensions\Locations\Services\StandardRoleCatalog;

/**
 * docs/BERECHTIGUNGEN.md is written by hand. Its tables of the standard roles name the module
 * roles with their keys in backticks, so they can be compared with the catalogue here.
 *
 * @return array{cells: array<string, array<string, list<string>>>, modules: list<string>}
 */
function documentedStandardRoles(): array
{
    $profiles = array_keys(app(StandardRoleCatalog::class)->all());
    $keys = fn (string $cell): array => preg_match_all('/`([^`]+)`/u', $cell, $found) ? $found[1] : [];
    $columns = [];
    $cells = [];
    $modules = [];

    foreach (file(base_path('docs/BERECHTIGUNGEN.md'), FILE_IGNORE_NEW_LINES) as $line) {
        if (! str_starts_with($line, '|')) {
            $columns = [];

            continue;
        }
        $row = array_map('trim', explode('|', trim($line, '|')));
        if ($columns === []) {
            // A header of a standard role table: columns that name a profile
            foreach ($row as $index => $cell) {
                $profile = array_values(array_intersect($keys($cell), $profiles))[0] ?? null;
                if ($profile !== null) {
                    $columns[$index] = $profile;
                }
            }
            $columns = $columns === [] ? [-1 => null] : $columns;   // another table: skip its rows

            continue;
        }
        $module = $keys($row[0])[0] ?? null;
        if ($module === null || array_key_first($columns) === -1) {
            continue;
        }
        $modules[] = $module;
        foreach ($columns as $index => $profile) {
            $cells[$profile][$module] = $keys($row[$index] ?? '');
        }
    }

    return ['cells' => $cells, 'modules' => array_values(array_unique($modules))];
}

it('documents the module roles of every standard role as the catalogue defines them', function () {
    $documented = documentedStandardRoles();

    foreach (app(StandardRoleCatalog::class)->all() as $profile => $definition) {
        if ($definition['modules'] === []) {
            continue;   // GBI/SBI and Bürgermeister are described in prose
        }
        expect($documented['cells'])->toHaveKey($profile);
        foreach ($definition['modules'] as $module => $roles) {
            expect($documented['cells'][$profile][$module] ?? null)->toEqualCanonicalizing($roles, "docs/BERECHTIGUNGEN.md: {$profile} in {$module}");
        }
        // Nothing documented that the profile does not give
        foreach ($documented['cells'][$profile] as $module => $roles) {
            expect($roles)->toEqualCanonicalizing($definition['modules'][$module] ?? [], "docs/BERECHTIGUNGEN.md: {$profile} in {$module}");
        }
    }
});

it('lists every module and every classic module role in the Berechtigungsmatrix', function () {
    $documented = documentedStandardRoles();
    $text = file_get_contents(base_path('docs/BERECHTIGUNGEN.md'));
    $classic = substr($text, strpos($text, '## Klassische Modulrollen'), strpos($text, '## Admin-Bereich') - strpos($text, '## Klassische Modulrollen'));

    foreach (app(ModuleRegistry::class)->all() as $key => $module) {
        expect($documented['modules'])->toContain($key);
        foreach ($module['roles'] ?? [] as $role) {
            expect($classic)->toContain('`'.$role.'`');
        }
    }
});
