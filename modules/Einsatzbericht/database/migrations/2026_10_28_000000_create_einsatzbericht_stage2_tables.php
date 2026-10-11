<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Stage 2: Lagemeldungen with photos (Einsatztagebuch) and Verbrauchsmaterial */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eb_situation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text')->nullable();
            $table->boolean('important')->default(false);
            $table->timestamps();
            $table->index(['report_id', 'created_at']);
        });

        // Photos scaled down on the server and kept as WebP in the database, like the Mängel photos
        Schema::create('eb_situation_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('eb_situation_messages')->cascadeOnDelete();
            $table->string('mime', 50);
            $table->longText('data');
            $table->timestamps();
        });

        // Catalog of Verbrauchsmaterial, kept by the admins
        Schema::create('eb_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('category', 30);
            $table->string('unit', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['name', 'unit']);
        });

        // What was used on an Einsatz; name, category and unit are copied so a later catalog change keeps the report
        Schema::create('eb_report_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('eb_reports')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('eb_materials')->nullOnDelete();
            $table->string('name', 150);
            $table->string('category', 30);
            $table->string('unit', 20);
            $table->decimal('quantity', 10, 2);
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['Schaummittel', 'loeschmittel', 'liter'], ['Netzmittel', 'loeschmittel', 'liter'], ['Löschpulver', 'loeschmittel', 'kilogramm'],
            ['Ölbindemittel', 'bindemittel', 'sack'], ['Ölbindemittel', 'bindemittel', 'kilogramm'],
            ['Verbandmaterial', 'sanitaetsmaterial', 'stueck'], ['Einmalhandschuhe', 'sanitaetsmaterial', 'stueck'],
            ['Diesel', 'betriebsstoffe', 'liter'], ['Benzin (Sonderkraftstoff)', 'betriebsstoffe', 'liter'], ['Kettenöl', 'betriebsstoffe', 'liter'],
        ] as $i => [$name, $category, $unit]) {
            DB::table('eb_materials')->insert(['name' => $name, 'category' => $category, 'unit' => $unit, 'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['eb_report_materials', 'eb_materials', 'eb_situation_photos', 'eb_situation_messages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
