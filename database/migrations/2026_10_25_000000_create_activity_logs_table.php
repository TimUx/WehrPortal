<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who changed what and when – also the Kameraden – plus logins and sent e-mails, for the admin "Logs" */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->index();
            // Who did it; null = the system (scheduler, import)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The admin behind it when "Als Kamerad ansehen" was on
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            // created | updated | deleted | login | impersonate | mail
            $table->string('event', 20)->index();
            $table->string('subject_type', 255)->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 500)->nullable();
            // {"field": [old, new]}
            $table->json('changes')->nullable();
            $table->foreignId('mail_log_id')->nullable()->constrained('mail_logs')->nullOnDelete();
            $table->string('ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
