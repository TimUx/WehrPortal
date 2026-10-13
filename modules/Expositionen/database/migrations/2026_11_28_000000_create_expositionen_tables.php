<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gefahrstoff-Expositionen of Einsatzkräfte (GefStoffV § 14: documented, kept 40 years, reported to the
     * DGUV-ZED). Activity, substances and notes are encrypted; a recorded exposure can't be deleted before
     * retain_until (trigger), only a proposal that was never confirmed.
     */
    public function up(): void
    {
        Schema::create('ex_exposures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('person_name', 150);                     // kept as text when the Kamerad is deleted
            $table->foreignId('report_id')->nullable()->constrained('eb_reports')->nullOnDelete();
            $table->date('occurred_on');
            $table->string('occasion', 200);                        // "Einsatz 2027/012 · FEU 2", "Übung Brandübungsanlage"
            $table->text('activity');                               // encrypted
            $table->text('substances');                             // encrypted JSON list
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->json('routes')->nullable();                     // inhalativ, dermal, oral, unbekannt
            $table->json('protection')->nullable();                 // atemschutz, csa, …
            $table->text('notes')->nullable();                      // encrypted
            $table->string('status', 10)->default('draft');         // draft (proposed) | recorded | reported
            $table->date('reported_on')->nullable();
            $table->string('zed_reference', 100)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('retain_until');
            $table->timestamps();
            $table->index(['user_id', 'occurred_on']);
            $table->index(['status', 'occurred_on']);
            $table->index('retain_until');
        });

        // Who did what with an exposure (no content, the record itself is encrypted and not in the activity log)
        Schema::create('ex_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exposure_id')->constrained('ex_exposures')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->timestampTz('created_at');
        });

        // "Keine Exposition" for an Einsatz, so the start page stops asking
        Schema::create('ex_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'report_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ex_exposures_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'draft' AND OLD.retain_until >= CURRENT_DATE THEN
                    RAISE EXCEPTION 'ex_exposures: exposure % is kept until %', OLD.id, OLD.retain_until USING ERRCODE = '23001';
                END IF;
                RETURN OLD;
            END $$;

            CREATE TRIGGER ex_exposures_protect BEFORE DELETE ON ex_exposures
                FOR EACH ROW EXECUTE FUNCTION ex_exposures_protect();
            SQL);

        DB::table('modules')->upsert(
            [['key' => 'expositionen', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ex_dismissals');
        Schema::dropIfExists('ex_events');
        Schema::dropIfExists('ex_exposures');
        DB::unprepared('DROP FUNCTION IF EXISTS ex_exposures_protect()');
        DB::table('modules')->where('key', 'expositionen')->delete();
    }
};
