<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_mitteilungen_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 64);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('transport', 16);
            $table->boolean('enabled')->default(false);
            $table->string('server_url', 255)->nullable();
            $table->string('target', 255)->nullable();
            $table->text('token')->nullable();
            $table->timestamps();
            $table->unique(['scope_key', 'transport']);
        });

        Schema::create('x_mitteilungen_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->string('scope_key', 64);
            $table->string('transport', 16);
            $table->char('fingerprint', 64);
            $table->string('transaction_id', 80)->nullable();
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('result', 255)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'scope_key', 'transport', 'fingerprint'], 'x_mitteilungen_delivery_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_mitteilungen_deliveries');
        Schema::dropIfExists('x_mitteilungen_destinations');
    }
};
