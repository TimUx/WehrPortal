<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Änderungswünsche: a Kamerad reports what is wrong in their Personalakte, the Personalverwalter answers */
    public function up(): void
    {
        Schema::create('pm_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 30);
            $table->text('message');
            $table->string('status', 10)->default('open');   // open | done | declined
            $table->text('answer')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_change_requests');
    }
};
