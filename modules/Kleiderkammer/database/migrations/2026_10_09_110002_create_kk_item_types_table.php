<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_item_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('kk_item_categories')->nullOnDelete();
            $table->string('name');
            $table->string('code_prefix')->nullable();
            $table->boolean('is_tracked')->default(true);
            $table->boolean('has_size')->default(true);
            $table->boolean('is_washable')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_item_types');
    }
};
