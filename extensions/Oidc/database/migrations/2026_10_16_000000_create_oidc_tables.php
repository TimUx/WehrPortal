<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_oidc_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer');
            $table->string('subject');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->unique(['user_id', 'issuer']);
        });

        // Local system admins can always log in locally (magic link), even if the IdP is exclusive or down
        if (! Schema::hasColumn('users', 'ext_local_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('ext_local_admin')->default(false);
            });
        }

        DB::table('users')->where('is_admin', true)->update(['ext_local_admin' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('x_oidc_identities');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ext_local_admin'));
    }
};
