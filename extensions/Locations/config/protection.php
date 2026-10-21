<?php

declare(strict_types=1);

/*
 * P2a target inventory, NOT an access-control configuration. Nothing consumes this
 * manifest to scope queries or authorize requests. References identify existing
 * mechanisms to investigate, not evidence of complete location isolation.
 * mode/owner/null_rule describe the proposed target. current_null_rule and
 * parents[].source_null_rule describe observed behavior/schema, not approval.
 */
$parent = static fn (string $childColumn, string $table, string $nullRule = 'forbidden', string $kind = 'foreign_key', bool $optionalColumn = false): array => [
    'child_column' => $childColumn,
    'table' => $table,
    'column' => 'id',
    'kind' => $kind,
    'source_null_rule' => $nullRule,
    'optional_column' => $optionalColumn,
    'absence_reason' => $optionalColumn ? 'Added only when the Locations migration is installed.' : null,
];
$location = $parent('ext_location_id', 'x_locations', 'shared', 'conditional_foreign_key', true);
$alwaysLocation = $parent('ext_location_id', 'x_locations', 'shared', 'conditional_foreign_key');
$required = 'Required owner/parent; authorize through that parent, never through a NULL fallback.';
$shared = 'NULL location is shared in the existing location mechanism, not public; module/record ACL still applies.';
$retained = 'Nullable person/actor links preserve historical records; NULL never grants access.';
$catalog = [
    'current' => 'No location owner: shared reference catalog, with existing module/admin write authorization.',
    'target' => 'explicitly_shared_catalog',
];
$system = [
    'current' => 'No row owner: instance-wide system state; only the named admin/service mechanism may access it.',
    'target' => 'not_applicable',
];
$optionalLocations = 'Locations migrations are not loaded on a fresh install with locations.enabled=false; existing tables remain when disabled.';
$optionalVerein = 'Vereinsverwaltung migrations are optional when vereinsverwaltung.enabled=false; existing tables remain when disabled.';
$optionalAttendance = 'Anwesenheit migrations are optional when anwesenheit.enabled=false; existing tables remain when disabled.';
$optionalHydrants = 'Hydrantenkontrolle migrations are optional when hydrantenkontrolle.enabled=false; existing tables remain when disabled.';
$optionalReport = 'EinsatzberichtPlus migrations are optional when einsatzbericht-plus.enabled=false; existing tables remain when disabled.';
$optionalOidc = 'Oidc migrations are optional when oidc.enabled=false; existing tables remain when disabled.';

$entry = static fn (string $module, string $mode, string $dataClass, string $owner, array $parents, string|array $nullRule, array $authorization, ?string $optional = null, array $polymorphic = [], string $targetStatus = 'proposed', ?string $targetGap = null, ?string $targetNullRule = null): array => [
    'module' => $module,
    'mode' => $mode,
    'data_class' => $dataClass,
    'owner' => $owner,
    'parents' => $parents,
    'current_null_rule' => is_array($nullRule) ? $nullRule['current'] : $nullRule,
    'null_rule' => $targetNullRule ?? (is_array($nullRule) ? $nullRule['target'] : 'deny_or_quarantine'),
    'authorization' => $authorization,
    'optional_absence' => $optional,
    'polymorphic' => $polymorphic,
    'target_status' => $targetStatus,
    'target_gap' => $targetGap ?? 'Proposed, not approved: define/verify all owner boundaries before implementing enforcement. NULL may resolve through an authorized non-NULL parent; it must not grant sharing by itself.',
    'enforcement' => [
        'status' => 'pending_verification',
        'evidence' => [],
        'gap' => 'P2a inventories the target only. Existing module gates, scopes and own-user checks do not establish complete isolation of reads, writes, exports or child records.',
    ],
];

$core = ['app/Models/User.php', 'app/Filament/Concerns/AdminOnly.php'];
$feedback = ['app/Services/FeedbackService.php'];
$personnel = ['modules/Personalverwaltung/src/Services/PersonnelService.php'];
$booking = ['modules/Fahrzeugbuchung/src/Policies/BookingPolicy.php'];
$equipment = ['modules/Ausruestung/src/Services/EquipmentService.php'];
$clothing = ['modules/Kleiderkammer/src/Services/IssuingService.php'];
$report = ['modules/Einsatzbericht/src/Services/ReportService.php'];
$calendar = ['modules/Kalender/src/Policies/EventPolicy.php'];
$training = ['modules/Lehrgaenge/src/Services/RegistrationService.php'];
$defects = ['modules/Maengelmanagement/src/Services/DefectService.php'];
$finance = ['modules/Finanzen/src/Services/FinanceService.php'];
$fleet = ['modules/Fahrzeuge/src/Services/FleetService.php'];
$breathing = ['modules/Atemschutz/src/Services/MonitorService.php'];
$exposure = ['modules/Expositionen/src/Services/ExposureService.php'];
$logbook = ['modules/Fahrtenbuch/src/Services/LogbookService.php'];
$license = ['modules/Fuehrerscheine/src/Services/LicenseService.php'];
$plans = ['modules/Objektplaene/src/Services/ObjectPlanService.php'];
$shifts = ['modules/Schichtplan/src/Policies/PlanPolicy.php'];
$keys = ['modules/Schluessel/src/Services/KeyService.php'];
$instructions = ['modules/Unterweisungen/src/Services/InstructionService.php'];
$health = ['modules/Verbandbuch/src/Services/VerbandbuchService.php'];
$maintenance = ['modules/Wartung/src/Services/WartungService.php'];
$water = ['modules/Wasserkarte/src/Services/WaterService.php'];
$announcement = ['modules/Mitteilungen/src/Services/AnnouncementService.php'];
$files = ['extensions/Dateien/Services/FileAccess.php'];
$association = ['extensions/Vereinsverwaltung/Services/AssociationAccess.php', 'extensions/Vereinsverwaltung/Services/MembershipService.php'];
$attendance = ['extensions/Anwesenheit/Services/AttendanceService.php'];
$hydrants = ['extensions/Hydrantenkontrolle/Services/InspectionService.php'];
$locations = ['extensions/Locations/Services/LocationAccess.php', 'extensions/Locations/Scopes/LocationScope.php'];
$assignmentHistory = $entry('extensions.locations', 'bewusst global', 'audit', 'Append-only operator assignment history; only finite attendance and tracked-clothing CLIs write it', [$parent('location_id', 'x_locations'), $parent('assigned_by', 'users')], $required, ['extensions/Locations/Services/AttendanceLocationAssignment.php', 'extensions/Locations/Console/AssignAttendanceCommand.php', 'extensions/Locations/Services/ClothingLocationAssignment.php', 'extensions/Locations/Console/AssignClothingCommand.php', 'extensions/Locations/database/migrations/2026_12_23_000000_create_location_data_assignments.php', 'extensions/Locations/database/migrations/2026_12_24_000000_allow_clothing_location_assignments.php', 'extensions/Locations/database/migrations/2026_12_24_000001_restrict_clothing_location_deletion.php', 'extensions/Locations/Services/LocationModuleRoles.php', 'app/Services/MemberRemoval.php', 'tests/Feature/Extensions/Locations/AttendanceLocationAssignmentTest.php', 'tests/Feature/Extensions/Locations/ClothingLocationAssignmentTest.php'], $optionalLocations, targetGap: 'Only explicit legacy NULL attendance and nondeleted tracked-clothing ownership assignment is implemented here; historical clothing children require private review, not rewriting. This is not verification of all module isolation or enforcement.');
$assignmentHistory['enforcement']['evidence'] = $assignmentHistory['authorization'];
$assignmentHistory['enforcement']['gap'] = 'Bounded CLI and immutable journal only; no certification of complete module reads, writes, exports or child isolation.';

