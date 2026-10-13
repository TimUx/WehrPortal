<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mitteilungen: Funktionen as audience, attachments, and who has read or confirmed them */
    public function up(): void
    {
        Schema::table('announcements', fn (Blueprint $table) => $table->boolean('needs_ack')->default(false));

        Schema::create('announcement_function', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_function_id')->constrained()->cascadeOnDelete();
            $table->primary(['announcement_id', 'member_function_id']);
        });

        Schema::create('announcement_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 200);
            $table->string('mime', 150);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->timestamps();
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('read_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->primary(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcement_files');
        Schema::dropIfExists('announcement_function');
        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn('needs_ack'));
    }
};
