<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Messages per booking. Deleting is soft so deciders and admins can still read them.
        Schema::create('booking_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['booking_id', 'created_at']);
        });

        // Earlier versions of edited messages
        Schema::create('booking_comment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_comment_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_comment_revisions');
        Schema::dropIfExists('booking_comments');
    }
};