return [
    'version' => 1,
    'purpose' => 'declarative_target_only',
    'admin_rule' => 'Preserve existing global administrator access; no manifest entry installs filters or changes authorization.',
    'optional_extensions' => [
        'extensions.locations' => ['config' => 'locations.enabled', 'provider' => 'extensions/Locations/LocationsServiceProvider.php', 'tables' => ['x_locations', 'x_location_user', 'x_location_module_roles', 'x_location_data_assignments'], 'reason' => $optionalLocations],
        'extensions.oidc' => ['config' => 'oidc.enabled', 'provider' => 'extensions/Oidc/OidcServiceProvider.php', 'tables' => ['x_oidc_identities'], 'reason' => $optionalOidc],
        'extensions.verein' => ['config' => 'vereinsverwaltung.enabled', 'provider' => 'extensions/Vereinsverwaltung/VereinsverwaltungServiceProvider.php', 'tables' => ['x_vv_associations', 'x_vv_association_user', 'x_vv_members', 'x_vv_applications'], 'reason' => $optionalVerein],
        'extensions.anwesenheit' => ['config' => 'anwesenheit.enabled', 'provider' => 'extensions/Anwesenheit/AnwesenheitServiceProvider.php', 'tables' => ['x_att_templates', 'x_att_lists', 'x_att_participants', 'x_att_files'], 'reason' => $optionalAttendance],
        'extensions.hydrantenkontrolle' => ['config' => 'hydrantenkontrolle.enabled', 'provider' => 'extensions/Hydrantenkontrolle/HydrantenkontrolleServiceProvider.php', 'tables' => ['x_hc_hydrants', 'x_hc_years', 'x_hc_inspections'], 'reason' => $optionalHydrants],
        'extensions.einsatzbericht-plus' => ['config' => 'einsatzbericht-plus.enabled', 'provider' => 'extensions/EinsatzberichtPlus/EinsatzberichtPlusServiceProvider.php', 'tables' => ['x_ebp_extras', 'x_ebp_persons', 'x_ebp_earning_losses'], 'reason' => $optionalReport],
    ],
    'infrastructure' => [
        'cache' => 'Laravel shared cache, not an application-domain table.',
        'cache_locks' => 'Laravel cache locks.',
        'failed_jobs' => 'Laravel failed-job storage; operational access only.',
        'jobs' => 'Laravel queue payloads; operational access only.',
        'job_batches' => 'Laravel queue batch metadata.',
        'migrations' => 'Laravel migration ledger.',
        'sessions' => 'Laravel sessions; user_id is optional and not a domain ownership boundary.',
    ],
    'retired_tables' => [
        'group_functions' => ['module' => 'core', 'removed_by' => 'database/migrations/2026_10_13_100001_make_functions_independent_of_groups.php', 'reason' => 'Renamed to member_functions.'],
        'group_function_user' => ['module' => 'core', 'removed_by' => 'database/migrations/2026_10_13_100001_make_functions_independent_of_groups.php', 'reason' => 'Renamed to member_function_user; group_function_id became member_function_id.'],
        'kk_members' => ['module' => 'kleiderkammer', 'removed_by' => 'modules/Kleiderkammer/database/migrations/2026_10_10_000001_move_kk_members_to_users.php', 'reason' => 'Member data moved to users.'],
        'lq_offering_mail_optouts' => ['module' => 'lehrgaenge', 'removed_by' => 'modules/Lehrgaenge/database/migrations/2026_10_22_000000_rework_lehrgaenge_registrations.php', 'reason' => 'Old offering mail preferences removed.'],
        'lq_offerings' => ['module' => 'lehrgaenge', 'removed_by' => 'modules/Lehrgaenge/database/migrations/2026_10_22_000000_rework_lehrgaenge_registrations.php', 'reason' => 'Dated external offerings removed.'],
        'lq_certificates' => ['module' => 'lehrgaenge', 'removed_by' => 'modules/Lehrgaenge/database/migrations/2026_10_22_000000_rework_lehrgaenge_registrations.php', 'reason' => 'Old qualification certificates removed.'],
        'lq_promotion_rule_course' => ['module' => 'lehrgaenge', 'removed_by' => 'modules/Lehrgaenge/database/migrations/2026_10_22_000000_rework_lehrgaenge_registrations.php', 'reason' => 'Old course promotion rules removed.'],
        'lq_promotion_rules' => ['module' => 'lehrgaenge', 'removed_by' => 'modules/Lehrgaenge/database/migrations/2026_10_22_000000_rework_lehrgaenge_registrations.php', 'reason' => 'Old promotion rule catalog removed.'],
        'lq_course_prerequisites' => ['module' => 'lehrgaenge', 'removed_by' => 'modules/Lehrgaenge/database/migrations/2026_10_22_000000_rework_lehrgaenge_registrations.php', 'reason' => 'Old course prerequisites removed.'],
        'lq_course_aliases' => ['module' => 'lehrgaenge', 'removed_by' => 'modules/Lehrgaenge/database/migrations/2026_10_22_000000_rework_lehrgaenge_registrations.php', 'reason' => 'Aliases folded into the course catalog and removed.'],
    ],
    'tables' => [
        'x_location_data_assignments' => $assignmentHistory,
        // Core. Own-user data is not made global merely because it lacks a location column.
        'users' => $entry('core', 'hybrid', 'personnel', 'Member itself, location membership and self-service identity', [$location], $shared, [...$core, ...$locations, ...$personnel]),
        'groups' => $entry('core', 'standortgebunden', 'access', 'Department location; unassigned departments must not become shared access boundaries', [$location], $shared, [...$core, ...$locations]),
        'group_user' => $entry('core', 'hybrid', 'access', 'Both department and member', [$parent('group_id', 'groups'), $parent('user_id', 'users')], $required, $core),
        'member_functions' => $entry('core', 'standortgebunden', 'access', 'Function location/hierarchy; unassigned functions must not become shared role grants', [$location, $parent('parent_id', 'member_functions', 'root')], $shared.' NULL parent denotes a root function.', [...$core, ...$locations]),
        'member_function_user' => $entry('core', 'hybrid', 'access', 'Both function and member', [$parent('member_function_id', 'member_functions'), $parent('user_id', 'users')], $required, $core),
        'login_tokens' => $entry('core', 'hybrid', 'authentication', 'Own user; secret single-use authentication credential', [$parent('user_id', 'users')], $required, ['app/Services/MagicLinkService.php']),
        'feedback_threads' => $entry('core', 'hybrid', 'personnel', 'Own user plus global feedback administrators', [$parent('user_id', 'users')], $required, $feedback),
        'feedback_messages' => $entry('core', 'hybrid', 'personnel', 'Thread owner; sender is not sufficient authorization', [$parent('feedback_thread_id', 'feedback_threads'), $parent('user_id', 'users', 'retain')], $retained, $feedback),
        'user_avatars' => $entry('core', 'hybrid', 'personnel', 'Own user; delivery follows avatar endpoint rules', [$parent('user_id', 'users')], $required, ['app/Services/AvatarService.php']),
        'modules' => $entry('core', 'bewusst global', 'reference', 'Instance-wide module registry, not a location registry', [], $system, $core),
        'module_user' => $entry('core', 'hybrid', 'access', 'Own user; module/role strings currently have no location dimension', [$parent('user_id', 'users')], $required, ['app/Models/User.php', 'app/Http/Middleware/EnsureModuleAccess.php']),
        'settings' => $entry('core', 'bewusst global', 'authentication', 'Instance-wide settings including encrypted service secrets; not member-readable', [], $system, $core),
        'email_change_requests' => $entry('core', 'hybrid', 'authentication', 'Own user and confirmation token', [$parent('user_id', 'users')], $required, ['app/Services/EmailChangeService.php']),
        'user_module_preferences' => $entry('core', 'hybrid', 'personnel', 'Own user preferences', [$parent('user_id', 'users')], $required, ['app/Models/User.php']),
        'mail_logs' => $entry('core', 'bewusst global', 'audit', 'Global admin mail audit; recipients do not own the log', [$parent('user_id', 'users', 'retain')], $retained, $core),
        'activity_logs' => $entry('core', 'bewusst global', 'audit', 'Global admin audit; historical polymorphic subject is not a live access parent', [$parent('user_id', 'users', 'retain'), $parent('impersonator_id', 'users', 'retain'), $parent('mail_log_id', 'mail_logs', 'retain')], $retained, $core, null, [['type_column' => 'subject_type', 'id_column' => 'subject_id', 'rule' => 'Historical App/Modules model subject, possibly deleted; app/Services/ActivityRecorder.php records it, global admin log ACL applies.']]),
        'notifications' => $entry('core', 'hybrid', 'personnel', 'Own notifiable user; not a global inbox', [$parent('notifiable_id', 'users', 'forbidden', 'polymorphic')], $required, ['app/Livewire/Notifications/Bell.php'], null, [['type_column' => 'notifiable_type', 'id_column' => 'notifiable_id', 'rule' => 'App\\Models\\User database notifications; authenticate notifiable identity, never the location alone.']]),
        'places' => $entry('core', 'übergreifend', 'reference', 'Shared place catalog used by several modules', [], $catalog, ['app/Livewire/Catalog/PlacesAndDress.php']),
        'dress_codes' => $entry('core', 'übergreifend', 'reference', 'Shared dress-code catalog used by several modules', [], $catalog, ['app/Livewire/Catalog/PlacesAndDress.php']),
        'push_subscriptions' => $entry('core', 'hybrid', 'authentication', 'Own user device endpoint and keys', [$parent('user_id', 'users')], $required, ['app/Services/PushService.php']),
        'guardians' => $entry('core', 'hybrid', 'personnel', 'Child user; guardian is a non-login contact with signed response access', [$parent('user_id', 'users')], $required, [...$personnel, 'modules/Kalender/src/Services/EventService.php']),
        'contacts' => $entry('core', 'hybrid', 'personnel', 'Department-owned or explicitly authorized contact; unassigned personal contacts require an ownership decision', [$parent('group_id', 'groups', 'shared')], 'NULL group is a shared contact, not unrestricted write permission.', $core, targetStatus: 'pending_decision', targetGap: 'Decide an explicit owner/ACL for unassigned personal contacts; do not approve current NULL sharing.'),

        // Fahrzeugbuchung and its child records.
        'vehicles' => $entry('fahrzeugbuchung', 'standortgebunden', 'operational', 'Vehicle location; cross-location use requires an explicit authorized workflow, never NULL sharing', [$location], $shared, ['modules/Fahrzeugbuchung/src/Policies/VehiclePolicy.php', ...$locations]),
        'bookings' => $entry('fahrzeugbuchung', 'standortgebunden', 'personnel', 'Vehicle plus own booking user and optional department', [$parent('vehicle_id', 'vehicles'), $parent('user_id', 'users'), $parent('group_id', 'groups', 'inherit')], 'NULL group does not bypass vehicle or booking-owner authorization.', $booking),
        'booking_decisions' => $entry('fahrzeugbuchung', 'standortgebunden', 'personnel', 'Requested booking; replaces_booking_id is an optional additional booking, conflicting_booking_ids is JSON history', [$parent('booking_id', 'bookings'), $parent('replaces_booking_id', 'bookings', 'inherit')], 'NULL replacement means no replacement; booking parent remains required.', ['modules/Fahrzeugbuchung/src/Services/DecisionService.php']),
        'booking_comments' => $entry('fahrzeugbuchung', 'standortgebunden', 'personnel', 'Booking visibility plus own author edit/delete checks', [$parent('booking_id', 'bookings'), $parent('user_id', 'users')], $required, ['modules/Fahrzeugbuchung/src/Policies/BookingCommentPolicy.php']),
        'booking_comment_revisions' => $entry('fahrzeugbuchung', 'standortgebunden', 'personnel', 'Comment and booking; revision-history permission is separate', [$parent('booking_comment_id', 'booking_comments')], $required, ['modules/Fahrzeugbuchung/src/Policies/BookingCommentPolicy.php']),
        'mail_templates' => $entry('fahrzeugbuchung', 'bewusst global', 'reference', 'Instance-wide editable mail templates', [], $system, $core),

        // Ausrüstung.
        'ar_categories' => $entry('ausruestung', 'hybrid', 'reference', 'Pure category catalog with proposed explicit shared categories; no physical equipment ownership is inherited from sharing a category', [$location], $shared, [...$equipment, ...$locations], targetNullRule: 'explicitly_shared_catalog'),
        'ar_locations' => $entry('ausruestung', 'standortgebunden', 'operational', 'Physical storage place must belong to a Standort; ar_locations is not the Standort registry', [], 'No Standort owner column currently exists; storage places use module-level management.', $equipment, targetStatus: 'pending_decision', targetGap: 'Define and migrate a Standort owner for physical storage places; lack of a column is not a cross-location target decision.'),
        'ar_compartments' => $entry('ausruestung', 'standortgebunden', 'operational', 'Physical compartment inherits the storage place Standort', [$parent('location_id', 'ar_locations')], $required, $equipment),
        'ar_equipment' => $entry('ausruestung', 'standortgebunden', 'operational', 'Physical equipment location; category/storage references cannot grant sharing', [$location, $parent('category_id', 'ar_categories', 'inherit'), $parent('location_id', 'ar_locations', 'inherit'), $parent('compartment_id', 'ar_compartments', 'inherit')], $shared.' Missing category/storage assignment does not remove equipment ownership.', [...$equipment, ...$locations]),
        'ar_inspections' => $entry('ausruestung', 'standortgebunden', 'operational', 'Equipment; inspector is actor only', [$parent('equipment_id', 'ar_equipment')], $required, $equipment),
        'ar_loans' => $entry('ausruestung', 'hybrid', 'personnel', 'Equipment and borrowing user; own-user access remains relevant', [$parent('equipment_id', 'ar_equipment'), $parent('user_id', 'users')], $required, ['modules/Ausruestung/src/Services/LoanService.php']),
        'ar_damages' => $entry('ausruestung', 'standortgebunden', 'operational', 'Equipment; reporter is actor only', [$parent('equipment_id', 'ar_equipment')], $required, $equipment),
        'ar_recalls' => $entry('ausruestung', 'übergreifend', 'operational', 'Cross-equipment recall catalog; affected equipment linked separately', [], $catalog, ['modules/Ausruestung/src/Services/RecallService.php']),
        'ar_recall_equipment' => $entry('ausruestung', 'hybrid', 'operational', 'Both recall and equipment', [$parent('recall_id', 'ar_recalls'), $parent('equipment_id', 'ar_equipment')], $required, ['modules/Ausruestung/src/Services/RecallService.php']),
        'ar_load_plan_items' => $entry('ausruestung', 'standortgebunden', 'operational', 'Physical load plan inherits compartment/storage Standort; category is reference data only', [$parent('compartment_id', 'ar_compartments'), $parent('category_id', 'ar_categories')], $required, ['modules/Ausruestung/src/Services/LoadPlanService.php']),
        'ar_events' => $entry('ausruestung', 'standortgebunden', 'financial', 'Equipment lifecycle work and costs', [$parent('equipment_id', 'ar_equipment')], $required, $equipment),
        'ar_stocktakes' => $entry('ausruestung', 'standortgebunden', 'operational', 'Physical inventory requires a Standort owner independent of optional storage-place selection', [$parent('location_id', 'ar_locations', 'shared')], 'NULL storage place means the stocktake has no single storage-place restriction.', ['modules/Ausruestung/src/Services/StocktakeService.php'], targetStatus: 'pending_decision', targetGap: 'Design Standort ownership for inventory across one or several storage places; current NULL/module-wide inventory is not an approved target.'),

        // Kleiderkammer: member links replaced the removed kk_members table.
        'kk_item_categories' => $entry('kleiderkammer', 'übergreifend', 'reference', 'Shared clothing category catalog', [], $catalog, $clothing),
        'kk_item_types' => $entry('kleiderkammer', 'übergreifend', 'reference', 'Shared clothing type with optional category', [$parent('category_id', 'kk_item_categories', 'inherit')], 'NULL category is an uncategorized catalog type; clothing-manager ACL remains.', $clothing),
        'kk_sizes' => $entry('kleiderkammer', 'übergreifend', 'reference', 'Clothing type and optional item group', [$parent('item_type_id', 'kk_item_types'), $parent('group_id', 'kk_item_groups', 'inherit')], 'NULL group is an ungrouped size; type remains required.', $clothing),
        'kk_items' => $entry('kleiderkammer', 'standortgebunden', 'operational', 'Physical tracked clothing Standort; type is catalog data only', [$location, $parent('item_type_id', 'kk_item_types')], $shared, [...$clothing, ...$locations]),
        'kk_stock' => $entry('kleiderkammer', 'standortgebunden', 'operational', 'Explicit whole-row physical bulk stock Standort metadata; catalogs do not establish ownership', [$location, $parent('item_type_id', 'kk_item_types'), $parent('size_id', 'kk_sizes', 'inherit'), $parent('group_id', 'kk_item_groups', 'inherit')], 'NULL location is unassigned; NULL size/group means unsized/ungrouped stock. No inferred recipient ownership.', [...$clothing, ...$locations], targetStatus: 'pending_decision', targetGap: 'Global type/size/group uniqueness is deliberately kept; issue and return still match globally. No stock query adapter or operational location enforcement. Decide the source-stock issuance model before per-location stock rows or isolation.'),
        'kk_issued_items' => $entry('kleiderkammer', 'hybrid', 'personnel', 'Recipient user plus optional tracked item and optional type snapshot', [$parent('user_id', 'users'), $parent('item_id', 'kk_items', 'alternative'), $parent('item_type_id', 'kk_item_types', 'alternative')], 'NULL item means bulk/retained issuance; NULL type never removes recipient ownership.', $clothing),
        'kk_item_treatments' => $entry('kleiderkammer', 'standortgebunden', 'operational', 'Tracked item', [$parent('item_id', 'kk_items')], $required, $clothing),
        'kk_item_groups' => $entry('kleiderkammer', 'übergreifend', 'reference', 'Clothing type', [$parent('item_type_id', 'kk_item_types')], $required, $clothing),
        'kk_member_sizes' => $entry('kleiderkammer', 'hybrid', 'personnel', 'Own user measurements plus required type/size', [$parent('user_id', 'users'), $parent('item_type_id', 'kk_item_types'), $parent('size_id', 'kk_sizes')], $required, $clothing),
        'kk_equipment_messages' => $entry('kleiderkammer', 'hybrid', 'personnel', 'Own user and optional retained issuance link', [$parent('user_id', 'users'), $parent('issued_item_id', 'kk_issued_items', 'retain')], 'NULL issuance retains the item-label snapshot and own-user/manager boundary.', ['modules/Kleiderkammer/src/Services/EquipmentMessageService.php']),

        // Einsatzbericht.
        'eb_reports' => $entry('einsatzbericht', 'standortgebunden', 'personnel', 'Report Standort; optional parent is a main report for a Nebenbericht, not implicit shared access', [$location, $parent('parent_id', 'eb_reports', 'root')], $shared.' NULL parent is a main report.', [...$report, ...$locations]),
        'eb_report_vehicles' => $entry('einsatzbericht', 'standortgebunden', 'operational', 'Report and optional retained shared vehicle link', [$parent('report_id', 'eb_reports'), $parent('vehicle_id', 'vehicles', 'retain')], 'NULL vehicle retains report-owned vehicle snapshot, never unrestricted access.', $report),
        'eb_report_crew' => $entry('einsatzbericht', 'hybrid', 'personnel', 'Report plus own user and required report-vehicle assignment', [$parent('report_id', 'eb_reports'), $parent('user_id', 'users'), $parent('report_vehicle_id', 'eb_report_vehicles')], $required, $report),
        'eb_divera_requests' => $entry('einsatzbericht', 'standortgebunden', 'authentication', 'Webhook request with optional resulting report; payload can contain personal data', [$parent('report_id', 'eb_reports', 'retain')], 'NULL report denotes rejected/unprocessed webhook; retain administrative webhook access only.', ['modules/Einsatzbericht/EinsatzberichtServiceProvider.php']),
        'eb_situation_messages' => $entry('einsatzbericht', 'standortgebunden', 'personnel', 'Report; optional external unit is an additional capability actor', [$parent('report_id', 'eb_reports'), $parent('external_unit_id', 'eb_external_units', 'inherit')], 'NULL external unit denotes an internal message; report ACL still applies.', ['modules/Einsatzbericht/src/Services/SituationService.php']),
        'eb_situation_photos' => $entry('einsatzbericht', 'standortgebunden', 'content', 'Situation message and its report', [$parent('message_id', 'eb_situation_messages')], $required, ['modules/Einsatzbericht/src/Services/SituationService.php']),
        'eb_materials' => $entry('einsatzbericht', 'übergreifend', 'reference', 'Shared consumable material catalog', [], $catalog, ['modules/Einsatzbericht/EinsatzberichtServiceProvider.php']),
        'eb_report_materials' => $entry('einsatzbericht', 'standortgebunden', 'operational', 'Report plus optional retained material catalog entry', [$parent('report_id', 'eb_reports'), $parent('material_id', 'eb_materials', 'retain')], 'NULL catalog entry retains report-owned material snapshot.', $report),
        'eb_external_units' => $entry('einsatzbericht', 'standortgebunden', 'authentication', 'Report; external signed/capability access is distinct from member access', [$parent('report_id', 'eb_reports')], $required, ['modules/Einsatzbericht/src/Services/ExternalAccess.php']),
        'eb_external_statuses' => $entry('einsatzbericht', 'standortgebunden', 'operational', 'External unit and its report', [$parent('unit_id', 'eb_external_units')], $required, ['modules/Einsatzbericht/src/Services/ExternalAccess.php']),

        // Health and breathing apparatus.
        'as_wearers' => $entry('atemschutz', 'hybrid', 'health', 'Own user readiness and medical examination dates', [$parent('user_id', 'users')], $required, $breathing),
        'as_trainings' => $entry('atemschutz', 'hybrid', 'health', 'Own user breathing-apparatus training', [$parent('user_id', 'users')], $required, $breathing),
        'as_operations' => $entry('atemschutz', 'standortgebunden', 'operational', 'Report Standort or explicit standalone-operation Standort; starter is attribution only', [$parent('report_id', 'eb_reports', 'alternative'), $parent('started_by', 'users', 'retain')], 'NULL report is a standalone operation, not shared/public; dedicated manager/monitor rules remain and location boundary is unverified.', $breathing, targetStatus: 'pending_decision', targetGap: 'Define Standort ownership for standalone exercises instead of deriving it from a nullable actor.'),
        'as_teams' => $entry('atemschutz', 'standortgebunden', 'operational', 'Breathing-apparatus operation', [$parent('operation_id', 'as_operations')], $required, $breathing),
        'as_team_members' => $entry('atemschutz', 'hybrid', 'health', 'Team plus optional linked own member; free-text external members are supported', [$parent('team_id', 'as_teams'), $parent('user_id', 'users', 'retain')], 'NULL user retains team-owned named member; never grants access.', $breathing),
        'as_readings' => $entry('atemschutz', 'standortgebunden', 'health', 'Team and required individual team member', [$parent('team_id', 'as_teams'), $parent('team_member_id', 'as_team_members')], $required, $breathing),
        'as_warnings' => $entry('atemschutz', 'standortgebunden', 'health', 'Team warning; acknowledging user is actor only', [$parent('team_id', 'as_teams')], $required, $breathing),
        'ex_exposures' => $entry('expositionen', 'hybrid', 'health_encrypted', 'Own exposed user/report boundary with retained Standort ownership when person/report links disappear', [$parent('user_id', 'users', 'retain'), $parent('report_id', 'eb_reports', 'alternative')], 'NULL user/report does not make retained encrypted exposure public; exposure manager/own-user rules remain.', $exposure, targetStatus: 'pending_decision', targetGap: 'Define retained health-record Standort ownership when both user and report are absent; encrypted content and module-manager roles alone do not establish isolation.'),
        'ex_events' => $entry('expositionen', 'hybrid', 'health', 'Exposure audit; actor is not the data owner', [$parent('exposure_id', 'ex_exposures')], $required, $exposure),
        'ex_dismissals' => $entry('expositionen', 'hybrid', 'health', 'Own user and report prompt', [$parent('user_id', 'users'), $parent('report_id', 'eb_reports')], $required, $exposure),
        'vb_entries' => $entry('verbandbuch', 'hybrid', 'health_encrypted', 'Dedicated health-record Standort/ACL boundary plus injured-person own access; actor IDs alone are not ownership', [$parent('injured_user_id', 'users', 'retain'), $parent('first_aider_user_id', 'users', 'retain'), $parent('recorded_by', 'users', 'retain')], 'NULL person links never make health records shared; dedicated health authorization and retention apply.', $health, targetStatus: 'pending_decision', targetGap: 'Define immutable retained Standort ownership for non-member injuries and removed user links; never treat missing person IDs as shared health data.'),
        'vb_signatures' => $entry('verbandbuch', 'hybrid', 'health', 'Health entry and optional signing user', [$parent('entry_id', 'vb_entries'), $parent('user_id', 'users', 'retain')], $retained, $health),
        'vb_amendments' => $entry('verbandbuch', 'hybrid', 'health_encrypted', 'Health entry; author is actor only', [$parent('entry_id', 'vb_entries')], $required, $health),
        'vb_access_logs' => $entry('verbandbuch', 'hybrid', 'health', 'Health entry audit, under dedicated health ACL rather than general activity log', [$parent('entry_id', 'vb_entries')], $required, $health),

        // Fleet, trips and driving licences.
        'fv_profiles' => $entry('fahrzeuge', 'standortgebunden', 'financial', 'Shared vehicle; location_id is an ar_locations storage place, not x_locations', [$parent('vehicle_id', 'vehicles'), $parent('location_id', 'ar_locations', 'inherit')], 'NULL storage place does not change vehicle ownership.', $fleet),
        'fv_status_events' => $entry('fahrzeuge', 'standortgebunden', 'operational', 'Vehicle status history', [$parent('vehicle_id', 'vehicles')], $required, $fleet),
        'fv_downtimes' => $entry('fahrzeuge', 'standortgebunden', 'operational', 'Vehicle downtime', [$parent('vehicle_id', 'vehicles')], $required, $fleet),
        'fv_deadlines' => $entry('fahrzeuge', 'standortgebunden', 'operational', 'Vehicle; optional mirrored calendar event (logical ID, no database FK)', [$parent('vehicle_id', 'vehicles'), $parent('event_id', 'kal_events', 'inherit', 'logical')], 'NULL mirror event retains vehicle ownership.', $fleet),
        'fv_records' => $entry('fahrzeuge', 'standortgebunden', 'financial', 'Vehicle work; optional deadline and logical finance handoff without FK', [$parent('vehicle_id', 'vehicles'), $parent('deadline_id', 'fv_deadlines', 'inherit'), $parent('finance_entry_id', 'fin_entries', 'inherit', 'logical')], 'NULL deadline/finance link does not remove vehicle authorization.', $fleet),
        'fv_attachments' => $entry('fahrzeuge', 'standortgebunden', 'content', 'Vehicle work record', [$parent('record_id', 'fv_records')], $required, $fleet),
        'fv_documents' => $entry('fahrzeuge', 'standortgebunden', 'content', 'Vehicle; internal documents additionally require vehicle-manager permission', [$parent('vehicle_id', 'vehicles')], $required, $fleet),
        'ft_trips' => $entry('fahrtenbuch', 'hybrid', 'personnel', 'Vehicle and own driver; optional booking/report do not replace vehicle checks', [$parent('vehicle_id', 'vehicles'), $parent('driver_id', 'users'), $parent('report_id', 'eb_reports', 'inherit'), $parent('booking_id', 'bookings', 'inherit')], 'NULL report/booking means standalone trip; vehicle/driver remain required.', $logbook),
        'ft_revisions' => $entry('fahrtenbuch', 'standortgebunden', 'audit', 'Trip and vehicle; correction author is actor only', [$parent('trip_id', 'ft_trips')], $required, $logbook),
        'ft_fuelings' => $entry('fahrtenbuch', 'standortgebunden', 'financial', 'Vehicle fueling', [$parent('vehicle_id', 'vehicles')], $required, $logbook),
        'fs_licenses' => $entry('fuehrerscheine', 'hybrid', 'personnel', 'Own user licence and licence-manager review', [$parent('user_id', 'users')], $required, $license),
        'fs_photos' => $entry('fuehrerscheine', 'hybrid', 'personnel', 'Licence; transient evidence deleted after decision', [$parent('license_id', 'fs_licenses')], $required, $license),
        'fs_checks' => $entry('fuehrerscheine', 'hybrid', 'personnel', 'Licence check snapshot; checker is actor only', [$parent('license_id', 'fs_licenses')], $required, $license),

        // Kalender and Schichtplan.
        'kal_sources' => $entry('kalender', 'übergreifend', 'authentication', 'Explicitly authorized imported calendar source; secret feed credentials are never shared through NULL ownership', [], $catalog, $calendar, targetStatus: 'pending_decision', targetGap: 'Define source/credential access boundaries separately from the potentially shared imported event catalog.', targetNullRule: 'deny_or_quarantine'),
        'kal_events' => $entry('kalender', 'hybrid', 'personnel', 'Event location plus audience/invitee ACL; public capability rules are separate', [$location, $parent('source_id', 'kal_sources', 'inherit')], $shared.' NULL source denotes a local event.', [...$calendar, ...$locations]),
        'kal_event_group' => $entry('kalender', 'hybrid', 'access', 'Both event and invited group', [$parent('event_id', 'kal_events'), $parent('group_id', 'groups')], $required, $calendar),
        'kal_responses' => $entry('kalender', 'hybrid', 'personnel', 'Event plus own participant; guardian response uses signed capability', [$parent('event_id', 'kal_events'), $parent('user_id', 'users')], $required, $calendar),
        'kal_feed_tokens' => $entry('kalender', 'hybrid', 'authentication', 'Own user private calendar capability', [$parent('user_id', 'users')], $required, ['modules/Kalender/src/Services/FeedService.php']),
        'kal_event_user' => $entry('kalender', 'hybrid', 'access', 'Event and specifically invited user', [$parent('event_id', 'kal_events'), $parent('user_id', 'users')], $required, $calendar),
        'kal_event_function' => $entry('kalender', 'hybrid', 'access', 'Event and invited member function', [$parent('event_id', 'kal_events'), $parent('function_id', 'member_functions')], $required, $calendar),
        'kal_templates' => $entry('kalender', 'hybrid', 'reference', 'Template ownership must account for embedded audience/event data; creator is attribution, not a complete location boundary', [$parent('created_by', 'users', 'retain')], $catalog, $calendar, targetStatus: 'pending_decision', targetGap: 'Decide which template fields are pure shared catalog data and which require Standort/audience ownership; do not approve blanket sharing of embedded personal targets.', targetNullRule: 'deny_or_quarantine'),
        'kal_polls' => $entry('kalender', 'hybrid', 'personnel', 'Poll audience via group/function/user pivots; optional resulting event and creator', [$parent('event_id', 'kal_events', 'inherit'), $parent('created_by', 'users', 'retain')], 'NULL event means the poll has not produced an event; audience and poll-manager rules remain.', ['modules/Kalender/src/Services/PollService.php']),
        'kal_poll_group' => $entry('kalender', 'hybrid', 'access', 'Poll and audience department', [$parent('poll_id', 'kal_polls'), $parent('group_id', 'groups')], $required, ['modules/Kalender/src/Services/PollService.php']),
        'kal_poll_function' => $entry('kalender', 'hybrid', 'access', 'Poll and audience function', [$parent('poll_id', 'kal_polls'), $parent('function_id', 'member_functions')], $required, ['modules/Kalender/src/Services/PollService.php']),
        'kal_poll_user' => $entry('kalender', 'hybrid', 'access', 'Poll and specifically invited own user', [$parent('poll_id', 'kal_polls'), $parent('user_id', 'users')], $required, ['modules/Kalender/src/Services/PollService.php']),
        'kal_poll_options' => $entry('kalender', 'hybrid', 'personnel', 'Poll and its event', [$parent('poll_id', 'kal_polls')], $required, ['modules/Kalender/src/Services/PollService.php']),
        'kal_poll_votes' => $entry('kalender', 'hybrid', 'personnel', 'Poll option plus own voting user', [$parent('option_id', 'kal_poll_options'), $parent('user_id', 'users')], $required, ['modules/Kalender/src/Services/PollService.php']),
        'sp_plans' => $entry('schichtplan', 'hybrid', 'personnel', 'Optional department and mirrored calendar event; plan ACL/participants also apply', [$parent('group_id', 'groups', 'shared'), $parent('event_id', 'kal_events', 'inherit')], 'NULL department means no department restriction, not public access; plan policy remains. NULL mirror does not change plan ownership.', $shifts),
        'sp_stations' => $entry('schichtplan', 'standortgebunden', 'operational', 'Shift plan; station is a post, not x_locations', [$parent('plan_id', 'sp_plans')], $required, $shifts),
        'sp_slots' => $entry('schichtplan', 'standortgebunden', 'operational', 'Shift plan time slot', [$parent('plan_id', 'sp_plans')], $required, $shifts),
        'sp_participants' => $entry('schichtplan', 'hybrid', 'personnel', 'Shift plan and own participant', [$parent('plan_id', 'sp_plans'), $parent('user_id', 'users')], $required, $shifts),
        'sp_assignments' => $entry('schichtplan', 'hybrid', 'personnel', 'Plan, slot, post and assigned user; all must remain consistent', [$parent('plan_id', 'sp_plans'), $parent('slot_id', 'sp_slots'), $parent('station_id', 'sp_stations'), $parent('user_id', 'users')], $required, $shifts),
        'sp_templates' => $entry('schichtplan', 'übergreifend', 'reference', 'Shared shift-plan templates', [], $catalog, $shifts),

        // Personnel and training qualifications.
        'pm_history' => $entry('personalverwaltung', 'hybrid', 'personnel', 'Own member career; historical ref_id is group/function metadata, not authorization', [$parent('user_id', 'users'), $parent('ref_id', 'groups', 'retain', 'polymorphic'), $parent('ref_id', 'member_functions', 'retain', 'polymorphic')], $retained, $personnel, null, [['type_column' => 'kind', 'id_column' => 'ref_id', 'rule' => 'group references groups; function references member_functions; rank/status/event/service can have no reference; user_id remains the owner.']]),
        'pm_honor_types' => $entry('personalverwaltung', 'übergreifend', 'reference', 'Shared honor catalog', [], $catalog, $personnel),
        'pm_honors' => $entry('personalverwaltung', 'hybrid', 'personnel', 'Own user award and optional honor catalog entry', [$parent('user_id', 'users'), $parent('honor_type_id', 'pm_honor_types', 'inherit')], 'NULL honor type is a free-text honor, still owned by user.', $personnel),
        'pm_profiles' => $entry('personalverwaltung', 'hybrid', 'personnel', 'Own user personnel profile and digital ID', [$parent('user_id', 'users')], $required, $personnel),
        'pm_scopes' => $entry('personalverwaltung', 'hybrid', 'access', 'Personnel manager and permitted group; department scope is additional to module role', [$parent('user_id', 'users'), $parent('group_id', 'groups')], $required, $personnel),
        'pm_change_requests' => $entry('personalverwaltung', 'hybrid', 'personnel', 'Own user request plus responsible personnel manager', [$parent('user_id', 'users')], $required, $personnel),
        'pm_history_files' => $entry('personalverwaltung', 'hybrid', 'personnel', 'Personnel history entry and its user', [$parent('entry_id', 'pm_history')], $required, $personnel),
        'lq_courses' => $entry('lehrgaenge', 'übergreifend', 'reference', 'Shared course catalog', [], $catalog, $training),
        'lq_records' => $entry('lehrgaenge', 'hybrid', 'personnel', 'Own member qualification plus shared course; retired offering_id is not a parent', [$parent('user_id', 'users'), $parent('course_id', 'lq_courses')], 'NULL completion date is valid for manual qualifications; member and course remain required.', $training),
        'lq_record_sessions' => $entry('lehrgaenge', 'hybrid', 'personnel', 'Member qualification course day', [$parent('record_id', 'lq_records')], $required, $training),
        'lq_deletions' => $entry('lehrgaenge', 'hybrid', 'personnel', 'Member qualification deletion history; actor is not the owner', [$parent('user_id', 'users')], $required, $training),

        // Defects, finance and remaining operational modules.
        'mm_devices' => $entry('maengelmanagement', 'standortgebunden', 'operational', 'Physical device requires Standort ownership independent of the responsible actor', [$parent('responsible_id', 'users', 'inherit')], 'NULL responsible user falls back to module responsibility, not public write access.', $defects, targetStatus: 'pending_decision', targetGap: 'Define physical device Standort ownership; responsible_id is assignment, not an adequate isolation boundary.'),
        'mm_defects' => $entry('maengelmanagement', 'standortgebunden', 'personnel', 'Defect Standort plus reporter/object authorization; equipment/damage IDs lack database FKs', [$location, $parent('reporter_id', 'users'), $parent('device_id', 'mm_devices', 'alternative'), $parent('vehicle_id', 'vehicles', 'alternative'), $parent('equipment_id', 'ar_equipment', 'alternative', 'logical'), $parent('ar_damage_id', 'ar_damages', 'inherit', 'logical')], $shared.' NULL object links are valid for free reports; reporter and defect ACL remain.', [...$defects, ...$locations]),
        'mm_defect_photos' => $entry('maengelmanagement', 'standortgebunden', 'content', 'Defect and optional comment event', [$parent('defect_id', 'mm_defects'), $parent('event_id', 'mm_defect_events', 'inherit')], 'NULL event denotes original report photo; defect ACL remains.', $defects),
        'mm_defect_events' => $entry('maengelmanagement', 'standortgebunden', 'personnel', 'Defect timeline; user is actor only', [$parent('defect_id', 'mm_defects')], $required, $defects),
        'fin_cost_centers' => $entry('finanzen', 'übergreifend', 'financial', 'Shared cost-center catalog; no location dimension today', [], $catalog, $finance),
        'fin_budgets' => $entry('finanzen', 'standortgebunden', 'financial', 'Budget Standort ownership must be defined separately from the shared cost-center catalog', [$parent('cost_center_id', 'fin_cost_centers')], 'Required cost_center_id currently identifies an instance-wide cost center without a Standort column; budget feature flag and finance ACL apply.', $finance, targetStatus: 'pending_decision', targetGap: 'Decide budget Standort ownership, cost-center allocation and per-year uniqueness; the missing Standort dimension is not approval of cross-location financial visibility.'),
        'fin_entries' => $entry('finanzen', 'hybrid', 'financial', 'Own submitting user plus finance reviewers and required cost center; public overview setting is separate', [$parent('submitted_by', 'users', 'retain'), $parent('cost_center_id', 'fin_cost_centers')], 'NULL submitting user retains finance-reviewer access only; no automatic own-user/global grant.', $finance),
        'fin_receipts' => $entry('finanzen', 'hybrid', 'financial', 'Finance entry receipt, not an unrestricted file', [$parent('entry_id', 'fin_entries')], $required, $finance),
        'fin_closed_years' => $entry('finanzen', 'hybrid', 'financial', 'Annual-close scope needs an explicit Standort/aggregation decision; closing actor is not the owner', [$parent('closed_by', 'users', 'retain')], 'Current year primary key closes the whole finance module; NULL closed_by retains the closing record and does not create own-user authorization.', $finance, targetStatus: 'pending_decision', targetGap: 'Decide per-Standort versus explicitly authorized consolidated annual closing and retained scope; existing year-only storage is not cross-location sharing approval.'),
        'gf_materials' => $entry('gefahrstoffe', 'übergreifend', 'reference', 'Shared hazardous-substance catalog', [], $catalog, ['modules/Gefahrstoffe/GefahrstoffeServiceProvider.php']),
        'op_objects' => $entry('objektplaene', 'übergreifend', 'operational', 'Shared geographic object catalog; module access still required', [], $catalog, $plans),
        'op_documents' => $entry('objektplaene', 'übergreifend', 'content', 'Object plan object', [$parent('object_id', 'op_objects')], $required, $plans),
        'op_versions' => $entry('objektplaene', 'übergreifend', 'content', 'Object plan document; immutable offline-capable version', [$parent('document_id', 'op_documents')], $required, $plans),
        'op_reviews' => $entry('objektplaene', 'übergreifend', 'operational', 'Object plan review', [$parent('object_id', 'op_objects')], $required, $plans),
        'sk_keys' => $entry('schluessel', 'standortgebunden', 'operational', 'Physical key Standort, optionally inherited from an owned vehicle/object rather than a shared catalog alone', [$parent('place_id', 'places', 'alternative'), $parent('vehicle_id', 'vehicles', 'alternative'), $parent('object_id', 'op_objects', 'alternative')], 'No object link means a standalone key under key-manager ACL, not a global unrestricted object.', $keys, targetStatus: 'pending_decision', targetGap: 'Define explicit Standort ownership for standalone keys and keys linked only to shared places.'),
        'sk_handovers' => $entry('schluessel', 'hybrid', 'personnel', 'Key plus own recipient; signature has the same boundary', [$parent('key_id', 'sk_keys'), $parent('user_id', 'users')], $required, $keys),
        'ud_services' => $entry('uebungsdienste', 'hybrid', 'personnel', 'JSON group_ids/function_ids audience, optional place and calendar mirror; no scalar group FK', [$parent('place_id', 'places', 'inherit'), $parent('event_id', 'kal_events', 'inherit')], 'NULL place/calendar link does not remove audience or planner authorization.', ['modules/Uebungsdienste/UebungsdiensteServiceProvider.php']),
        'ud_service_user' => $entry('uebungsdienste', 'hybrid', 'personnel', 'Service and own responsible user', [$parent('service_id', 'ud_services'), $parent('user_id', 'users')], $required, ['modules/Uebungsdienste/UebungsdiensteServiceProvider.php']),
        'uw_topics' => $entry('unterweisungen', 'hybrid', 'reference', 'Topic audience JSON for_all/group_ids/function_ids and optional vehicle', [$parent('vehicle_id', 'vehicles', 'alternative')], 'NULL vehicle is a general instruction; topic audience and manager rules remain.', $instructions),
        'uw_sessions' => $entry('unterweisungen', 'hybrid', 'personnel', 'Instruction topic and audience; instructor is an actor', [$parent('topic_id', 'uw_topics')], $required, $instructions),
        'uw_attendances' => $entry('unterweisungen', 'hybrid', 'personnel', 'Instruction session and own attendee/signature', [$parent('session_id', 'uw_sessions'), $parent('user_id', 'users')], $required, $instructions),
        'uw_files' => $entry('unterweisungen', 'hybrid', 'content', 'Instruction session', [$parent('session_id', 'uw_sessions')], $required, $instructions),
        'wm_providers' => $entry('wartung', 'übergreifend', 'reference', 'Shared maintenance provider catalog', [], $catalog, $maintenance),
        'wm_templates' => $entry('wartung', 'hybrid', 'reference', 'Equipment category and optional maintenance provider', [$parent('category_id', 'ar_categories', 'alternative'), $parent('provider_id', 'wm_providers', 'inherit')], 'NULL category is a generic template; module manager ACL remains.', $maintenance),
        'wm_template_equipment' => $entry('wartung', 'standortgebunden', 'operational', 'Both maintenance template and equipment', [$parent('template_id', 'wm_templates'), $parent('equipment_id', 'ar_equipment')], $required, $maintenance),
        'wm_qualifications' => $entry('wartung', 'hybrid', 'personnel', 'Own user inspector qualification', [$parent('user_id', 'users')], $required, $maintenance),
        'wm_protocols' => $entry('wartung', 'standortgebunden', 'financial', 'Signed protocol must retain the equipment Standort boundary after equipment deletion', [$parent('equipment_id', 'ar_equipment', 'retain'), $parent('template_id', 'wm_templates', 'inherit'), $parent('provider_id', 'wm_providers', 'inherit')], 'NULL equipment retains immutable device snapshot under maintenance ACL; no automatic global sharing. NULL template/provider denotes manual/internal work.', $maintenance, targetStatus: 'pending_decision', targetGap: 'Define retained Standort ownership for immutable protocols whose equipment FK has become NULL.'),
        'wm_attachments' => $entry('wartung', 'standortgebunden', 'content', 'Signed maintenance protocol', [$parent('protocol_id', 'wm_protocols')], $required, $maintenance),
        'wm_tasks' => $entry('wartung', 'standortgebunden', 'operational', 'Equipment task plus optional template and resulting protocol', [$parent('equipment_id', 'ar_equipment'), $parent('template_id', 'wm_templates', 'inherit'), $parent('protocol_id', 'wm_protocols', 'inherit')], 'NULL template denotes manual task; NULL protocol means not yet performed; equipment authorization remains.', $maintenance),
        'wk_sources' => $entry('wasserkarte', 'übergreifend', 'operational', 'Shared geographic water-source catalog', [], $catalog, $water),
        'wk_inspections' => $entry('wasserkarte', 'übergreifend', 'operational', 'Water source inspection', [$parent('source_id', 'wk_sources')], $required, $water),
        'wk_defects' => $entry('wasserkarte', 'übergreifend', 'operational', 'Water source defect', [$parent('source_id', 'wk_sources')], $required, $water),
        'wk_status_events' => $entry('wasserkarte', 'übergreifend', 'operational', 'Water source status history', [$parent('source_id', 'wk_sources')], $required, $water),

        // Mitteilungen includes audience pivots and transport credentials.
        'announcements' => $entry('mitteilungen', 'hybrid', 'content', 'Optional location plus group/function audience; creator is not the whole ACL', [$location], $shared, $announcement),
        'announcement_group' => $entry('mitteilungen', 'hybrid', 'access', 'Announcement and audience department', [$parent('announcement_id', 'announcements'), $parent('group_id', 'groups')], $required, $announcement),
        'announcement_function' => $entry('mitteilungen', 'hybrid', 'access', 'Announcement and audience function', [$parent('announcement_id', 'announcements'), $parent('member_function_id', 'member_functions')], $required, $announcement),
        'announcement_files' => $entry('mitteilungen', 'hybrid', 'content', 'Announcement attachment follows recipient ACL', [$parent('announcement_id', 'announcements')], $required, $announcement),
        'announcement_reads' => $entry('mitteilungen', 'hybrid', 'personnel', 'Announcement and own recipient read/acknowledgment', [$parent('announcement_id', 'announcements'), $parent('user_id', 'users')], $required, $announcement),
        'announcement_dismissals' => $entry('mitteilungen', 'hybrid', 'personnel', 'Announcement and own dismissing user', [$parent('announcement_id', 'announcements'), $parent('user_id', 'users')], $required, $announcement),
        'x_mitteilungen_destinations' => $entry('extensions.mitteilungen', 'hybrid', 'authentication', 'Explicit transport scope_key/location; credentials are not member content', [$parent('location_id', 'x_locations', 'shared', 'conditional_foreign_key')], 'NULL location represents the explicit global transport scope, under destination-management ACL.', ['extensions/Mitteilungen/MitteilungenServiceProvider.php']),
        'x_mitteilungen_deliveries' => $entry('extensions.mitteilungen', 'hybrid', 'audit', 'Announcement plus transport scope_key; no destination FK exists', [$parent('announcement_id', 'announcements')], $required, ['extensions/Mitteilungen/MitteilungenServiceProvider.php']),

        // Locations and optional extensions: absence is allowed only for these named tables.
        'x_locations' => $entry('extensions.locations', 'übergreifend', 'reference', 'Location registry; global admins retain all locations, managers only their locations', [], $system, $locations, $optionalLocations),
        'x_location_user' => $entry('extensions.locations', 'hybrid', 'access', 'Both member and location membership/role', [$parent('location_id', 'x_locations'), $parent('user_id', 'users')], $required, $locations, $optionalLocations),
        'x_location_module_roles' => $entry('extensions.locations', 'standortgebunden', 'access', 'Explicit location/member/module grant history; role NULL is entry only, never object authority', [$parent('location_id', 'x_locations'), $parent('user_id', 'users'), $parent('granted_by', 'users'), $parent('revoked_by', 'users', 'retain')], 'Required location and member. NULL role grants only module entry; NULL expiry is indefinite. A revoked row with NULL revoked_by must declare system source membership and a reason; it never attributes the system action to a person or grants sharing.', ['extensions/Locations/Services/LocationModuleRoles.php', 'extensions/Locations/Models/LocationModuleRole.php'], $optionalLocations),
        'x_oidc_identities' => $entry('extensions.oidc', 'hybrid', 'authentication', 'Own user external identity; identity provider subject is not a location', [$parent('user_id', 'users')], $required, ['extensions/Oidc/Services/OidcLoginService.php'], $optionalOidc),
        'x_vv_associations' => $entry('extensions.verein', 'hybrid', 'access', 'Association ACL is independent of location membership', [$parent('location_id', 'x_locations', 'shared', 'conditional_foreign_key')], 'NULL location is a standalone association; only its association ACL or global admin grants access.', $association, $optionalVerein),
        'x_vv_association_user' => $entry('extensions.verein', 'hybrid', 'access', 'Association and authorized board member; not inferred from x_location_user', [$parent('association_id', 'x_vv_associations'), $parent('user_id', 'users')], $required, $association, $optionalVerein),
        'x_vv_members' => $entry('extensions.verein', 'hybrid', 'financial_encrypted', 'Association ACL, optional linked personnel user and location; encrypted identity/bank/SEPA data', [$location, $parent('association_id', 'x_vv_associations', 'retain'), $parent('user_id', 'users', 'alternative')], 'NULL user is an ordinary external member; NULL association/location never bypasses association ACL.', $association, $optionalVerein),
        'x_vv_applications' => $entry('extensions.verein', 'hybrid', 'financial_encrypted', 'Association application with encrypted applicant/bank data; no applicant user FK', [$parent('association_id', 'x_vv_associations', 'retain')], 'NULL association is legacy/unassigned application; retain dedicated application/board ACL, never make public.', $association, $optionalVerein),
        'x_att_templates' => $entry('extensions.anwesenheit', 'hybrid', 'personnel', 'Template Standort and optional leader; personal recipients require an explicit authorized boundary', [$parent('ext_location_id', 'x_locations', 'deny_non_admin', 'conditional_foreign_key'), $parent('leader_id', 'users', 'retain')], 'With Locations active and installed, AttendanceService::restrictTemplateQuery excludes NULL owners from prefillOptions, direct prefill and the template admin query for non-global users. Global admins retain all templates. Without Locations, existing module/admin access still applies. Nullable historical leader never establishes ownership.', [...$attendance, 'extensions/Anwesenheit/Filament/Resources/TemplateResource.php', 'tests/Feature/Extensions/Locations/AttendanceLocationAssignmentTest.php'], $optionalAttendance, targetGap: 'Bounded P3 handling of NULL template ownership and explicit assignment only; this does not certify all template writes, exports or other module enforcement.'),
        'x_att_lists' => $entry('extensions.anwesenheit', 'standortgebunden', 'personnel', 'Standalone list Standort; template/source event are historical hints, not authorization parents', [$parent('ext_location_id', 'x_locations', 'deny_non_admin', 'conditional_foreign_key'), $parent('template_id', 'x_att_templates', 'retain', 'historical'), $parent('source_event_id', 'kal_events', 'retain', 'historical')], 'With Locations active and its table installed, AttendanceService::canAccessLocation denies NULL location to non-global admins; visibleTo also excludes unassigned lists. Global admins retain access. Without Locations, active module users still need the list-specific manager/creator/leader/participant authorization. Missing template/source event never changes list authorization.', $attendance, $optionalAttendance),
        'x_att_participants' => $entry('extensions.anwesenheit', 'hybrid', 'personnel', 'List and own participant', [$parent('list_id', 'x_att_lists'), $parent('user_id', 'users')], $required, $attendance, $optionalAttendance),
        'x_att_files' => $entry('extensions.anwesenheit', 'standortgebunden', 'content', 'Attendance list attachment', [$parent('list_id', 'x_att_lists')], $required, $attendance, $optionalAttendance),
        'x_hc_hydrants' => $entry('extensions.hydrantenkontrolle', 'hybrid', 'operational', 'Hydrant location, NULL shared', [$alwaysLocation], $shared, $hydrants, $optionalHydrants),
        'x_hc_years' => $entry('extensions.hydrantenkontrolle', 'hybrid', 'operational', 'Inspection-year location, NULL shared', [$alwaysLocation], $shared, $hydrants, $optionalHydrants),
        'x_hc_inspections' => $entry('extensions.hydrantenkontrolle', 'hybrid', 'personnel', 'Both inspection year and hydrant must agree; inspector is actor only', [$parent('inspection_year_id', 'x_hc_years'), $parent('hydrant_id', 'x_hc_hydrants')], $required, $hydrants, $optionalHydrants),
        'x_ebp_extras' => $entry('extensions.einsatzbericht-plus', 'standortgebunden', 'personnel_encrypted', 'Report; encrypted chargeable note', [$parent('report_id', 'eb_reports')], $required, ['extensions/EinsatzberichtPlus/EinsatzberichtPlusServiceProvider.php', ...$report], $optionalReport),
        'x_ebp_persons' => $entry('extensions.einsatzbericht-plus', 'standortgebunden', 'personnel_encrypted', 'Report; encrypted third-party identity/contact data', [$parent('report_id', 'eb_reports')], $required, ['extensions/EinsatzberichtPlus/EinsatzberichtPlusServiceProvider.php', ...$report], $optionalReport),
        'x_ebp_earning_losses' => $entry('extensions.einsatzbericht-plus', 'hybrid', 'personnel_encrypted', 'Report and own affected user; encrypted employer/note', [$parent('report_id', 'eb_reports'), $parent('user_id', 'users')], $required, ['extensions/EinsatzberichtPlus/EinsatzberichtPlusServiceProvider.php', ...$report], $optionalReport),

        // Files uses its own ACL, not just a model location scope. These migrations always load.
        'x_files_spaces' => $entry('extensions.dateien', 'hybrid', 'content', 'Location boundary plus independent space/node user/group ACL', [$alwaysLocation], $shared.' Shared location does not grant a Files permission.', $files),
        'x_files_nodes' => $entry('extensions.dateien', 'hybrid', 'content', 'Space and optional parent folder; ACL inheritance is explicit', [$parent('space_id', 'x_files_spaces'), $parent('parent_id', 'x_files_nodes', 'root')], 'NULL parent is space root; inherit_permissions and explicit ACL still apply.', $files),
        'x_files_versions' => $entry('extensions.dateien', 'hybrid', 'content', 'File node and space ACL, not uploader ownership', [$parent('node_id', 'x_files_nodes')], $required, $files),
        'x_files_groups' => $entry('extensions.dateien', 'hybrid', 'access', 'Location and separate Files group-manager ACL', [$alwaysLocation], $shared.' NULL does not add membership or group-manager permission.', ['extensions/Dateien/Services/GroupService.php']),
        'x_files_group_members' => $entry('extensions.dateien', 'hybrid', 'access', 'Files group and member; is_manager is a Files role, not location admin', [$parent('group_id', 'x_files_groups'), $parent('user_id', 'users')], $required, ['extensions/Dateien/Services/GroupService.php']),
        'x_files_permissions' => $entry('extensions.dateien', 'hybrid', 'access', 'Polymorphic subject user/group and target space/node; trigger-populated actual FK projections', [$parent('subject_user_id', 'users', 'alternative'), $parent('subject_group_id', 'x_files_groups', 'alternative'), $parent('target_space_id', 'x_files_spaces', 'alternative'), $parent('target_node_id', 'x_files_nodes', 'alternative')], 'Only the projection matching subject_type/target_type is non-NULL; other NULL projections never grant permissions.', $files, null, [['type_column' => 'subject_type', 'id_column' => 'subject_id', 'rule' => 'user -> subject_user_id/users; group -> subject_group_id/x_files_groups; enforced reference projection trigger.'], ['type_column' => 'target_type', 'id_column' => 'target_id', 'rule' => 'space -> target_space_id/x_files_spaces; node -> target_node_id/x_files_nodes; enforced reference projection trigger.']]),
        'x_files_audit_events' => $entry('extensions.dateien', 'hybrid', 'audit', 'Historical space/node IDs deliberately have no FK; dedicated audit/global admin ACL', [$parent('actor_id', 'users', 'retain', 'historical'), $parent('space_id', 'x_files_spaces', 'retain', 'historical'), $parent('node_id', 'x_files_nodes', 'retain', 'historical')], 'NULL/deleted historical objects never broaden audit access.', ['extensions/Dateien/Services/FileAccess.php', 'extensions/Dateien/DateienServiceProvider.php']),
        'x_files_pending_deletions' => $entry('extensions.dateien', 'bewusst global', 'operational', 'Storage-key cleanup outbox with no surviving node/space FK; service only', [], $system, ['extensions/Dateien/Services/FileService.php']),
        'x_files_office_sessions' => $entry('extensions.dateien', 'hybrid', 'authentication', 'Own user office capability plus node/space ACL; optional source version', [$parent('user_id', 'users'), $parent('node_id', 'x_files_nodes'), $parent('version_id', 'x_files_versions', 'inherit')], 'NULL version does not remove user/node checks or capability validation.', ['extensions/Dateien/Office/OfficeService.php', ...$files]),
        'x_files_office_locks' => $entry('extensions.dateien', 'hybrid', 'authentication', 'Node and optional office session; retained lock still belongs to node', [$parent('node_id', 'x_files_nodes'), $parent('session_id', 'x_files_office_sessions', 'retain')], 'NULL session never bypasses node ACL or lock/capability validation.', ['extensions/Dateien/Office/OfficeService.php', ...$files]),
        'x_files_shares' => $entry('extensions.dateien', 'hybrid', 'authentication', 'Node ACL to create/manage; explicit expiring/revocable public share capability to read', [$parent('node_id', 'x_files_nodes')], $required, ['extensions/Dateien/Services/ShareService.php', ...$files]),
        'x_files_recent' => $entry('extensions.dateien', 'hybrid', 'personnel', 'Own user and currently authorized node', [$parent('user_id', 'users'), $parent('node_id', 'x_files_nodes')], $required, ['extensions/Dateien/Services/CollaborationService.php', ...$files]),
        'x_files_favorites' => $entry('extensions.dateien', 'hybrid', 'personnel', 'Own user favorite and currently authorized node; favorite never grants file access', [$parent('user_id', 'users'), $parent('node_id', 'x_files_nodes')], $required, ['extensions/Dateien/Services/CollaborationService.php', ...$files]),
        'x_files_subscriptions' => $entry('extensions.dateien', 'hybrid', 'personnel', 'Own user subscription and currently authorized node; subscription never grants file access', [$parent('user_id', 'users'), $parent('node_id', 'x_files_nodes')], $required, ['extensions/Dateien/Services/CollaborationService.php', ...$files]),
        'x_files_comments' => $entry('extensions.dateien', 'hybrid', 'personnel', 'Node ACL and own comment author', [$parent('node_id', 'x_files_nodes'), $parent('user_id', 'users')], $required, ['extensions/Dateien/Services/CollaborationService.php', ...$files]),
        'x_files_previews' => $entry('extensions.dateien', 'hybrid', 'content', 'Version, node and space ACL; cached derived content has the same boundary', [$parent('version_id', 'x_files_versions')], $required, ['extensions/Dateien/Services/PreviewService.php', ...$files]),
    ],
];
