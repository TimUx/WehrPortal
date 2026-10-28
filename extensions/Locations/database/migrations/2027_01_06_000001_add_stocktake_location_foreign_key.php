<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEY = 'ar_stocktakes_ext_location_fk';

    public function up(): void
    {
        if (! Schema::hasTable('x_locations')
            || ! Schema::hasTable('ar_stocktakes')
            || ! Schema::hasColumn('ar_stocktakes', 'ext_location_id')) {
            return;
        }

        if (! collect(Schema::getForeignKeys('ar_stocktakes'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            Schema::table('ar_stocktakes', function (Blueprint $table) {
                $table->foreign('ext_location_id', self::FOREIGN_KEY)
                    ->references('id')->on('x_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ar_stocktakes')) {
            return;
        }

        if (collect(Schema::getForeignKeys('ar_stocktakes'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            Schema::table('ar_stocktakes', fn (Blueprint $table) => $table->dropForeign(self::FOREIGN_KEY));
        }
    }
};
