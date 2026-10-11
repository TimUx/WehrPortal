<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atemschutzüberwachung (FwDV 7): the Atemschutzgeräteträger with G26.3 and their Unterweisungen
 * and Belastungsübungen, and the monitoring at the Einsatz: Trupps with their members, pressures,
 * times and the warnings that came up. An Überwachung can be linked to an Einsatzbericht.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A Kamerad in the Atemschutz pool; only dates, never medical findings
        Schema::create('as_wearers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_agt')->default(false);           // Lehrgang Atemschutzgeräteträger
            $table->date('agt_qualified_on')->nullable();
            $table->date('g263_examined_on')->nullable();
            $table->date('g263_valid_until')->nullable();
            $table->string('rfid_uid', 100)->nullable()->unique();
            $table->string('notes', 500)->nullable();
            // Which expiry dates the Kamerad was already reminded of: {item: date}
            $table->json('reminded')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('as_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20);                          // belastungsuebung | unterweisung | einsatzuebung
            $table->date('done_on');
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'kind', 'done_on']);
        });

        Schema::create('as_operations', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('kind', 10);                          // einsatz | uebung
            $table->foreignId('report_id')->nullable()->constrained('eb_reports')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('ended_at');
        });

        Schema::create('as_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('as_operations')->cascadeOnDelete();
            $table->string('name', 100);                          // Angriffstrupp 1 …
            $table->string('task', 300)->nullable();              // Auftrag
            $table->string('status', 10)->default('ready');       // ready | active | done
            $table->decimal('cylinder_liters', 4, 1);
            $table->unsignedSmallInteger('consumption_lpm');
            $table->unsignedSmallInteger('reserve_bar');
            $table->timestampTz('started_at')->nullable();        // Anschluss Lungenautomat
            $table->timestampTz('target_reached_at')->nullable();
            $table->unsignedSmallInteger('target_pressure')->nullable(); // lowest pressure at the target
            $table->timestampTz('withdrawn_at')->nullable();      // Rückzug angetreten
            $table->timestampTz('ended_at')->nullable();          // Atemschutz abgelegt
            $table->timestamps();
            $table->index(['operation_id', 'status']);
        });

        Schema::create('as_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('as_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->boolean('is_leader')->default(false);
            $table->unsignedSmallInteger('start_pressure');
            $table->unsignedSmallInteger('end_pressure')->nullable();
            // Used although not einsatzbereit (with the reason at that moment)
            $table->string('override_reason', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('as_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('as_teams')->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained('as_team_members')->cascadeOnDelete();
            $table->unsignedSmallInteger('pressure');
            $table->string('kind', 10)->default('check');         // start | check | target | end
            $table->timestampTz('recorded_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'recorded_at']);
        });

        // Warnings as they came up and when they were acknowledged, for the protocol
        Schema::create('as_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('as_teams')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('message', 300);
            $table->timestampTz('raised_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['team_id', 'kind']);
        });

        DB::table('modules')->upsert(
            [['key' => 'atemschutz', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'atemschutz')->delete();
        foreach (['as_warnings', 'as_readings', 'as_team_members', 'as_teams', 'as_operations', 'as_trainings', 'as_wearers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
