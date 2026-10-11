<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            // open | closed
            $table->string('status', 20)->default('open')->index();
            $table->boolean('unread_for_user')->default(false);
            $table->boolean('unread_for_admin')->default(true);
            $table->timestampTz('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_admin')->default(false);
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_messages');
        Schema::dropIfExists('feedback_threads');
    }
};
