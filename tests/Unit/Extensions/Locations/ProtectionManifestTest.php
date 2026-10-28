<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions\Locations;

use Extensions\Locations\Services\ProtectionManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProtectionManifestTest extends TestCase
{
    private ProtectionManifest $guard;

    private array $manifest;

    private array $schemaSnapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(ProtectionManifest::class);
        $this->manifest = config('locations.protection');
        $this->manifest['infrastructure'] = [];
        $this->manifest['optional_extensions'] = [];
        $this->manifest['tables'] = array_intersect_key($this->manifest['tables'], array_flip(['places', 'dress_codes']));
        $this->manifest['tables']['dress_codes']['mode'] = 'standortgebunden';
        $this->manifest['tables']['dress_codes']['owner'] = 'Synthetic child of places for schema-guard regression tests.';
        $this->manifest['tables']['dress_codes']['parents'] = [[
            'child_column' => 'place_id',
            'table' => 'places',
            'column' => 'id',
            'kind' => 'foreign_key',
            'source_null_rule' => 'forbidden',
            'optional_column' => false,
            'absence_reason' => null,
        ]];
        $this->schemaSnapshot = [
            'public.places' => ['columns' => ['id'], 'foreign_keys' => []],
            '"public"."dress_codes"' => [
                'columns' => ['id', 'place_id'],
                'foreign_keys' => [['columns' => ['place_id'], 'foreign_table' => 'public.places', 'foreign_columns' => ['id']]],
            ],
        ];
    }

    public function test_normalizes_postgresql_names_without_certifying_enforcement(): void
    {
        $this->assertSame([], $this->guard->violations($this->manifest, $this->schemaSnapshot));
        $this->assertSame('proposed', $this->manifest['tables']['dress_codes']['target_status']);
        $this->assertSame('pending_verification', $this->manifest['tables']['dress_codes']['enforcement']['status']);
    }

    #[DataProvider('newTables')]
    public function test_rejects_new_tables_instead_of_inferring_a_prefix(string $table): void
    {
        $this->schemaSnapshot['public.'.$table] = ['columns' => ['id'], 'foreign_keys' => []];

        $this->assertContains("Unclassified table: {$table}.", $this->guard->violations($this->manifest, $this->schemaSnapshot));
    }

    public static function newTables(): array
    {
        return [['new_application_table'], ['x_files_new_child'], ['jobs_archive']];
    }

    public function test_rejects_missing_required_tables_and_stale_entries(): void
    {
        unset($this->schemaSnapshot['"public"."dress_codes"']);

        $this->assertContains('Missing required application table: dress_codes.', $this->guard->violations($this->manifest, $this->schemaSnapshot));
    }

    public function test_allows_only_explained_optional_absence(): void
    {
        unset($this->schemaSnapshot['"public"."dress_codes"']);
        $this->allowOptionalChild();

        $this->assertSame([], $this->guard->violations($this->manifest, $this->schemaSnapshot));
    }

    public function test_validates_optional_entries_even_when_absent(): void
    {
        unset($this->schemaSnapshot['"public"."dress_codes"']);
        $this->allowOptionalChild();
        unset($this->manifest['tables']['dress_codes']['parents'][0]['child_column']);

        $this->assertViolation('malformed parent relation');
    }

    public function test_rejects_arbitrary_optional_core_table_declarations(): void
    {
        unset($this->schemaSnapshot['"public"."dress_codes"']);
        $this->manifest['tables']['dress_codes']['optional_absence'] = 'Do not hide an obsolete core table.';

        $this->assertViolation('optional absence must belong to a documented optional extension table allowlist');
        $this->assertViolation('Missing required application table: dress_codes.');
    }

    public function test_requires_optional_tables_when_their_extension_is_enabled(): void
    {
        unset($this->schemaSnapshot['"public"."dress_codes"']);
        $this->allowOptionalChild();
        config(['locations.enabled' => true]);

        $this->assertViolation('Missing required application table: dress_codes.');
    }

    public function test_rejects_typographical_optional_table_declarations(): void
    {
        $this->allowOptionalChild();
        $this->manifest['tables']['dress_code_typo'] = $this->manifest['tables']['dress_codes'];
        unset($this->manifest['tables']['dress_codes']);

        $this->assertViolation('optional availability must match');
        $this->assertViolation('dress_code_typo: optional absence must belong');
    }

    #[DataProvider('malformedEntries')]
    public function test_rejects_malformed_fields_without_throwing(string $field, mixed $value, string $message): void
    {
        data_set($this->manifest['tables']['dress_codes'], $field, $value);

        $this->assertViolation($message);
    }

    public static function malformedEntries(): array
    {
        return [
            ['mode', 'global', 'invalid mode'],
            ['module', '', 'invalid module'],
            ['data_class', 'ordinary', 'invalid data_class'],
            ['owner', null, 'invalid owner'],
            ['null_rule', '', 'invalid null_rule'],
            ['authorization', [], 'authorization must name'],
            ['authorization', ['does/not/exist.php'], 'invalid authorization reference'],
            ['optional_absence', true, 'optional_absence requires'],
            ['target_status', 'verified', 'target_status must be proposed or pending_decision'],
            ['enforcement', null, 'enforcement must separately'],
            ['enforcement.status', 'verified', 'enforcement must separately'],
            ['enforcement.status', 'implemented', 'implemented enforcement requires verification evidence'],
            ['enforcement.evidence', ['missing-test.php'], 'invalid enforcement evidence reference'],
            ['enforcement.silently_accepted', true, 'enforcement must separately'],
            ['parents', null, 'parents must be an explicit list'],
            ['parents', [], 'require an explicit parent'],
            ['parents.0', null, 'malformed parent relation'],
            ['parents.0.child_column', null, 'malformed parent relation'],
            ['parents.0.source_null_rule', 'anything', 'malformed parent relation'],
            ['parents.0.kind', 'inferred', 'malformed parent relation'],
            ['parents.0.optional_column', 'yes', 'malformed parent relation'],
            ['parents.0.optional_column', true, 'optional column requires'],
            ['polymorphic', null, 'polymorphic must be an explicit list'],
            ['polymorphic', [['type_column' => 'type']], 'malformed polymorphic relation'],
            ['unexpected', 'not accepted', 'missing or unknown entry fields'],
        ];
    }

    #[DataProvider('omittedFields')]
    public function test_rejects_omitted_classification_fields(string $field): void
    {
        unset($this->manifest['tables']['dress_codes'][$field]);

        $this->assertViolation('missing or unknown entry fields');
    }

    public static function omittedFields(): array
    {
        return array_map(fn (string $field): array => [$field], ['mode', 'module', 'data_class', 'owner', 'parents', 'current_null_rule', 'null_rule', 'authorization', 'enforcement', 'optional_absence', 'polymorphic', 'target_status', 'target_gap']);
    }

    #[DataProvider('invalidParents')]
    public function test_rejects_invalid_parent_tables_and_columns(string $field, string $value, string $message): void
    {
        $this->manifest['tables']['dress_codes']['parents'][0][$field] = $value;

        $this->assertViolation($message);
    }

    public static function invalidParents(): array
    {
        return [
            ['child_column', 'missing_parent_id', 'missing child parent column missing_parent_id'],
            ['table', 'unknown_parent', 'unclassified parent table unknown_parent'],
            ['column', 'missing_id', 'missing parent column places.missing_id'],
        ];
    }

    public function test_rejects_logical_ids_falsely_claimed_as_foreign_keys(): void
    {
        $this->schemaSnapshot['"public"."dress_codes"']['foreign_keys'] = [];
        $this->assertViolation('declared foreign key to places.id does not exist');

        $this->manifest['tables']['dress_codes']['parents'][0]['kind'] = 'logical';
        $this->assertSame([], $this->guard->violations($this->manifest, $this->schemaSnapshot));
    }

    public function test_installed_conditional_foreign_keys_are_required_unless_explicitly_logical(): void
    {
        $this->manifest['tables']['dress_codes']['parents'][0]['kind'] = 'conditional_foreign_key';
        $this->schemaSnapshot['"public"."dress_codes"']['foreign_keys'] = [];
        $this->assertViolation('declared foreign key to places.id does not exist');

        $this->manifest['tables']['dress_codes']['parents'][0]['kind'] = 'conditional_logical';
        $this->assertSame([], $this->guard->violations($this->manifest, $this->schemaSnapshot));
    }

    public function test_enabled_oidc_requires_its_table_and_disabled_oidc_still_validates_retained_tables(): void
    {
        $manifest = config('locations.protection');
        $manifest['tables'] = array_intersect_key($manifest['tables'], array_flip(['users', 'x_locations', 'x_oidc_identities']));
        $manifest['infrastructure'] = [];
        $manifest['optional_extensions'] = array_intersect_key($manifest['optional_extensions'], array_flip(['extensions.locations', 'extensions.oidc']));
        $manifest['optional_extensions']['extensions.locations']['tables'] = ['x_locations'];
        config(['locations.enabled' => false, 'oidc.enabled' => true]);
        $snapshot = ['public.users' => ['columns' => ['id'], 'foreign_keys' => []]];

        $this->assertContains('Missing required application table: x_oidc_identities.', $this->guard->violations($manifest, $snapshot));

        config(['oidc.enabled' => false]);
        $this->assertSame([], $this->guard->violations($manifest, $snapshot));

        $snapshot['public.x_oidc_identities'] = ['columns' => ['id'], 'foreign_keys' => []];
        $this->assertContains('x_oidc_identities: missing child parent column user_id.', $this->guard->violations($manifest, $snapshot));
    }

    public function test_rejects_incorrect_parent_nullability(): void
    {
        $this->schemaSnapshot['"public"."dress_codes"']['nullable_columns'] = ['place_id'];

        $this->assertViolation('dress_codes.place_id: source NULL rule contradicts schema nullability.');
    }

    public function test_nullable_source_columns_do_not_approve_shared_personal_data(): void
    {
        $this->schemaSnapshot['"public"."dress_codes"']['nullable_columns'] = ['place_id'];
        $this->manifest['tables']['dress_codes']['parents'][0]['source_null_rule'] = 'inherit';
        $this->manifest['tables']['dress_codes']['data_class'] = 'personnel';
        $this->manifest['tables']['dress_codes']['current_null_rule'] = 'Legacy rows currently allow NULL parents.';
        $this->manifest['tables']['dress_codes']['null_rule'] = 'deny_or_quarantine';

        $this->assertSame([], $this->guard->violations($this->manifest, $this->schemaSnapshot));

        $this->manifest['tables']['dress_codes']['null_rule'] = 'explicitly_shared_catalog';
        $this->assertViolation('sensitive/personnel NULL ownership must target deny_or_quarantine');
    }

    public function test_physical_storage_places_have_explicit_location_ownership_while_other_inventory_gaps_remain(): void
    {
        $tables = config('locations.protection.tables');
        foreach (['kk_stock', 'wm_protocols'] as $table) {
            $this->assertSame('standortgebunden', $tables[$table]['mode']);
            $this->assertSame('deny_or_quarantine', $tables[$table]['null_rule']);
            $this->assertSame('pending_decision', $tables[$table]['target_status']);
            $this->assertNotEmpty($tables[$table]['target_gap']);
        }
        $this->assertSame('proposed', $tables['mm_devices']['target_status']);

        $storage = $tables['ar_locations'];
        $this->assertSame('standortgebunden', $storage['mode']);
        $this->assertSame('deny_or_quarantine', $storage['null_rule']);
        $this->assertSame('proposed', $storage['target_status']);
        $this->assertSame('ext_location_id', $storage['parents'][0]['child_column']);
        $this->assertStringContainsString('not backfilled', $storage['target_gap']);

        $stocktakes = $tables['ar_stocktakes'];
        $this->assertSame('standortgebunden', $stocktakes['mode']);
        $this->assertSame('deny_or_quarantine', $stocktakes['null_rule']);
        $this->assertSame('proposed', $stocktakes['target_status']);
        $this->assertSame('ext_location_id', $stocktakes['parents'][0]['child_column']);
        $this->assertStringContainsString('Historical NULL ownership is not backfilled', $stocktakes['target_gap']);
    }

    public function test_current_shared_null_scope_is_not_an_approved_sensitive_target(): void
    {
        $tables = config('locations.protection.tables');
        foreach (['users', 'eb_reports', 'x_vv_members', 'x_files_spaces', 'x_files_groups'] as $table) {
            $this->assertSame('deny_or_quarantine', $tables[$table]['null_rule']);
            $this->assertSame('pending_verification', $tables[$table]['enforcement']['status']);
            $this->assertContains($tables[$table]['target_status'], ['proposed', 'pending_decision']);
            $this->assertNotEmpty($tables[$table]['current_null_rule']);
        }
    }

    public function test_attendance_lists_and_template_prefill_deny_unassigned_ownership_without_certifying_all_enforcement(): void
    {
        $tables = config('locations.protection.tables');
        $this->assertSame('deny_non_admin', $tables['x_att_lists']['parents'][0]['source_null_rule']);
        $this->assertStringContainsString('denies NULL location to non-global admins', $tables['x_att_lists']['current_null_rule']);
        $this->assertSame('deny_non_admin', $tables['x_att_templates']['parents'][0]['source_null_rule']);
        $this->assertStringContainsString('AttendanceService::restrictTemplateQuery', $tables['x_att_templates']['current_null_rule']);
        $this->assertStringContainsString('Global admins retain all templates', $tables['x_att_templates']['current_null_rule']);
        $this->assertSame('pending_verification', $tables['x_att_templates']['enforcement']['status']);
        $this->assertSame('proposed', $tables['x_att_templates']['target_status']);
        $this->assertSame('deny_or_quarantine', $tables['x_att_lists']['null_rule']);
        $this->assertSame('deny_or_quarantine', $tables['x_att_templates']['null_rule']);
    }

    public function test_financial_ownership_gaps_are_not_approved_as_cross_location_sharing(): void
    {
        $tables = config('locations.protection.tables');
        foreach (['fin_budgets', 'fin_closed_years'] as $table) {
            $this->assertSame('proposed', $tables[$table]['target_status']);
            $this->assertSame('deny_or_quarantine', $tables[$table]['null_rule']);
            $this->assertNotSame('übergreifend', $tables[$table]['mode']);
        }
    }

    public function test_validates_polymorphic_discriminator_and_id_columns(): void
    {
        $this->manifest['tables']['dress_codes']['polymorphic'] = [[
            'type_column' => 'missing_type',
            'id_column' => 'missing_id',
            'rule' => 'Explicit polymorphic rule.',
        ]];

        $this->assertViolation('dress_codes: missing polymorphic column missing_type.');
        $this->assertViolation('dress_codes: missing polymorphic column missing_id.');
    }

    public function test_rejects_infrastructure_wildcards(): void
    {
        $this->manifest['infrastructure'] = ['jobs_*' => 'Not a finite table name.'];

        $this->assertViolation('finite, unqualified table name');
    }

    public function test_rejects_ambiguous_normalized_schema_names(): void
    {
        $this->schemaSnapshot['other.places'] = ['columns' => ['id'], 'foreign_keys' => []];

        $this->assertViolation('Ambiguous normalized schema table: places.');
    }

    public function test_keeps_configuration_registered_when_locations_is_disabled(): void
    {
        config(['locations.enabled' => false]);

        $this->assertSame('declarative_target_only', config('locations.protection.purpose'));
        $this->assertSame('pending_verification', config('locations.protection.tables.users.enforcement.status'));
    }

    public function test_permits_optional_locations_absence_but_validates_installed_columns(): void
    {
        $manifest = config('locations.protection');
        $manifest['tables'] = array_intersect_key($manifest['tables'], array_flip(['users', 'x_locations']));
        $manifest['infrastructure'] = [];
        $manifest['optional_extensions'] = ['extensions.locations' => $manifest['optional_extensions']['extensions.locations']];
        $manifest['optional_extensions']['extensions.locations']['tables'] = ['x_locations'];
        config(['locations.enabled' => false]);
        $snapshot = ['public.users' => ['columns' => ['id'], 'foreign_keys' => []]];

        $this->assertSame([], $this->guard->violations($manifest, $snapshot));

        $snapshot['public.x_locations'] = ['columns' => ['id'], 'foreign_keys' => []];
        $this->assertContains('users: missing child parent column ext_location_id.', $this->guard->violations($manifest, $snapshot));
    }

    public function test_optional_stock_provenance_is_not_an_inferred_location_or_required_on_older_schema(): void
    {
        $manifest = config('locations.protection');
        $entry = $manifest['tables']['kk_issued_items'];
        $source = collect($entry['parents'])->firstWhere('child_column', 'ext_source_stock_id');
        $this->assertSame('kk_stock', $source['table']);
        $this->assertTrue($source['optional_column']);
        $this->assertSame('conditional_foreign_key', $source['kind']);
        $this->assertSame('retain', $source['source_null_rule']);
        $this->assertSame('pending_verification', $entry['enforcement']['status']);
        $this->assertSame('pending_decision', $entry['target_status']);
        $this->assertStringContainsString('not a Standort assignment', $entry['owner']);

        $this->manifest['tables']['kk_issued_items'] = $entry;
        $this->manifest['tables']['kk_issued_items']['parents'] = [$source];
        unset($this->manifest['tables']['dress_codes']);
        $this->manifest['tables']['kk_stock'] = $manifest['tables']['kk_item_categories'];
        $snapshot = [
            'places' => ['columns' => ['id'], 'foreign_keys' => []],
            'kk_stock' => ['columns' => ['id'], 'foreign_keys' => []],
            'kk_issued_items' => ['columns' => ['id'], 'foreign_keys' => []],
        ];
        $this->assertSame([], $this->guard->violations($this->manifest, $snapshot));
        $snapshot['kk_issued_items']['columns'][] = 'ext_source_stock_id';
        $this->assertContains('kk_issued_items.ext_source_stock_id: declared foreign key to kk_stock.id does not exist.', $this->guard->violations($this->manifest, $snapshot));
    }

    public function test_rejects_cyclic_parent_chains(): void
    {
        $this->manifest['tables']['places']['parents'] = [[
            'child_column' => 'id',
            'table' => 'dress_codes',
            'column' => 'id',
            'kind' => 'logical',
            'source_null_rule' => 'forbidden',
            'optional_column' => false,
            'absence_reason' => null,
        ]];

        $this->assertViolation('Cyclic parent chain');
    }

    public function test_permits_only_explicit_root_based_self_hierarchies(): void
    {
        $this->manifest['tables']['dress_codes']['parents'][0]['table'] = 'dress_codes';
        $this->manifest['tables']['dress_codes']['parents'][0]['kind'] = 'logical';
        $this->assertViolation('Cyclic parent chain');

        $this->manifest['tables']['dress_codes']['parents'][0]['source_null_rule'] = 'root';
        $this->assertSame([], $this->guard->violations($this->manifest, $this->schemaSnapshot));
    }

    public function test_historical_metadata_does_not_form_an_ownership_cycle(): void
    {
        $this->manifest['tables']['places']['parents'] = [[
            'child_column' => 'id',
            'table' => 'dress_codes',
            'column' => 'id',
            'kind' => 'historical',
            'source_null_rule' => 'retain',
            'optional_column' => false,
            'absence_reason' => null,
        ]];

        $this->assertSame([], $this->guard->violations($this->manifest, $this->schemaSnapshot));
    }

    private function allowOptionalChild(): void
    {
        config(['locations.enabled' => false]);
        $reason = 'Synthetic optional extension fixture, absent only when locations.enabled=false.';
        $this->manifest['tables']['dress_codes']['module'] = 'extensions.locations';
        $this->manifest['tables']['dress_codes']['optional_absence'] = $reason;
        $this->manifest['optional_extensions'] = ['extensions.locations' => [
            'config' => 'locations.enabled',
            'provider' => 'extensions/Locations/LocationsServiceProvider.php',
            'tables' => ['dress_codes'],
            'reason' => $reason,
        ]];
    }

    private function assertViolation(string $message): void
    {
        $this->assertStringContainsString($message, implode("\n", $this->guard->violations($this->manifest, $this->schemaSnapshot)));
    }
}
