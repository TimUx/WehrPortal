<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_threads', function (Blueprint $table) {
            $table->string('module_key')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_threads', function (Blueprint $table) {
            $table->dropColumn('module_key');
        });
    }
};
