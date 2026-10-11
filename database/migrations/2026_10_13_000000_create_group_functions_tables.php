<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Functions within a group (e.g. "Gruppenführer"); a function may have sub-functions through parent_id
        Schema::create('group_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('group_functions')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['group_id', 'parent_id', 'sort_order']);
        });

        Schema::create('group_function_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_function_id')->constrained('group_functions')->cascadeOnDelete();

            $table->primary(['user_id', 'group_function_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_function_user');
        Schema::dropIfExists('group_functions');
    }
};
