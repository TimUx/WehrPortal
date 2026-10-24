<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Ausbildungsleiter decides what is offered: fox112 only suggests (suggested_at), a suggestion can be
     * ignored for good (ignored_at), a Lehrgang can be taken off the list (removed_at, when Kameraden have it
     * on record), and one that left fox112 is flagged (gone_since) instead of deleted. is_standard now means
     * "offered to the Kameraden".
     */
    public function up(): void
    {
        Schema::table('lq_courses', function (Blueprint $table) {
            $table->timestamp('suggested_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('gone_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lq_courses', fn (Blueprint $table) => $table->dropColumn(['suggested_at', 'ignored_at', 'removed_at', 'gone_since']));
    }
};
