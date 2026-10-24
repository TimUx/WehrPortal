<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('is_active');
            $table->string('rank')->nullable()->after('status');
            $table->string('gender', 10)->nullable()->after('rank');
            $table->text('notes')->nullable()->after('gender');
        });

    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'rank', 'gender', 'notes']);
        });
    }
};
