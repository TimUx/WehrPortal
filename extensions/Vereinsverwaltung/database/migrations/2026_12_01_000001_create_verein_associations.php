<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_vv_associations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->unsignedBigInteger('location_id')->nullable()->unique();
            $table->timestampsTz();
        });

        if (Schema::hasTable('x_locations')) {
            Schema::table('x_vv_associations', function (Blueprint $table) {
                $table->foreign('location_id')->references('id')->on('x_locations')->restrictOnDelete();
            });
        }

        Schema::create('x_vv_association_user', function (Blueprint $table) {
            $table->foreignId('association_id')->constrained('x_vv_associations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['association_id', 'user_id']);
        });

        Schema::table('x_vv_members', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable()->constrained('x_vv_associations')->restrictOnDelete();
        });

        Schema::table('x_vv_applications', function (Blueprint $table) {
            $table->foreignId('association_id')->nullable()->constrained('x_vv_associations')->restrictOnDelete();
        });

        $locations = Schema::hasTable('x_locations')
            ? DB::table('x_locations')->orderBy('id')->pluck('id')
            : collect([null]);

        foreach ($locations as $locationId) {
            DB::table('x_vv_associations')->insert([
                'name' => config('vereinsverwaltung.association_name', 'Feuerwehrverein'),
                'location_id' => $locationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        }

    }

    public function down(): void
    {
        Schema::table('x_vv_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('association_id');
        });

        Schema::table('x_vv_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('association_id');
        });

        Schema::dropIfExists('x_vv_association_user');
        Schema::dropIfExists('x_vv_associations');
    }
};
