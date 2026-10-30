<?php

namespace Extensions\Locations\Services;

use App\Services\ModuleRegistry;
use InvalidArgumentException;

class StandardRoleCatalog
{
    /**
     * Profiles are identifiers, not permission payloads. Runtime permissions stay in code
     * so an assignment can never add arbitrary modules or roles.
     *
     * @var array<string, array{name: string, scope: string, group: ?string, modules: array<string, list<string>>}>
     */
    private const PROFILES = [
        'buergermeister' => ['name' => 'Bürgermeister', 'scope' => 'global', 'group' => null, 'modules' => []],
        'brandschutzamt' => ['name' => 'Brandschutzamt', 'scope' => 'global', 'group' => null, 'modules' => [
            'ausruestung' => ['geraetewart'],
            'kleiderkammer' => ['bestand', 'kleiderwart'],
        ]],
        'gemeindebrandinspektor' => ['name' => 'Gemeindebrandinspektor / Stadtbrandinspektor', 'scope' => 'global', 'group' => null, 'modules' => []],
        'gemeindejugendwart' => ['name' => 'Gemeindejugendwart', 'scope' => 'global', 'group' => 'jugendfeuerwehr', 'modules' => [
            'uebungsdienste' => ['gemeindejugendwart'],
            'anwesenheit' => ['gemeindejugendwart'],
            'personalverwaltung' => ['personalverwalter'],
        ]],
        'gemeindekinderwart' => ['name' => 'Gemeindekinderwart', 'scope' => 'global', 'group' => 'kinderfeuerwehr', 'modules' => [
            'uebungsdienste' => ['gemeindekinderwart'],
            'anwesenheit' => ['gemeindekinderwart'],
            'personalverwaltung' => ['personalverwalter'],
        ]],
        'atemschutzwart' => ['name' => 'Atemschutzwart', 'scope' => 'global', 'group' => null, 'modules' => [
            'atemschutz' => ['atemschutzwart'],
            'unterweisungen' => ['unterweisungsbeauftragter'],
        ]],
        'wehrfuehrung' => ['name' => 'Wehrführung', 'scope' => 'location', 'group' => null, 'modules' => [
            'anwesenheit' => ['verwalter'],
            'atemschutz' => ['atemschutzwart'],
            'ausruestung' => ['geraetewart'],
            'einsatzbericht' => ['wehrführung'],
            'finanzen' => ['wehrführung'],
            'fahrzeuge' => ['fahrzeugwart'],
            'fahrtenbuch' => ['fahrtenbuchwart'],
            'maengelmanagement' => ['verantwortlicher'],
            'personalverwaltung' => ['personalverwalter'],
            'uebungsdienste' => ['wehrführung'],
            'schichtplan' => ['planer'],
            'verbandbuch' => ['wehrführung'],
            'expositionen' => ['wehrführung'],
            'kalender' => ['terminverwalter'],
            'wartung' => ['geraetewart'],
        ]],
        'jugendwart' => ['name' => 'Jugendwart', 'scope' => 'group', 'group' => 'jugendfeuerwehr', 'modules' => [
            'uebungsdienste' => ['jugendwart'],
            'anwesenheit' => ['jugendwart'],
            'personalverwaltung' => ['personalverwalter'],
        ]],
        'kinderwart' => ['name' => 'Kinderwart', 'scope' => 'group', 'group' => 'kinderfeuerwehr', 'modules' => [
            'uebungsdienste' => ['kinderwart'],
            'anwesenheit' => ['kinderwart'],
            'personalverwaltung' => ['personalverwalter'],
        ]],
        'geraetewart' => ['name' => 'Gerätewart', 'scope' => 'location', 'group' => null, 'modules' => [
            'fahrzeuge' => ['fahrzeugwart'],
            'ausruestung' => ['geraetewart'],
            'maengelmanagement' => ['verantwortlicher'],
            'wartung' => ['geraetewart'],
        ]],
    ];

    public function all(): array
    {
        return self::PROFILES;
    }

    public function get(string $profile): array
    {
        return self::PROFILES[$profile] ?? throw new InvalidArgumentException('Unknown standard role profile.');
    }

    public function has(string $profile): bool
    {
        return isset(self::PROFILES[$profile]);
    }

    public function isAssignable(string $profile): bool
    {
        if ($profile === 'buergermeister') {
            return app(LeadershipAssignments::class)->projectionEnabled('einsatzbericht');
        }
        if ($profile === 'brandschutzamt') {
            return config('locations.enabled')
                && config('locations.clothing_stock_enabled')
                && config('locations.clothing_personal_enabled')
                && app(ModuleRegistry::class)->isActive('ausruestung')
                && app(ModuleRegistry::class)->isActive('kleiderkammer');
        }

        return in_array($profile, [
            'gemeindebrandinspektor',
            'gemeindejugendwart',
            'gemeindekinderwart',
            'atemschutzwart',
            'wehrfuehrung',
            'jugendwart',
            'kinderwart',
            'geraetewart',
        ], true);
    }

    public function hasModuleRole(string $profile, string $module, string $role): bool
    {
        return in_array($role, self::PROFILES[$profile]['modules'][$module] ?? [], true);
    }
}
