<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equipment or places a defect can be reported for; the QR label carries the code
        Schema::create('mm_devices', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mm_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('mm_devices')->nullOnDelete();
            // Free text for defects that are not tied to a registered device
            $table->string('location')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('urgency', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'urgency']);
        });

        // Photos live in the database like avatars: the container has no persistent disk
        Schema::create('mm_defect_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('defect_id')->constrained('mm_defects')->cascadeOnDelete();
            $table->string('mime', 50);
            $table->longText('data');
            $table->timestamps();
        });

        // The full history of a defect: reported, status and urgency changes, assignments, comments
        Schema::create('mm_defect_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('defect_id')->constrained('mm_defects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['defect_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mm_defect_events');
        Schema::dropIfExists('mm_defect_photos');
        Schema::dropIfExists('mm_defects');
        Schema::dropIfExists('mm_devices');
    }
};
