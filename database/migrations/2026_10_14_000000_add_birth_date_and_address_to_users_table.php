<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('phone');
            $table->string('street')->nullable()->after('birth_date');
            $table->string('postal_code', 10)->nullable()->after('street');
            $table->string('city')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'street', 'postal_code', 'city']);
        });
    }
};
