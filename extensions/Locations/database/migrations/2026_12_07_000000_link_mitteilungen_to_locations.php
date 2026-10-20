<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcements') && Schema::hasColumn('announcements', 'ext_location_id')
            && ! $this->hasForeignKey('announcements', 'announcements_ext_location_id_foreign')) {
            Schema::table('announcements', fn (Blueprint $table) => $table->foreign('ext_location_id')
                ->references('id')->on('x_locations')->restrictOnDelete());
        }

        if (Schema::hasTable('x_mitteilungen_destinations') && Schema::hasColumn('x_mitteilungen_destinations', 'location_id')
            && ! $this->hasForeignKey('x_mitteilungen_destinations', 'x_mitteilungen_destinations_location_id_foreign')) {
            Schema::table('x_mitteilungen_destinations', fn (Blueprint $table) => $table->foreign('location_id')
                ->references('id')->on('x_locations')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey('announcements', 'announcements_ext_location_id_foreign')) {
            Schema::table('announcements', fn (Blueprint $table) => $table->dropForeign('announcements_ext_location_id_foreign'));
        }

        if ($this->hasForeignKey('x_mitteilungen_destinations', 'x_mitteilungen_destinations_location_id_foreign')) {
            Schema::table('x_mitteilungen_destinations', fn (Blueprint $table) => $table->dropForeign('x_mitteilungen_destinations_location_id_foreign'));
        }
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return Schema::hasTable($table) && DB::table('information_schema.table_constraints')
            ->where('table_name', $table)->where('constraint_name', $name)
            ->where('constraint_type', 'FOREIGN KEY')->exists();
    }
};
