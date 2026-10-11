<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verbandbuch & Vorfälle (DGUV Information 204-021): one signed entry per injury, accident or
 * incident, Nachträge instead of changes, and who opened an entry. The health data is encrypted
 * by the app. Triggers keep a signed entry unchanged and refuse deletes before the retention date,
 * so not even a direct query can rewrite the book.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vb_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            // Running number per year: 2027-001
            $table->unsignedInteger('number');
            $table->string('kind', 12);                // injury | accident | incident
            $table->string('occasion', 20);            // einsatz | uebung | dienst | jugend | veranstaltung | sonstiges
            $table->timestampTz('occurred_at');
            $table->string('location', 150);
            $table->foreignId('injured_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('injured_name', 150)->nullable();
            // Encrypted by the app (cast "encrypted")
            $table->text('description');
            $table->text('injury')->nullable();
            $table->text('witnesses')->nullable();
            $table->timestampTz('first_aid_at')->nullable();
            $table->text('first_aid_measures')->nullable();
            $table->foreignId('first_aider_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('first_aider_name', 150)->nullable();
            $table->boolean('doctor_visited')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('recorded_by_name', 150);
            // SHA-256 over the content and the signatures when it was signed
            $table->char('content_hash', 64);
            $table->date('retain_until');
            $table->boolean('hold')->default(false);
            $table->timestampTz('reported_at')->nullable();
            $table->string('report_note', 300)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['year', 'number']);
            $table->index('retain_until');
        });

        Schema::create('vb_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('vb_entries')->cascadeOnDelete();
            $table->string('role', 12);                // recorder | injured
            $table->string('name', 150);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // PNG as base64, like the other images of the app
            $table->text('data');
            $table->timestampTz('signed_at');
            $table->unique(['entry_id', 'role']);
        });

        Schema::create('vb_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('vb_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 150);
            $table->text('text');                      // encrypted
            // Chained: SHA-256 over the hash before it and this Nachtrag
            $table->char('hash', 64);
            $table->timestampTz('created_at');
        });

        Schema::create('vb_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('vb_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);              // created | viewed | printed | amended | reported | voided | hold | released
            $table->timestampTz('created_at');
            $table->index(['entry_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vb_entries_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.hold OR OLD.retain_until >= CURRENT_DATE THEN
                        RAISE EXCEPTION 'vb_entries: entry % is kept until %', OLD.id, OLD.retain_until USING ERRCODE = '23001';
                    END IF;
                    RETURN OLD;
                END IF;
                IF (NEW.year, NEW.number, NEW.kind, NEW.occasion, NEW.occurred_at, NEW.location, NEW.injured_name, NEW.description,
                    NEW.injury, NEW.witnesses, NEW.first_aid_at, NEW.first_aid_measures, NEW.first_aider_name, NEW.doctor_visited,
                    NEW.recorded_by_name, NEW.content_hash, NEW.retain_until, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.year, OLD.number, OLD.kind, OLD.occasion, OLD.occurred_at, OLD.location, OLD.injured_name, OLD.description,
                    OLD.injury, OLD.witnesses, OLD.first_aid_at, OLD.first_aid_measures, OLD.first_aider_name, OLD.doctor_visited,
                    OLD.recorded_by_name, OLD.content_hash, OLD.retain_until, OLD.created_at) THEN
                    RAISE EXCEPTION 'vb_entries: entry % is signed and cannot be changed', OLD.id USING ERRCODE = '23001';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER vb_entries_protect BEFORE UPDATE OR DELETE ON vb_entries
                FOR EACH ROW EXECUTE FUNCTION vb_entries_protect();

            CREATE OR REPLACE FUNCTION vb_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (to_jsonb(NEW) - 'user_id') IS DISTINCT FROM (to_jsonb(OLD) - 'user_id') THEN
                    RAISE EXCEPTION '%: rows cannot be changed', TG_TABLE_NAME USING ERRCODE = '23001';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER vb_signatures_append_only BEFORE UPDATE ON vb_signatures
                FOR EACH ROW EXECUTE FUNCTION vb_append_only();
            CREATE TRIGGER vb_amendments_append_only BEFORE UPDATE ON vb_amendments
                FOR EACH ROW EXECUTE FUNCTION vb_append_only();
            SQL);

        DB::table('modules')->upsert(
            [['key' => 'verbandbuch', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'verbandbuch')->delete();
        foreach (['vb_access_logs', 'vb_amendments', 'vb_signatures', 'vb_entries'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS vb_entries_protect(); DROP FUNCTION IF EXISTS vb_append_only();');
    }
};
