<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX x_location_module_roles_open');
        DB::statement("CREATE UNIQUE INDEX x_location_module_roles_open ON x_location_module_roles (location_id, user_id, module, (CASE WHEN module = 'kleiderkammer' THEN COALESCE(role, '') ELSE '' END)) WHERE revoked_at IS NULL");
    }

    public function down(): void
    {
        // Refuse rollback with parallel capabilities rather than destroy immutable grant history.
        if (DB::table('x_location_module_roles')->selectRaw('1')->whereNull('revoked_at')
            ->groupBy('location_id', 'user_id', 'module')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Revoke parallel clothing capabilities explicitly before rolling back.');
        }
        DB::statement('DROP INDEX x_location_module_roles_open');
        DB::statement('CREATE UNIQUE INDEX x_location_module_roles_open ON x_location_module_roles (location_id, user_id, module) WHERE revoked_at IS NULL');
    }
};
