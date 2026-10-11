<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DIVERA 24/7 webhook: drafts from alarms, and every request kept raw for a while */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eb_reports', function (Blueprint $table) {
            // manual | divera
            $table->string('source', 20)->default('manual');
            // Alarm id in DIVERA, so a re-alarm attaches instead of creating a second draft
            $table->string('divera_id', 100)->nullable()->index();
            // The alarm text as it came ("Meldung")
            $table->text('alarm_message')->nullable();
        });

        Schema::create('eb_divera_requests', function (Blueprint $table) {
            $table->id();
            $table->timestamp('received_at')->index();
            $table->string('method', 10);
            $table->string('content_type', 150)->nullable();
            $table->string('ip', 45)->nullable();
            // Unchanged body (or query string for GET)
            $table->longText('body')->nullable();
            // created | attached | filtered | disabled | error | pending
            $table->string('status', 20)->default('pending');
            $table->string('message', 500)->nullable();
            $table->foreignId('report_id')->nullable()->constrained('eb_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eb_divera_requests');
        Schema::table('eb_reports', fn (Blueprint $table) => $table->dropColumn(['source', 'divera_id', 'alarm_message']));
    }
};
