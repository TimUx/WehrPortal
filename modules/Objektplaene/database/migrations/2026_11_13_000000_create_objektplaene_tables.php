<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Objektpläne & Brandschutzunterlagen: the objects (with position, type and Gefährdungsklasse),
 * their documents (Feuerwehrplan, Objektplan, Brandschutzordnung …) and every uploaded version of
 * a document. Versions are never changed; a new upload becomes the current one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('op_objects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('object_type', 30);
            $table->string('hazard_class', 20)->default('normal');
            $table->string('address', 200)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('radius_m')->default(150);   // area the object covers, for the GPS suggestions
            $table->boolean('has_bma')->default(false);                // Brandmeldeanlage
            $table->boolean('has_fsd')->default(false);                // Feuerwehrschlüsseldepot
            $table->boolean('has_hazardous_materials')->default(false);
            $table->text('access')->nullable();                        // Zufahrt, Anleiterstellen, FIBS/FAT
            $table->text('hazards')->nullable();                       // besondere Gefahren
            $table->text('contacts')->nullable();                      // Ansprechpartner im Objekt
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('review_interval_months')->nullable();
            $table->date('last_reviewed_on')->nullable();
            $table->date('next_review_on')->nullable();
            $table->timestampTz('due_mailed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'object_type']);
            $table->index('next_review_on');
        });

        Schema::create('op_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('op_objects')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('title', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('op_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('op_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');                                  // file as base64
            $table->string('note', 500)->nullable();                   // what changed
            $table->date('valid_from')->nullable();                    // Stand des Plans
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['document_id', 'version']);
        });

        Schema::create('op_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('op_objects')->cascadeOnDelete();
            $table->date('reviewed_on');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        DB::table('modules')->upsert(
            [['key' => 'objektplaene', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'objektplaene')->delete();
        foreach (['op_reviews', 'op_versions', 'op_documents', 'op_objects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
