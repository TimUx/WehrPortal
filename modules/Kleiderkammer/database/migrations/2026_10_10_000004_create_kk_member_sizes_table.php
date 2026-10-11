<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_member_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('item_type_id')->constrained('kk_item_types')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('kk_item_groups')->restrictOnDelete();
            $table->foreignId('size_id')->constrained('kk_sizes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'item_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_member_sizes');
    }
};
