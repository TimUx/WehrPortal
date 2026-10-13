<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guided Zusatzangaben per Einsatzart (details), the signatures of Einsatzleiter and Wehrführung
 * with a checksum over the released content, Nebenberichte linked to their Hauptbericht, and the
 * reminder bookkeeping for reports that are left lying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eb_reports', function (Blueprint $table) {
            $table->json('details')->nullable();
            $table->longText('closed_signature')->nullable();      // PNG as base64
            $table->longText('released_signature')->nullable();
            $table->string('released_hash', 64)->nullable();       // sha256 of the content at the release
            $table->foreignId('parent_id')->nullable()->constrained('eb_reports')->nullOnDelete();
            $table->string('side_label', 150)->nullable();         // e.g. "Löschzug Nord", "Abschnitt B"
            $table->timestampTz('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('eb_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['details', 'closed_signature', 'released_signature', 'released_hash', 'side_label', 'reminded_at']);
        });
    }
};
