<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wartungsmanagement on top of the devices of the Ausrüstungsverwaltung: Wartungsvorlagen with
 * interval, Voranzeige and checklist, the open Wartungen they generate, signed Prüfprotokolle with
 * time, costs, photos and the Prüfer's qualification, external Fachfirmen and the Prüfer-IDs.
 * A trigger keeps a signed protocol unchanged; it can only be cancelled with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wm_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_person', 150)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wm_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            // All devices of this category, plus the devices picked one by one
            $table->foreignId('category_id')->nullable()->constrained('ar_categories')->nullOnDelete();
            $table->unsignedSmallInteger('interval_months')->nullable();   // null: only ad hoc
            $table->unsignedSmallInteger('lead_days')->default(30);        // Voranzeige
            $table->json('checklist');
            $table->boolean('counts_as_inspection')->default(true);        // sets the Prüffrist of the device
            $table->string('performer', 10)->default('internal');          // internal | external
            $table->foreignId('provider_id')->nullable()->constrained('wm_providers')->nullOnDelete();
            $table->string('required_qualification', 150)->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wm_template_equipment', function (Blueprint $table) {
            $table->foreignId('template_id')->constrained('wm_templates')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('ar_equipment')->cascadeOnDelete();
            $table->primary(['template_id', 'equipment_id']);
        });

        Schema::create('wm_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);                       // e.g. Gerätewart Atemschutz
            $table->string('inspector_number', 50)->nullable(); // Prüfer-ID
            $table->date('valid_until')->nullable();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('wm_protocols', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number');
            $table->foreignId('template_id')->nullable()->constrained('wm_templates')->nullOnDelete();
            // The device may be deleted later; code and name stay in the protocol
            $table->foreignId('equipment_id')->nullable()->constrained('ar_equipment')->nullOnDelete();
            $table->string('equipment_code', 50);
            $table->string('equipment_name', 255);
            $table->string('title', 150);
            $table->date('performed_on');
            $table->string('result', 10);                      // passed | failed
            $table->json('checklist');                         // [{item, result: ok|defect|na, note}]
            $table->text('findings')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->unsignedInteger('hourly_rate_cents')->default(0);
            $table->unsignedInteger('labor_cost_cents')->default(0);
            $table->unsignedInteger('material_cost_cents')->default(0);
            $table->unsignedInteger('external_cost_cents')->default(0);
            $table->string('performer', 10);                   // internal | external
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspector_name', 150);
            $table->string('inspector_number', 50)->nullable();
            $table->string('qualification', 150)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('wm_providers')->nullOnDelete();
            $table->string('provider_name', 150)->nullable();
            $table->text('signature');                         // PNG as base64
            $table->timestampTz('signed_at');
            $table->char('content_hash', 64);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['year', 'number']);
            $table->index(['equipment_id', 'performed_on']);
            $table->index(['template_id', 'equipment_id']);
        });

        Schema::create('wm_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('wm_protocols')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->char('hash', 64);                          // SHA-256 of the file, part of the protocol hash
            $table->timestamp('created_at')->nullable();
        });

        // A Wartung that is due (Voranzeige) or overdue; generated from the templates or ad hoc
        Schema::create('wm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('wm_templates')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('ar_equipment')->cascadeOnDelete();
            $table->date('due_on');
            $table->string('status', 10)->default('open');     // open | done | cancelled
            $table->foreignId('provider_id')->nullable()->constrained('wm_providers')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('protocol_id')->nullable()->constrained('wm_protocols')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_mailed_at')->nullable();
            $table->timestampTz('overdue_mailed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_on']);
        });

        // One open Wartung per template and device
        DB::statement("CREATE UNIQUE INDEX wm_tasks_one_open ON wm_tasks (template_id, equipment_id) WHERE status = 'open' AND template_id IS NOT NULL");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wm_protocols_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'wm_protocols: protocol % is signed and cannot be deleted', OLD.id USING ERRCODE = '23001';
                END IF;
                IF (to_jsonb(NEW) - ARRAY['template_id', 'equipment_id', 'inspector_user_id', 'provider_id', 'recorded_by',
                        'voided_at', 'void_reason', 'voided_by', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['template_id', 'equipment_id', 'inspector_user_id', 'provider_id', 'recorded_by',
                        'voided_at', 'void_reason', 'voided_by', 'updated_at']) THEN
                    RAISE EXCEPTION 'wm_protocols: protocol % is signed and cannot be changed', OLD.id USING ERRCODE = '23001';
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER wm_protocols_protect BEFORE UPDATE OR DELETE ON wm_protocols
                FOR EACH ROW EXECUTE FUNCTION wm_protocols_protect();

            CREATE OR REPLACE FUNCTION wm_attachments_protect() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'wm_attachments: attachments of a signed protocol cannot be changed' USING ERRCODE = '23001';
            END $$;

            CREATE TRIGGER wm_attachments_protect BEFORE UPDATE OR DELETE ON wm_attachments
                FOR EACH ROW EXECUTE FUNCTION wm_attachments_protect();
            SQL);

        // Lifetime categories the Ausrüstungsverwaltung didn't bring yet; existing names stay as they are
        $now = now();
        DB::table('ar_categories')->insertOrIgnore(collect([
            ['name' => 'Feuerwehrschutzkleidung (Jacke/Hose)', 'lifetime_years' => 10, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; Sichtprüfung nach Gebrauch, jährliche Prüfung'],
            ['name' => 'Feuerwehrschutzhandschuhe', 'lifetime_years' => null, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; Sichtprüfung nach Gebrauch'],
            ['name' => 'Feuerwehrsicherheitsschuhe', 'lifetime_years' => null, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; Sichtprüfung'],
            ['name' => 'Sprungpolster / Sprungretter', 'lifetime_years' => null, 'inspection_interval_months' => 12, 'lifetime_basis' => 'Herstellerangabe beachten; jährliche Prüfung durch Sachkundigen'],
        ])->map(fn ($row) => [...$row, 'sort_order' => 90, 'created_at' => $now, 'updated_at' => $now])->all());

        DB::table('modules')->upsert(
            [['key' => 'wartung', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'wartung')->delete();
        foreach (['wm_tasks', 'wm_attachments', 'wm_protocols', 'wm_qualifications', 'wm_template_equipment', 'wm_templates', 'wm_providers'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS wm_protocols_protect(); DROP FUNCTION IF EXISTS wm_attachments_protect();');
    }
};
