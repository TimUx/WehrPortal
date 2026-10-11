<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Functions (what someone does) are not tied to an Abteilung (where someone is a member).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_functions', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'parent_id', 'sort_order']);
            $table->dropConstrainedForeignId('group_id');
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::rename('group_functions', 'member_functions');
        Schema::rename('group_function_user', 'member_function_user');

        Schema::table('member_function_user', function (Blueprint $table) {
            $table->renameColumn('group_function_id', 'member_function_id');
        });
    }

    public function down(): void
    {
        Schema::table('member_function_user', function (Blueprint $table) {
            $table->renameColumn('member_function_id', 'group_function_id');
        });

        Schema::rename('member_function_user', 'group_function_user');
        Schema::rename('member_functions', 'group_functions');
    }
};
