<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Kleiderkammer\src\Enums\ItemStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_type_id')->constrained('kk_item_types')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('kk_sizes')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('status')->default(ItemStatus::Available->value);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_items');
    }
};
