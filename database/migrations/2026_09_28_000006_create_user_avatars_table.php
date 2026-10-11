<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stored in the database (small, resized) because the container has no persistent file storage
        Schema::create('user_avatars', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('mime', 30);
            $table->text('data'); // base64
            $table->timestamps();
        });

        // Cheap check for "has an avatar" and cache-busting without loading the image
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('avatar_updated_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_updated_at');
        });
        Schema::dropIfExists('user_avatars');
    }
};
