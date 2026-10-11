<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_item_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('kk_items')->cascadeOnDelete();
            $table->string('type');
            $table->date('treated_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_item_treatments');
    }
};
