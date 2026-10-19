<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_vv_applications', function (Blueprint $table) {
            $table->boolean('youth_member')->default(false);
            $table->boolean('photo_consent')->default(false);
            $table->boolean('youth_photo_consent')->default(false);
            $table->string('photo_consent_version', 30)->nullable();
            $table->timestampTz('photo_consent_recorded_at')->nullable();
        });

        Schema::table('x_vv_members', function (Blueprint $table) {
            $table->boolean('youth_member')->default(false);
            $table->boolean('photo_consent')->default(false);
            $table->boolean('youth_photo_consent')->default(false);
            $table->string('photo_consent_version', 30)->nullable();
            $table->timestampTz('photo_consent_recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('x_vv_members', function (Blueprint $table) {
            $table->dropColumn(['youth_member', 'photo_consent', 'youth_photo_consent', 'photo_consent_version', 'photo_consent_recorded_at']);
        });

        Schema::table('x_vv_applications', function (Blueprint $table) {
            $table->dropColumn(['youth_member', 'photo_consent', 'youth_photo_consent', 'photo_consent_version', 'photo_consent_recorded_at']);
        });
    }
};
