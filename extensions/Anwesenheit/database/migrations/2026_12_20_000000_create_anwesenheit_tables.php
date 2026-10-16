<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone Anwesenheitslisten. A list never depends on a Kalender event or a template: source_event_id
 * is a plain number (no foreign key) that only remembers what the form was prefilled from.
 */
return new class extends Migration
{
    private function location(Blueprint $table): void
    {
        // The Standort column exists once the Locations extension is installed; without it the column is a plain number
        if (Schema::hasTable('x_locations')) {
            $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->nullOnDelete();
        } else {
            $table->unsignedBigInteger('ext_location_id')->nullable();
        }
    }

    public function up(): void
    {
        Schema::create('x_att_templates', function (Blueprint $table) {
            $table->id();
            $this->location($table);
            $table->string('name', 150);
            $table->string('kind', 20)->default('uebung');
            $table->string('title', 150)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->foreignId('leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('recipients')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('x_att_lists', function (Blueprint $table) {
            $table->id();
            $this->location($table);
            $table->string('title', 150);
            $table->string('kind', 20)->default('uebung');
            $table->string('status', 15)->default('draft'); // draft | submitted
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->foreignId('leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('guests')->default(0);
            $table->unsignedSmallInteger('participant_count')->default(0);
            $table->text('notes')->nullable();
            $table->text('recipients')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('source_event_id')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('mailed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('starts_at');
        });

        Schema::create('x_att_participants', function (Blueprint $table) {
            $table->foreignId('list_id')->constrained('x_att_lists')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['list_id', 'user_id']);
        });

        Schema::create('x_att_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('x_att_lists')->cascadeOnDelete();
            $table->string('filename', 200);
            $table->unsignedInteger('size');
            $table->longText('data'); // base64
            $table->timestamps();
        });

        DB::table('modules')->upsert(
            [['key' => 'anwesenheit', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'anwesenheit')->delete();
        Schema::dropIfExists('x_att_files');
        Schema::dropIfExists('x_att_participants');
        Schema::dropIfExists('x_att_lists');
        Schema::dropIfExists('x_att_templates');
    }
};
