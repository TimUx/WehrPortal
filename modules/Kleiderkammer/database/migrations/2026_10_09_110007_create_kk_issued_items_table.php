<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_issued_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // For tracked items (is_tracked = true on item_type)
            $table->foreignId('item_id')->nullable()->constrained('kk_items')->restrictOnDelete();
            // For stock items (is_tracked = false on item_type)
            $table->foreignId('item_type_id')->nullable()->constrained('kk_item_types')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('kk_sizes')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('issued_at');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_issued_items');
    }
};
