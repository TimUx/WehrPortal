<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('is_active');
            $table->string('department', 30)->nullable()->after('status');
            $table->string('rank')->nullable()->after('department');
            $table->string('gender', 10)->nullable()->after('rank');
            $table->text('notes')->nullable()->after('gender');
        });

        DB::table('users')->where('is_active', false)->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'department', 'rank', 'gender', 'notes']);
        });
    }
};
