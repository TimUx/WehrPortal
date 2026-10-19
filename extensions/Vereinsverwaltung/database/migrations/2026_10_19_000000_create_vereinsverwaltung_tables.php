<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_vv_applications', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->text('title')->nullable();
            $table->text('first_name')->nullable();
            $table->text('last_name')->nullable();
            $table->text('birth_date')->nullable();
            $table->text('marital_status')->nullable();
            $table->text('phone')->nullable();
            $table->text('mobile')->nullable();
            $table->text('email')->nullable();
            $table->text('street')->nullable();
            $table->text('postal_code')->nullable();
            $table->text('city')->nullable();
            $table->text('account_holder')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('bic')->nullable();
            $table->text('iban')->nullable();
            $table->string('consent_version', 30);
            $table->timestampTz('consented_at');
            $table->timestampTz('submitted_at');
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('x_vv_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('membership_type')->default('passive');
            $table->string('status')->default('member');
            $table->text('title')->nullable();
            $table->text('first_name');
            $table->text('last_name');
            $table->text('birth_date')->nullable();
            $table->text('marital_status')->nullable();
            $table->text('phone')->nullable();
            $table->text('mobile')->nullable();
            $table->text('email')->nullable();
            $table->text('street')->nullable();
            $table->text('postal_code')->nullable();
            $table->text('city')->nullable();
            $table->date('joined_at');
            $table->date('left_at')->nullable();
            $table->unsignedInteger('annual_fee_cents');
            $table->string('mandate_status')->default('awaiting_signature');
            $table->text('account_holder')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('bic')->nullable();
            $table->text('iban')->nullable();
            $table->text('mandate_reference')->nullable();
            $table->date('mandate_signed_at')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });

        DB::table('modules')->upsert(
            [['key' => 'verein', 'is_active' => false, 'auto_assign' => false, 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'verein')->delete();
        Schema::dropIfExists('x_vv_members');
        Schema::dropIfExists('x_vv_applications');
    }
};
