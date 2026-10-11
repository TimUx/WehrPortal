<?php

use App\Enums\MemberGender;
use App\Enums\MemberStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kk_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('department')->default('active_service');
            $table->string('role')->nullable();
            $table->string('gender')->default(MemberGender::Male->value);
            $table->string('status')->default(MemberStatus::Active->value);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_members');
    }
};
