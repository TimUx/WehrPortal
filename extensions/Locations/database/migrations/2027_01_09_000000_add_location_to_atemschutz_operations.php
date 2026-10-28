<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEY = 'as_operations_ext_location_fk';

    public function up(): void
    {
        if (! Schema::hasTable('as_operations') || ! Schema::hasTable('x_locations')
            || ! Schema::hasColumn('as_operations', 'ext_location_id')) {
            return;
        }

        if (Schema::hasTable('eb_reports') && Schema::hasColumn('eb_reports', 'ext_location_id')) {
            DB::table('as_operations')
                ->whereNull('ext_location_id')
                ->whereIn('report_id', DB::table('eb_reports')->select('id')->whereNotNull('ext_location_id'))
                ->update(['ext_location_id' => DB::raw('(select ext_location_id from eb_reports where eb_reports.id = as_operations.report_id)')]);
        }

        if (! collect(Schema::getForeignKeys('as_operations'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            Schema::table('as_operations', function (Blueprint $table) {
                $table->foreign('ext_location_id', self::FOREIGN_KEY)
                    ->references('id')->on('x_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('as_operations') || ! Schema::hasColumn('as_operations', 'ext_location_id')) {
            return;
        }

        if (collect(Schema::getForeignKeys('as_operations'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            Schema::table('as_operations', fn (Blueprint $table) => $table->dropForeign(self::FOREIGN_KEY));
        }
    }
};
