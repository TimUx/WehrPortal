<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Kept for the migration history only. It used to fill in sample vehicles and groups; new
 * instances start empty, vehicles and Abteilungen are created in the admin area
 * (or with DemoDataSeeder for development).
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
