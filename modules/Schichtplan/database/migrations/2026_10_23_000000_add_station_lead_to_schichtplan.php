<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stationsverantwortung: one person is in charge of a station for the whole event, with or without shifts */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sp_stations', function (Blueprint $table) {
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sp_stations', fn (Blueprint $table) => $table->dropConstrainedForeignId('lead_user_id'));
    }
};
