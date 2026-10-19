<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Members without login (e.g. Jugendfeuerwehr children, Musikzug): no e-mail address. They are kept in
     * Kleiderkammer, Unterweisungen, Verbandbuch … but can't log in. Unique stays (Postgres allows many NULLs).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
    }

    public function down(): void
    {
        // Members without an address would block NOT NULL; they keep it nullable
    }
};
