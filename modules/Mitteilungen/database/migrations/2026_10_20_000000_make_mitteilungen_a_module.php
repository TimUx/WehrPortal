<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Notices were part of the core. As a module they stay switched on and open to everyone:
// every Kamerad gets access (new ones automatically), and whoever could post keeps that as Redakteur.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('modules')->upsert(
            [['key' => 'mitteilungen', 'is_active' => true, 'auto_assign' => true, 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['updated_at'],
        );

        $posters = Schema::hasColumn('users', 'can_post_announcements')
            ? DB::table('users')->where('can_post_announcements', true)->pluck('id')->all()
            : [];

        foreach (DB::table('users')->pluck('id') as $userId) {
            DB::table('module_user')->updateOrInsert(
                ['user_id' => $userId, 'module' => 'mitteilungen'],
                ['role' => in_array($userId, $posters, true) ? 'redakteur' : null],
            );
        }

        if (Schema::hasColumn('users', 'can_post_announcements')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_post_announcements'));
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('can_post_announcements')->default(false)->after('is_admin'));

        DB::table('users')->whereIn('id', DB::table('module_user')->where('module', 'mitteilungen')->where('role', 'redakteur')->pluck('user_id'))
            ->update(['can_post_announcements' => true]);
        DB::table('module_user')->where('module', 'mitteilungen')->delete();
        DB::table('modules')->where('key', 'mitteilungen')->delete();
    }
};
