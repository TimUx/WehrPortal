<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('member_functions')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('member_function_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_function_id')->constrained('member_functions')->cascadeOnDelete();

            $table->primary(['user_id', 'member_function_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_function_user');
        Schema::dropIfExists('member_functions');
    }
};
