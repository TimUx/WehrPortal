<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEY = 'ex_exposures_ext_location_fk';

    public function up(): void
    {
        if (! Schema::hasTable('x_locations') || ! Schema::hasTable('ex_exposures')
            || ! Schema::hasColumn('ex_exposures', 'ext_location_id')) {
            return;
        }

        if (collect(Schema::getForeignKeys('ex_exposures'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            return;
        }

        Schema::table('ex_exposures', function (Blueprint $table) {
            $table->foreign('ext_location_id', self::FOREIGN_KEY)
                ->references('id')->on('x_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ex_exposures')
            || ! collect(Schema::getForeignKeys('ex_exposures'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            return;
        }

        Schema::table('ex_exposures', fn (Blueprint $table) => $table->dropForeign(self::FOREIGN_KEY));
    }
};
