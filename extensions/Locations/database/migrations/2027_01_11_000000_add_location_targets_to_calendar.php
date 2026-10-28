<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kal_polls', 'ext_location_id')) {
            Schema::table('kal_polls', function (Blueprint $table): void {
                $table->foreignId('ext_location_id')->nullable()->constrained('x_locations')->nullOnDelete();
            });
        }

        Schema::create('kal_event_location', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained('kal_events')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('x_locations')->restrictOnDelete();
            $table->primary(['event_id', 'location_id']);
        });

        Schema::create('kal_poll_location', function (Blueprint $table): void {
            $table->foreignId('poll_id')->constrained('kal_polls')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('x_locations')->restrictOnDelete();
            $table->primary(['poll_id', 'location_id']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO kal_event_location (event_id, location_id)
            SELECT id, ext_location_id FROM kal_events WHERE ext_location_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO kal_poll_location (poll_id, location_id)
            SELECT DISTINCT kal_poll_group.poll_id, groups.ext_location_id
            FROM kal_poll_group
            JOIN groups ON groups.id = kal_poll_group.group_id
            WHERE groups.ext_location_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        DB::statement(<<<'SQL'
            UPDATE kal_polls
            SET ext_location_id = (
                SELECT MIN(location_id) FROM kal_poll_location WHERE kal_poll_location.poll_id = kal_polls.id
            )
            WHERE ext_location_id IS NULL
              AND EXISTS (SELECT 1 FROM kal_poll_location WHERE kal_poll_location.poll_id = kal_polls.id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('kal_poll_location');
        Schema::dropIfExists('kal_event_location');

        if (Schema::hasColumn('kal_polls', 'ext_location_id')) {
            Schema::table('kal_polls', fn (Blueprint $table) => $table->dropConstrainedForeignId('ext_location_id'));
        }
    }
};
