<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additions to the Einsatzbericht: Kostenpflicht, involved persons (third parties) and Verdienstausfall.
 * Own tables only; the core eb_* tables stay untouched. Personal data of third parties is encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_ebp_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained('eb_reports')->cascadeOnDelete();
            $table->boolean('chargeable')->default(false);
            $table->string('chargeable_to', 150)->nullable();
            $table->text('chargeable_note')->nullable(); // encrypted
            $table->timestamps();
        });

        Schema::create('x_ebp_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('name');    // encrypted
            $table->text('contact')->nullable(); // encrypted: address, phone
            $table->text('note')->nullable();    // encrypted
            $table->timestamps();
            $table->index('report_id');
        });

        Schema::create('x_ebp_earning_losses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('employer')->nullable(); // encrypted
            $table->decimal('hours', 5, 2)->nullable();
            $table->string('status', 15)->default('open'); // open | requested | settled
            $table->text('note')->nullable(); // encrypted
            $table->timestamps();
            $table->unique(['report_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_ebp_earning_losses');
        Schema::dropIfExists('x_ebp_persons');
        Schema::dropIfExists('x_ebp_extras');
    }
};
