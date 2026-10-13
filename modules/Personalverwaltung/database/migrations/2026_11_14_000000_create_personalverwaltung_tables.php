<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personalverwaltung: the career of each Kamerad (Dienstgrade, Abteilungen, Funktionen, Status with
 * from/until), Ehrungen with their kinds, the Dienstausweis, and which Abteilungen a Personalverwalter
 * may see. The current member data stays on users; this is the history and what Fox-like
 * registers add to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                         // rank | group | function | status
            $table->unsignedBigInteger('ref_id')->nullable();    // group or function id, for the sync
            $table->string('label', 200);                       // as it was then, also after renaming
            $table->date('since')->nullable();
            $table->date('until')->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'kind', 'until']);
        });

        Schema::create('pm_honor_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->unsignedSmallInteger('after_years')->nullable();  // e.g. 25 Dienstjahre
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pm_honors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('honor_type_id')->nullable()->constrained('pm_honor_types')->nullOnDelete();
            $table->string('title', 200);
            $table->date('awarded_on');
            $table->string('awarded_by', 150)->nullable();          // e.g. Kreisfeuerwehrverband
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'awarded_on']);
        });

        Schema::create('pm_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('id_card_number', 50)->nullable();       // Dienstausweis
            $table->date('id_card_valid_until')->nullable();
            $table->timestamps();
        });

        Schema::create('pm_scopes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->primary(['user_id', 'group_id']);
        });

        DB::table('modules')->upsert(
            [['key' => 'personalverwaltung', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'personalverwaltung')->delete();
        foreach (['pm_scopes', 'pm_profiles', 'pm_honors', 'pm_honor_types', 'pm_history'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
