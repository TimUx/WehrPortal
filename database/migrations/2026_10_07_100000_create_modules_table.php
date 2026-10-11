<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->boolean('is_active')->default(false);
            $table->boolean('auto_assign')->default(false)
                ->comment('Neue Nutzer automatisch für dieses Modul freischalten');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
