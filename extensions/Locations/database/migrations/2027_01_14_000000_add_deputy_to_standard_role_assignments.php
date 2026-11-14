<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Stellvertretung holds the same profile with the same rights; the flag only names it.
     * Like every other field of a grant it is fixed once written (x_standard_role_history).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('x_standard_role_assignments', 'is_deputy')) {
            Schema::table('x_standard_role_assignments', function (Blueprint $table) {
                $table->boolean('is_deputy')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('x_standard_role_assignments', 'is_deputy')) {
            Schema::table('x_standard_role_assignments', function (Blueprint $table) {
                $table->dropColumn('is_deputy');
            });
        }
    }
};
