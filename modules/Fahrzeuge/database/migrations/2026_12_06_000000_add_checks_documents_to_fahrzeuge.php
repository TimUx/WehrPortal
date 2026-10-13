<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fahrzeugakte: Beschaffung data, documents of the vehicle (Fahrzeugschein, Gutachten …),
 * Prüfschritte for a deadline and the checklist, result and signature of a documented Prüfung,
 * which sets the Einsatzbereitschaft. Deadlines are mirrored into the Kalender (event_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fv_profiles', function (Blueprint $table) {
            $table->date('purchased_on')->nullable();
            $table->unsignedBigInteger('purchase_price_cents')->nullable();
            $table->string('supplier', 150)->nullable();
            $table->string('funding', 150)->nullable();          // Förderung, e.g. Land 40 %
            $table->date('warranty_until')->nullable();
            $table->string('status_source', 20)->nullable();     // inspection = set by a Prüfung
        });

        Schema::table('fv_deadlines', function (Blueprint $table) {
            $table->json('steps')->nullable();                    // Prüfschritte
            $table->unsignedBigInteger('event_id')->nullable();   // its Kalender entry
        });

        Schema::table('fv_records', function (Blueprint $table) {
            $table->string('invoice_number', 60)->nullable();
            $table->string('result', 12)->nullable();             // passed | limited | failed
            $table->json('checklist')->nullable();                // [{step, state: ok|defect|na, note}]
            $table->longText('signature')->nullable();            // PNG as base64
            $table->string('signer_name', 150)->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->unsignedBigInteger('finance_entry_id')->nullable();
        });

        Schema::create('fv_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title', 150);
            $table->date('valid_until')->nullable();
            $table->boolean('internal')->default(false);          // only Fahrzeugwarte and admins
            $table->string('name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fv_documents');
        Schema::table('fv_records', fn (Blueprint $t) => $t->dropColumn(['invoice_number', 'result', 'checklist', 'signature', 'signer_name', 'signed_at', 'finance_entry_id']));
        Schema::table('fv_deadlines', fn (Blueprint $t) => $t->dropColumn(['steps', 'event_id']));
        Schema::table('fv_profiles', fn (Blueprint $t) => $t->dropColumn(['purchased_on', 'purchase_price_cents', 'supplier', 'funding', 'warranty_until', 'status_source']));
    }
};
