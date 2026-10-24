<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_vv_members', function (Blueprint $table) {
            $table->text('first_name')->nullable()->change();
            $table->text('last_name')->nullable()->change();
        });
    }

    public function down(): void {}
};
