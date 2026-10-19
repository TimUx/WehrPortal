<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_vv_applications', function (Blueprint $table) {
            $table->string('association_name')->default(config('vereinsverwaltung.association_name', 'Feuerwehrverein'));
        });

        Schema::table('x_vv_members', function (Blueprint $table) {
            $table->string('association_name')->default(config('vereinsverwaltung.association_name', 'Feuerwehrverein'));
        });
    }

    public function down(): void
    {
        Schema::table('x_vv_members', function (Blueprint $table) {
            $table->dropColumn('association_name');
        });

        Schema::table('x_vv_applications', function (Blueprint $table) {
            $table->dropColumn('association_name');
        });
    }
};
