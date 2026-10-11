<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lehrgangstermine from the public Restplätze page of the Kreis (fox112), imported daily */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lq_courses', function (Blueprint $table) {
            // Created by the import; stays inactive (out of the overviews) until the Ausbildungsleiter takes it up
            $table->boolean('imported')->default(false);
            // Termine of this course are shown to the Kameraden
            $table->boolean('show_offerings')->default(true);
        });

        // Names under which a course appears in the Lehrgangsportal
        Schema::create('lq_course_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lq_courses')->cascadeOnDelete();
            $table->string('name', 200)->unique();
            $table->timestamps();
        });

        Schema::create('lq_offerings', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30)->default('fox112');
            $table->unsignedInteger('external_id');
            $table->foreignId('course_id')->nullable()->constrained('lq_courses')->nullOnDelete();
            $table->string('course_name', 200);
            $table->string('title', 255);
            $table->string('number', 50)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->string('places', 20)->nullable();
            $table->json('sessions')->nullable();
            $table->timestamp('details_fetched_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('gone_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->index('starts_on');
        });

        Schema::table('lq_records', function (Blueprint $table) {
            $table->foreignId('offering_id')->nullable()->constrained('lq_offerings')->nullOnDelete();
        });

        // Kameraden who don't want the mail about new Lehrgangstermine
        Schema::create('lq_offering_mail_optouts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // The prefilled courses under their names in the Lehrgangsportal
        $now = now();
        foreach (['Atemschutzgeräteträger' => 'AGT', 'Sprechfunk' => 'SprFu', 'Maschinist / Maschinistin' => 'Ma', 'Technische Hilfeleistung' => 'TH', 'Motorsägenführung' => 'MS'] as $name => $abbr) {
            if ($courseId = DB::table('lq_courses')->where('abbreviation', $abbr)->value('id')) {
                DB::table('lq_course_aliases')->insertOrIgnore(['course_id' => $courseId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lq_offering_mail_optouts');
        Schema::table('lq_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('offering_id'));
        Schema::dropIfExists('lq_offerings');
        Schema::dropIfExists('lq_course_aliases');
        Schema::table('lq_courses', fn (Blueprint $table) => $table->dropColumn(['imported', 'show_offerings']));
    }
};
