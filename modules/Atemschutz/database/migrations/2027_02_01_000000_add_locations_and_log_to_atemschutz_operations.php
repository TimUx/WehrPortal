<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An Einsatz or Übung usually involves several Standorte (as_operation_locations; ext_location_id
 * stays the first one), and the Überwachung keeps a log of timed comments and special events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('as_operation_locations')) {
            Schema::create('as_operation_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operation_id')->constrained('as_operations')->cascadeOnDelete();
                $table->unsignedBigInteger('ext_location_id')->index();
                $table->unique(['operation_id', 'ext_location_id']);
            });

            if (Schema::hasColumn('as_operations', 'ext_location_id')) {
                DB::table('as_operations')->whereNotNull('ext_location_id')->orderBy('id')->each(
                    fn ($op) => DB::table('as_operation_locations')->insert(['operation_id' => $op->id, 'ext_location_id' => $op->ext_location_id])
                );
            }
        }

        if (! Schema::hasTable('as_log')) {
            Schema::create('as_log', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operation_id')->constrained('as_operations')->cascadeOnDelete();
                $table->string('kind', 10);          // note | event
                $table->string('event', 30)->nullable();
                $table->string('body', 1000)->nullable();
                $table->timestampTz('noted_at');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['operation_id', 'noted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('as_log');
        Schema::dropIfExists('as_operation_locations');
    }
};
