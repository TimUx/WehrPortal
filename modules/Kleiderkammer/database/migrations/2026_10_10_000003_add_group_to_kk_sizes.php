<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kk_sizes', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('item_type_id')->constrained('kk_item_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kk_sizes', fn (Blueprint $table) => $table->dropConstrainedForeignId('group_id'));
    }
};
