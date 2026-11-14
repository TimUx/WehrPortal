<?php

namespace Extensions\Locations\Services;

use App\Models\Setting;
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
            // Reads the Einsatzberichte of every Standort, changes none
            'einsatzbericht' => ['einsicht'],
        ]],
        'gemeindebrandinspektor' => ['name' => 'Gemeindebrandinspektor / Stadtbrandinspektor', 'scope' => 'global', 'group' => null, 'modules' => []],
        'gemeindejugendwart' => ['name' => 'Gemeindejugendwart', 'scope' => 'global', 'group' => 'jugendfeuerwehr', 'modules' => [
            'uebungsdienste' => ['gemeindejugendwart'],
            // Termine for the own Abteilung only (User::canManageKalenderGroups)
            'kalender' => ['terminverwalter'],
            'anwesenheit' => ['gemeindejugendwart'],
            'personalverwaltung' => ['personalverwalter'],
        ]],
        'gemeindekinderwart' => ['name' => 'Gemeindekinderwart', 'scope' => 'global', 'group' => 'kinderfeuerwehr', 'modules' => [
            'uebungsdienste' => ['gemeindekinderwart'],
            // Termine for the own Abteilung only (User::canManageKalenderGroups)
            'kalender' => ['terminverwalter'],
            'anwesenheit' => ['gemeindekinderwart'],
            'personalverwaltung' => ['personalverwalter'],
        ]],
        'atemschutzwart' => ['name' => 'Atemschutzgerätewart', 'scope' => 'global', 'group' => null, 'modules' => [
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
            'fahrzeugbuchung' => ['entscheider'],
            // Records that hang on people: for the members of the Standort (managedMemberIds)
            'fuehrerscheine' => ['fuehrerscheinpruefer'],
            'lehrgaenge' => ['wehrführung'],
            'unterweisungen' => ['unterweisungsbeauftragter'],
            'hydrantenkontrolle' => ['hydrantenwart'],
            'fahrtenbuch' => ['fahrtenbuchwart'],
            'maengelmanagement' => ['verantwortlicher'],
            'personalverwaltung' => ['personalverwalter'],
            'uebungsdienste' => ['wehrführung'],
            'schichtplan' => ['planer'],
            'verbandbuch' => ['wehrführung'],
            'expositionen' => ['wehrführung'],
            'kalender' => ['terminverwalter'],
            'wartung' => ['geraetewart'],
            // Only with the Standort adapters of the Kleiderkammer (LocationModuleRoles::capabilityLocationIds):
            // without them the Kleiderkammer is one store for the whole Kommune
            'kleiderkammer' => ['bestand', 'kleiderwart'],
            // Records of the whole Kommune without a Standort of their own: kept by every Wehrführung
            'schluessel' => ['schluesselwart'],
            'objektplaene' => ['planverwalter'],
            'wasserkarte' => ['hydrantenwart'],
            'gefahrstoffe' => ['gefahrstoffbeauftragter'],
        ]],
        'jugendwart' => ['name' => 'Jugendwart', 'scope' => 'group', 'group' => 'jugendfeuerwehr', 'modules' => [
            'uebungsdienste' => ['jugendwart'],
            // Termine for the own Abteilung only (User::canManageKalenderGroups)
            'kalender' => ['terminverwalter'],
            'anwesenheit' => ['jugendwart'],
            'personalverwaltung' => ['personalverwalter'],
        ]],
        'kinderwart' => ['name' => 'Kinderwart', 'scope' => 'group', 'group' => 'kinderfeuerwehr', 'modules' => [
            'uebungsdienste' => ['kinderwart'],
            // Termine for the own Abteilung only (User::canManageKalenderGroups)
            'kalender' => ['terminverwalter'],
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

    /** Setting: is the Kommune a Gemeinde or a Stadt? Unset keeps the neutral double names. */
    public const MUNICIPALITY_SETTING = 'locations.municipality';

    public const MUNICIPALITIES = ['gemeinde' => 'Gemeinde', 'stadt' => 'Stadt'];

    /** Names of the Kommune-wide positions in a Stadt */
    private const CITY_NAMES = [
        'gemeindebrandinspektor' => 'Stadtbrandinspektor',
        'gemeindejugendwart' => 'Stadtjugendwart',
        'gemeindekinderwart' => 'Stadtkinderwart',
    ];

    /** 'gemeinde', 'stadt' or null while nobody has chosen */
    public function municipality(): ?string
    {
        $value = Setting::get(self::MUNICIPALITY_SETTING);

        return is_string($value) && isset(self::MUNICIPALITIES[$value]) ? $value : null;
    }

    /** "Gemeinde" or "Stadt" in running text; "Gemeinde" while nobody has chosen */
    public function municipalityWord(): string
    {
        return self::MUNICIPALITIES[$this->municipality() ?? 'gemeinde'];
    }

    /** What a profile is called here: the keys stay, a Stadt has a Stadtbrandinspektor */
    public function name(string $profile, bool $deputy = false): string
    {
        $name = $this->get($profile)['name'];
        $municipality = $this->municipality();
        if ($municipality === 'stadt' && isset(self::CITY_NAMES[$profile])) {
            $name = self::CITY_NAMES[$profile];
        } elseif ($municipality === 'gemeinde' && $profile === 'gemeindebrandinspektor') {
            $name = 'Gemeindebrandinspektor';
        }

        return $deputy ? 'Stv. '.$name : $name;
    }

    /** The short form used in running text: GBI, SBI, or both while nobody has chosen */
    public function inspector(): string
    {
        return match ($this->municipality()) {
            'gemeinde' => 'GBI',
            'stadt' => 'SBI',
            default => 'GBI/SBI',
        };
    }

    /** @return list<string> lower-case names of the Abteilungen that profiles are tied to */
    public function groupNames(): array
    {
        return array_values(array_unique(array_filter(array_column(self::PROFILES, 'group'))));
    }

    /** Why a profile can't be given yet; null when it can */
    public function notAssignableReason(string $profile): ?string
    {
        if ($this->isAssignable($profile)) {
            return null;
        }

        return match ($profile) {
            'buergermeister' => 'braucht die Führungsübersicht (LOCATIONS_LEADERSHIP_ENABLED=true) und das aktive Modul Einsatzbericht',
            'brandschutzamt' => 'braucht die aktiven Module Ausrüstung und Kleiderkammer sowie LOCATIONS_CLOTHING_STOCK_ENABLED=true und LOCATIONS_CLOTHING_PERSONAL_ENABLED=true',
            default => 'ist nicht freigegeben',
        };
    }

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
