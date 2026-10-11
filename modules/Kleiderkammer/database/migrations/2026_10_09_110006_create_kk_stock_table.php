<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_type_id')->constrained('kk_item_types')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('kk_sizes')->nullOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();

            $table->unique(['item_type_id', 'size_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_stock');
    }
};
