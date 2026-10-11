<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lehrgänge and other qualifications that can be acquired; validity in months for refreshers
        Schema::create('lq_courses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('abbreviation', 20)->nullable();
            $table->string('category', 30)->default('sonstiges');
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lq_course_prerequisites', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('lq_courses')->cascadeOnDelete();
            $table->foreignId('required_course_id')->constrained('lq_courses')->cascadeOnDelete();
            $table->primary(['course_id', 'required_course_id']);
        });

        // One Lehrgang of one Kamerad: requested, planned, completed, failed or cancelled
        Schema::create('lq_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lq_courses')->restrictOnDelete();
            $table->string('status', 20);
            $table->date('starts_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('provider')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('expiry_reminded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
            $table->index(['status', 'expires_on']);
        });

        // Certificates live in the database like avatars: the container has no persistent disk
        Schema::create('lq_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('lq_records')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->timestamps();
        });

        // Beförderungsvoraussetzungen per step of the Dienstgrad list (0 = Anwärter … 16 = Landesbrandmeister)
        Schema::create('lq_promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('rank_step')->unique();
            $table->decimal('min_service_years', 4, 1)->nullable();
            $table->decimal('min_years_in_rank', 4, 1)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('lq_promotion_rule_course', function (Blueprint $table) {
            $table->foreignId('rule_id')->constrained('lq_promotion_rules')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lq_courses')->cascadeOnDelete();
            $table->primary(['rule_id', 'course_id']);
        });

        $now = now();
        foreach (self::COURSES as $i => [$name, $abbr, $category, $validity]) {
            DB::table('lq_courses')->insert(['name' => $name, 'abbreviation' => $abbr, 'category' => $category, 'validity_months' => $validity, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        $id = fn (string $abbr) => DB::table('lq_courses')->where('abbreviation', $abbr)->value('id');
        foreach ([['TM2', 'TM1'], ['TF', 'TM2'], ['GF', 'TF'], ['ZF', 'GF'], ['VF', 'ZF']] as [$course, $required]) {
            DB::table('lq_course_prerequisites')->insert(['course_id' => $id($course), 'required_course_id' => $id($required)]);
        }
    }

    /** Common Lehrgänge (FwDV 2); a starting point, everything can be changed in the catalog */
    private const COURSES = [
        ['Truppmann Teil 1', 'TM1', 'grundausbildung', null],
        ['Truppmann Teil 2', 'TM2', 'grundausbildung', null],
        ['Truppführer', 'TF', 'grundausbildung', null],
        ['Sprechfunker', 'SprFu', 'grundausbildung', null],
        ['Atemschutzgeräteträger', 'AGT', 'atemschutz', null],
        ['Atemschutz-Belastungsübung', 'AGT-Ü', 'atemschutz', 12],
        ['Maschinist', 'Ma', 'technik', null],
        ['Technische Hilfeleistung', 'TH', 'technik', null],
        ['Motorsägenführer', 'MS', 'technik', null],
        ['ABC-Einsatz', 'ABC', 'technik', null],
        ['Gruppenführer', 'GF', 'fuehrung', null],
        ['Zugführer', 'ZF', 'fuehrung', null],
        ['Verbandsführer', 'VF', 'fuehrung', null],
        ['Erste Hilfe', 'EH', 'sonstiges', 24],
    ];

    public function down(): void
    {
        foreach (['lq_promotion_rule_course', 'lq_promotion_rules', 'lq_certificates', 'lq_records', 'lq_course_prerequisites', 'lq_courses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
