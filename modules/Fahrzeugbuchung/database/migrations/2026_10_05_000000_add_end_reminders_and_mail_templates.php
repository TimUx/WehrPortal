<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestampTz('end_reminder_sent_at')->nullable()->after('cancelled_at');
        });

        // Mail texts the admins can edit; rows are created from MailTemplate::defaults() on first use
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->string('greeting')->nullable();
            $table->text('body');
            $table->string('action_label')->nullable();
            $table->string('salutation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('end_reminder_sent_at');
        });
    }
};
