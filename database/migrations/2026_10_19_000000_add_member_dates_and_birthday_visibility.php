<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Shown on the start page of the other Kameraden (day and month only); each can opt out
            $table->boolean('show_birthday')->default(true)->after('birth_date');
            // Basis for Beförderungsvoraussetzungen: years of service and years in the current rank
            $table->date('joined_at')->nullable()->after('rank');
            $table->date('rank_since')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['show_birthday', 'joined_at', 'rank_since']));
    }
};
