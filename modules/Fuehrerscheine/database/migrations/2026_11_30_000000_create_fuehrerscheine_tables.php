<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Führerscheine of the Kameraden: classes with their expiry, the Feuerwehr-Fahrberechtigung, the check by
     * the Führerscheinprüfer. The photos are only kept until the check – then only the result remains.
     */
    public function up(): void
    {
        Schema::create('fs_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('document_valid_until')->nullable();      // the card itself (EU cards: 15 years)
            $table->json('classes');                                // [{class: "C1", valid_until: "2031-05-01"}, …]
            $table->string('status', 10)->default('draft');         // draft | pending | validated | rejected
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_reason', 500)->nullable();
            $table->date('next_check_on')->nullable();
            $table->json('reminded')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_check_on']);
        });

        Schema::create('fs_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('fs_licenses')->cascadeOnDelete();
            $table->string('side', 5);                              // front | back
            $table->string('mime', 50);
            $table->longText('data');
            $table->timestamps();
            $table->unique(['license_id', 'side']);
        });

        // Every check with what was confirmed – the proof that the Halter checked
        Schema::create('fs_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('fs_licenses')->cascadeOnDelete();
            $table->string('result', 10);                           // validated | rejected
            $table->json('snapshot');
            $table->string('note', 500)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        DB::table('modules')->upsert(
            [['key' => 'fuehrerscheine', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_checks');
        Schema::dropIfExists('fs_photos');
        Schema::dropIfExists('fs_licenses');
        DB::table('modules')->where('key', 'fuehrerscheine')->delete();
    }
};
