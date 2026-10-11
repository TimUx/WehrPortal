<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // Extension is not dropped to avoid breaking other databases
    }
};
