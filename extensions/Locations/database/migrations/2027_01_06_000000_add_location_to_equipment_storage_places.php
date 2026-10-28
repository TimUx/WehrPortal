<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEY = 'ar_locations_ext_location_fk';

    public function up(): void
    {
        if (! Schema::hasTable('x_locations') || ! Schema::hasTable('ar_locations')) {
            return;
        }

        if (! Schema::hasColumn('ar_locations', 'ext_location_id')) {
            Schema::table('ar_locations', function (Blueprint $table) {
                $table->unsignedBigInteger('ext_location_id')->nullable()->index();
            });
        }

        if (! collect(Schema::getForeignKeys('ar_locations'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            Schema::table('ar_locations', function (Blueprint $table) {
                $table->foreign('ext_location_id', self::FOREIGN_KEY)
                    ->references('id')->on('x_locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ar_locations') || ! Schema::hasColumn('ar_locations', 'ext_location_id')) {
            return;
        }

        if (collect(Schema::getForeignKeys('ar_locations'))->contains(fn (array $foreignKey) => $foreignKey['name'] === self::FOREIGN_KEY)) {
            Schema::table('ar_locations', fn (Blueprint $table) => $table->dropForeign(self::FOREIGN_KEY));
        }
        Schema::table('ar_locations', function (Blueprint $table) {
            $table->dropIndex('ar_locations_ext_location_id_index');
            $table->dropColumn('ext_location_id');
        });
    }
};
