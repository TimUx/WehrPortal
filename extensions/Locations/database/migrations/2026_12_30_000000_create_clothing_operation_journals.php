<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_clothing_stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('from_stock_id')->constrained('kk_stock')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('to_stock_id')->constrained('kk_stock')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('from_location_id')->constrained('x_locations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('to_location_id')->constrained('x_locations')->restrictOnDelete()->restrictOnUpdate();
            $table->integer('quantity');
            $table->integer('from_before');
            $table->integer('to_before');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('reason', 500);
            $table->jsonb('catalog');
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('x_clothing_stock_transfers')->restrictOnDelete()->restrictOnUpdate();
            $table->timestampTz('recorded_at');
        });
        Schema::create('x_clothing_history_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issuance_id')->constrained('kk_issued_items')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('reviewed_stock_id')->constrained('kk_stock')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('reviewed_location_id')->constrained('x_locations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('previous_id')->nullable()->unique()->constrained('x_clothing_history_corrections')->restrictOnDelete()->restrictOnUpdate();
            $table->string('reason', 500);
            $table->string('evidence', 500);
            $table->char('state_hash', 64);
            $table->jsonb('original');
            $table->timestampTz('recorded_at');
            $table->index(['issuance_id', 'id']);
        });
        DB::statement('ALTER TABLE x_clothing_stock_transfers ADD CONSTRAINT x_clothing_transfer_quantities CHECK (quantity > 0 AND from_before >= quantity AND to_before >= 0 AND to_before::bigint + quantity <= 2147483647 AND from_stock_id <> to_stock_id AND from_location_id <> to_location_id)');
        DB::statement("ALTER TABLE x_clothing_history_corrections ADD CONSTRAINT x_clothing_correction_hash CHECK (state_hash ~ '^[a-f0-9]{64}$')");
        foreach (['x_clothing_stock_transfers', 'x_clothing_history_corrections'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_reason CHECK (length(trim(reason)) BETWEEN 1 AND 500)");
        }
        DB::statement('ALTER TABLE x_clothing_history_corrections ADD CONSTRAINT x_clothing_correction_evidence CHECK (length(trim(evidence)) BETWEEN 1 AND 500)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION x_clothing_operation_history_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Clothing operation history is append-only' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            SQL);
        foreach (['x_clothing_stock_transfers', 'x_clothing_history_corrections'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION x_clothing_operation_history_guard()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION x_clothing_operation_history_guard()");
        }
    }

    public function down(): void
    {
        foreach (['x_clothing_history_corrections', 'x_clothing_stock_transfers'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Clothing operation history exists; refusing to discard immutable journals.');
            }
        }
        Schema::dropIfExists('x_clothing_history_corrections');
        Schema::dropIfExists('x_clothing_stock_transfers');
        DB::statement('DROP FUNCTION IF EXISTS x_clothing_operation_history_guard()');
    }
};
