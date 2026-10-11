<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kk_item_types', function (Blueprint $table) {
            $table->boolean('is_chainsaw_protection')->default(false)->after('is_washable');
        });

        Schema::create('kk_item_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_type_id')->constrained('kk_item_types')->cascadeOnDelete();
            $table->string('name', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['item_type_id', 'name']);
        });

        foreach (['kk_items', 'kk_issued_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('group_id')->nullable()->after('size_id')->constrained('kk_item_groups')->restrictOnDelete();
            });
        }

        Schema::table('kk_stock', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('size_id')->constrained('kk_item_groups')->restrictOnDelete();
            $table->dropUnique(['item_type_id', 'size_id']);
            // NULL size/group must count as a value, otherwise duplicate stock rows are possible.
            $table->unique(['item_type_id', 'size_id', 'group_id'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::table('kk_stock', function (Blueprint $table) {
            $table->dropUnique(['item_type_id', 'size_id', 'group_id']);
            $table->dropConstrainedForeignId('group_id');
            $table->unique(['item_type_id', 'size_id']);
        });

        foreach (['kk_items', 'kk_issued_items'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('group_id'));
        }

        Schema::dropIfExists('kk_item_groups');

        Schema::table('kk_item_types', fn (Blueprint $table) => $table->dropColumn('is_chainsaw_protection'));
    }
};
