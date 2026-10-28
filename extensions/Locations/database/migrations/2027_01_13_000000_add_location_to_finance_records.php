<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('x_locations')) {
            return;
        }

        if (Schema::hasTable('fin_budgets') && ! Schema::hasColumn('fin_budgets', 'location_id')) {
            Schema::table('fin_budgets', function (Blueprint $table): void {
                $table->dropUnique(['cost_center_id', 'year']);
                $table->foreignId('location_id')->nullable()->constrained('x_locations')->restrictOnDelete();
            });
            DB::statement('CREATE UNIQUE INDEX fin_budgets_global_cost_center_year_unique ON fin_budgets (cost_center_id, year) WHERE location_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX fin_budgets_location_cost_center_year_unique ON fin_budgets (location_id, cost_center_id, year) WHERE location_id IS NOT NULL');
        }

        if (Schema::hasTable('fin_entries') && ! Schema::hasColumn('fin_entries', 'location_id')) {
            Schema::table('fin_entries', function (Blueprint $table): void {
                $table->foreignId('location_id')->nullable()->constrained('x_locations')->restrictOnDelete();
                $table->index(['location_id', 'year', 'cost_center_id'], 'fin_entries_location_year_center_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fin_budgets') && Schema::hasColumn('fin_budgets', 'location_id')) {
            DB::statement('DROP INDEX IF EXISTS fin_budgets_global_cost_center_year_unique');
            DB::statement('DROP INDEX IF EXISTS fin_budgets_location_cost_center_year_unique');
            Schema::table('fin_budgets', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('location_id');
                $table->unique(['cost_center_id', 'year']);
            });
        }

        if (Schema::hasTable('fin_entries') && Schema::hasColumn('fin_entries', 'location_id')) {
            Schema::table('fin_entries', function (Blueprint $table): void {
                $table->dropIndex('fin_entries_location_year_center_index');
                $table->dropConstrainedForeignId('location_id');
            });
        }
    }
};
