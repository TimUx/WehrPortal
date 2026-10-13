<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Web push: one row per device (browser) a Kamerad switched push on
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key', 200);
            $table->string('auth_token', 100);
            $table->string('device', 150)->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestamps();
        });

        // Erziehungsberechtigte of a (young) Kamerad: they get the Kalender mails and answer for the child
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'email']);
        });

        // Ansprechpartner of the Wehr, for everyone, with phone to tap
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('role', 150);
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('push_subscriptions');
    }
};
