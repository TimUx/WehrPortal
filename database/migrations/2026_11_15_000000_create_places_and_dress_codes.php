<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared by the modules: the places a Wehr meets at (name, address for the navigation) and the
 * Kleidung that can be asked for. Every module that uses them edits the same rows, so a place or a
 * piece of Kleidung added in one module is there in all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('address', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('dress_codes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dress_codes');
        Schema::dropIfExists('places');
    }
};
