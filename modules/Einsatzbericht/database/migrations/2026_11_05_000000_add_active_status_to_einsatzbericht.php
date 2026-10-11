<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New state "aktuell" (active) between Entwurf and Abgeschlossen: a started Einsatz. Until now
 * every open report was an "Entwurf" and running meant "Entwurf ohne Einsatzende"; those reports
 * keep running as "aktuell", so Lagemeldungen and the QR access of running Einsätze go on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('eb_reports')->where('status', 'draft')->update(['status' => 'active']);
    }

    public function down(): void
    {
        DB::table('eb_reports')->where('status', 'active')->update(['status' => 'draft']);
    }
};
