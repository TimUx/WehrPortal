<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lq_courses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('titles')->nullable();
            $table->boolean('listed')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lq_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lq_courses')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->date('starts_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('start_reminded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'course_id']);
        });

        Schema::create('lq_record_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('lq_records')->cascadeOnDelete();
            $table->date('date');
            $table->string('starts_at', 5)->nullable();
            $table->string('ends_at', 5)->nullable();
            $table->string('location', 255)->nullable();
            $table->timestamps();
            $table->index(['record_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lq_record_sessions');
        Schema::dropIfExists('lq_records');
        Schema::dropIfExists('lq_courses');
    }
};
