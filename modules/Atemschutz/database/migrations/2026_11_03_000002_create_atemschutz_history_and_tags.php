<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('as_wearer_examinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wearer_id')->constrained('as_wearers')->cascadeOnDelete();
            $table->date('examined_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['wearer_id', 'examined_on']);
        });
        DB::statement('ALTER TABLE as_wearer_examinations ADD CONSTRAINT as_wearer_examinations_has_date CHECK (examined_on IS NOT NULL OR valid_until IS NOT NULL)');
        DB::statement('ALTER TABLE as_wearer_examinations ADD CONSTRAINT as_wearer_examinations_dates_order CHECK (examined_on IS NULL OR valid_until IS NULL OR valid_until >= examined_on)');

        DB::statement(<<<'SQL'
            INSERT INTO as_wearer_examinations (wearer_id, examined_on, valid_until, is_legacy, created_at, updated_at)
            SELECT id, g263_examined_on, g263_valid_until, TRUE, COALESCE(updated_at, CURRENT_TIMESTAMP), COALESCE(updated_at, CURRENT_TIMESTAMP)
            FROM as_wearers
            WHERE g263_examined_on IS NOT NULL OR g263_valid_until IS NOT NULL
            SQL);

        Schema::create('as_tag_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('tag_uid', 100);
            $table->string('standard', 30)->default('rfid');
            $table->foreignId('wearer_id')->nullable()->constrained('as_wearers')->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('as_equipment')->cascadeOnDelete();
            $table->boolean('is_legacy')->default(false);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at');
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestamps();
            $table->index(['wearer_id', 'deactivated_at']);
            $table->index(['equipment_id', 'deactivated_at']);
        });
        DB::statement('ALTER TABLE as_tag_assignments ADD CONSTRAINT as_tag_assignments_one_target CHECK ((wearer_id IS NOT NULL)::int + (equipment_id IS NOT NULL)::int = 1)');
        DB::statement('CREATE UNIQUE INDEX as_tag_assignments_one_active_uid ON as_tag_assignments (tag_uid) WHERE deactivated_at IS NULL');

        DB::statement(<<<'SQL'
            INSERT INTO as_tag_assignments (tag_uid, wearer_id, is_legacy, assigned_at, created_at, updated_at)
            SELECT regexp_replace(upper(rfid_uid), '[^0-9A-Z]', '', 'g'), id, TRUE, COALESCE(updated_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM as_wearers
            WHERE rfid_uid IS NOT NULL AND regexp_replace(upper(rfid_uid), '[^0-9A-Z]', '', 'g') <> ''
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO as_tag_assignments (tag_uid, equipment_id, is_legacy, assigned_at, created_at, updated_at)
            SELECT regexp_replace(upper(rfid), '[^0-9A-Z]', '', 'g'), id, TRUE, COALESCE(updated_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM as_equipment
            WHERE rfid IS NOT NULL AND regexp_replace(upper(rfid), '[^0-9A-Z]', '', 'g') <> ''
            SQL);

        Schema::table('as_wearers', fn (Blueprint $table) => $table->dropColumn(['g263_examined_on', 'g263_valid_until', 'rfid_uid']));
        Schema::table('as_equipment', fn (Blueprint $table) => $table->dropColumn('rfid'));
    }

    public function down(): void
    {
        if (DB::table('as_wearer_examinations')->select('wearer_id')->groupBy('wearer_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('as_tag_assignments')->whereNotNull('deactivated_at')->exists()
            || DB::table('as_tag_assignments')->select('wearer_id')->whereNotNull('wearer_id')->groupBy('wearer_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('as_tag_assignments')->select('equipment_id')->whereNotNull('equipment_id')->groupBy('equipment_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Atemschutz history cannot be rolled back to the single-value fields without losing records.');
        }

        Schema::table('as_wearers', function (Blueprint $table) {
            $table->date('g263_examined_on')->nullable();
            $table->date('g263_valid_until')->nullable();
            $table->string('rfid_uid', 100)->nullable()->unique();
        });
        Schema::table('as_equipment', fn (Blueprint $table) => $table->string('rfid', 100)->nullable()->unique());

        DB::statement(<<<'SQL'
            UPDATE as_wearers AS wearer
            SET g263_examined_on = examination.examined_on,
                g263_valid_until = examination.valid_until
            FROM as_wearer_examinations AS examination
            WHERE examination.wearer_id = wearer.id
            SQL);
        DB::statement(<<<'SQL'
            UPDATE as_wearers AS wearer
            SET rfid_uid = assignment.tag_uid
            FROM as_tag_assignments AS assignment
            WHERE assignment.wearer_id = wearer.id AND assignment.deactivated_at IS NULL
            SQL);
        DB::statement(<<<'SQL'
            UPDATE as_equipment AS equipment
            SET rfid = assignment.tag_uid
            FROM as_tag_assignments AS assignment
            WHERE assignment.equipment_id = equipment.id AND assignment.deactivated_at IS NULL
            SQL);

        Schema::dropIfExists('as_tag_assignments');
        Schema::dropIfExists('as_wearer_examinations');
    }
};
