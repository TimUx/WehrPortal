<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The start page keeps the own order by default; sorting by use is opt-in */
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('modules_locked')->default(true)->change());
        DB::table('users')->update(['modules_locked' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('modules_locked')->default(false)->change());
    }
};
