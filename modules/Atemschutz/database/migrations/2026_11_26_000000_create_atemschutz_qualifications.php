<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('as_wearer_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wearer_id')->constrained('as_wearers')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title', 180)->nullable();
            $table->date('qualified_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('issuer', 180)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('proof_filename', 255)->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->longText('proof_data')->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['wearer_id', 'kind', 'qualified_on']);
        });

        DB::statement("ALTER TABLE as_wearer_qualifications ADD CONSTRAINT as_wearer_qualifications_kind CHECK (kind IN ('agt', 'csa', 'other'))");
        DB::statement("ALTER TABLE as_wearer_qualifications ADD CONSTRAINT as_wearer_qualifications_title CHECK (kind <> 'other' OR title IS NOT NULL)");
        DB::statement('ALTER TABLE as_wearer_qualifications ADD CONSTRAINT as_wearer_qualifications_proof CHECK ((proof_filename IS NULL AND proof_size IS NULL AND proof_data IS NULL) OR (proof_filename IS NOT NULL AND proof_size IS NOT NULL AND proof_data IS NOT NULL))');
        DB::statement('ALTER TABLE as_wearer_qualifications ADD CONSTRAINT as_wearer_qualifications_dates CHECK (qualified_on IS NULL OR valid_until IS NULL OR valid_until >= qualified_on)');

        DB::statement(<<<'SQL'
            INSERT INTO as_wearer_qualifications (wearer_id, kind, qualified_on, is_legacy, created_at, updated_at)
            SELECT id, 'agt', agt_qualified_on, TRUE, COALESCE(updated_at, CURRENT_TIMESTAMP), COALESCE(updated_at, CURRENT_TIMESTAMP)
            FROM as_wearers
            WHERE is_agt
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO as_wearer_qualifications (wearer_id, kind, qualified_on, is_legacy, created_at, updated_at)
            SELECT id, 'csa', csa_qualified_on, TRUE, COALESCE(updated_at, CURRENT_TIMESTAMP), COALESCE(updated_at, CURRENT_TIMESTAMP)
            FROM as_wearers
            WHERE is_csa
            SQL);

        Schema::table('as_wearers', fn (Blueprint $table) => $table->dropColumn(['is_agt', 'agt_qualified_on', 'is_csa', 'csa_qualified_on']));
    }

    public function down(): void
    {
        if (DB::table('as_wearer_qualifications')->where('kind', 'other')->exists()
            || DB::table('as_wearer_qualifications')->where(fn ($query) => $query
                ->where('is_legacy', false)
                ->orWhereNotNull('valid_until')
                ->orWhereNotNull('issuer')
                ->orWhereNotNull('notes')
                ->orWhereNotNull('proof_filename')
                ->orWhereNotNull('proof_size')
                ->orWhereNotNull('proof_data'))->exists()
            || DB::table('as_wearer_qualifications')->select('wearer_id', 'kind')->groupBy('wearer_id', 'kind')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Atemschutz qualification history cannot be rolled back to the single-value fields without losing records.');
        }

        Schema::table('as_wearers', function (Blueprint $table) {
            $table->boolean('is_agt')->default(false);
            $table->date('agt_qualified_on')->nullable();
            $table->boolean('is_csa')->default(false);
            $table->date('csa_qualified_on')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE as_wearers AS wearer
            SET is_agt = TRUE, agt_qualified_on = qualification.qualified_on
            FROM as_wearer_qualifications AS qualification
            WHERE qualification.wearer_id = wearer.id AND qualification.kind = 'agt'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE as_wearers AS wearer
            SET is_csa = TRUE, csa_qualified_on = qualification.qualified_on
            FROM as_wearer_qualifications AS qualification
            WHERE qualification.wearer_id = wearer.id AND qualification.kind = 'csa'
            SQL);

        Schema::dropIfExists('as_wearer_qualifications');
    }
};
