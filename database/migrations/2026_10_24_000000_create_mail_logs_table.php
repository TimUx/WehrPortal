<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every e-mail the app sends, so admins can see what went to whom and send it again */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sent_at')->index();
            $table->text('to');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 500)->nullable();
            // Notification or mailable class, for the "Art" filter
            $table->string('type', 255)->nullable()->index();
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            // Login and confirmation links are not stored and can't be sent again
            $table->boolean('redacted')->default(false);
            $table->foreignId('resent_from_id')->nullable()->constrained('mail_logs')->nullOnDelete();
            $table->foreignId('resent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
    }
};
