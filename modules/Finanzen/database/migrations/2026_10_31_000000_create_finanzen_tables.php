<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budget & Finanzen: Kostenstellen with a budget per year, Ausgaben (submitted by Kameraden, approved,
 * paid) and Einnahmen with their Belege, and the Jahresabschluss that locks a year. Amounts in cent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('fin_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_center_id')->constrained('fin_cost_centers')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->bigInteger('amount_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['cost_center_id', 'year']);
        });

        Schema::create('fin_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            // Running Belegnummer per year: 2027-001
            $table->unsignedInteger('number');
            $table->string('kind', 10);                // expense | income
            $table->string('status', 12)->index();    // submitted | approved | rejected | paid
            $table->foreignId('cost_center_id')->constrained('fin_cost_centers')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('vendor', 150)->nullable();
            $table->bigInteger('amount_cents');
            $table->date('booked_on');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_note', 150)->nullable();
            $table->timestamps();
            $table->unique(['year', 'number']);
            $table->index(['year', 'cost_center_id']);
        });

        Schema::create('fin_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('fin_entries')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('data');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('fin_closed_years', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at');
        });

        DB::table('modules')->upsert(
            [['key' => 'finanzen', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'finanzen')->delete();
        foreach (['fin_closed_years', 'fin_receipts', 'fin_entries', 'fin_budgets', 'fin_cost_centers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
