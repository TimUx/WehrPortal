<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan belongs to a Kalender event: the one it was created from, or the one publishing it
 * created (event_owned). A deleted event stays gone: event_owned without event_id is not re-created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sp_plans', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->constrained('kal_events')->nullOnDelete();
            $table->boolean('event_owned')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sp_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
            $table->dropColumn('event_owned');
        });
    }
};
