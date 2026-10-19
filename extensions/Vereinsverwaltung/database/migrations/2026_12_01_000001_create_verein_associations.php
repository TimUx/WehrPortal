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

        $defaultLocationId = Schema::hasTable('x_locations')
            ? DB::table('x_locations')->where('is_default', true)->value('id') ?? DB::table('x_locations')->orderBy('id')->value('id')
            : null;
        $locations = Schema::hasTable('x_locations')
            ? DB::table('x_locations')->orderBy('id')->pluck('id')
            : collect([null]);

        foreach ($locations as $locationId) {
            $oldName = DB::table('x_vv_members')
                ->when($locationId, fn ($query) => $query->where('ext_location_id', $locationId))
                ->when(! $locationId && Schema::hasColumn('x_vv_members', 'ext_location_id'), fn ($query) => $query->whereNull('ext_location_id'))
                ->value('association_name');

            $associationId = DB::table('x_vv_associations')->insertGetId([
                'name' => $oldName ?: config('vereinsverwaltung.association_name', 'Feuerwehrverein'),
                'location_id' => $locationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('x_locations') && Schema::hasColumn('x_vv_members', 'ext_location_id')) {
                DB::table('x_vv_members')->where('ext_location_id', $locationId)->update([
                    'association_id' => $associationId,
                    'ext_location_id' => $locationId,
                ]);
            } else {
                DB::table('x_vv_members')->update(['association_id' => $associationId]);
            }
        }

        if ($defaultLocationId !== null) {
            $fallbackAssociationId = DB::table('x_vv_associations')->where('location_id', $defaultLocationId)->value('id');
        } else {
            $fallbackAssociationId = DB::table('x_vv_associations')->value('id');
        }
        DB::table('x_vv_applications')->whereNull('association_id')->update(['association_id' => $fallbackAssociationId]);
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
