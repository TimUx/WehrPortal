<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The fixed "Abteilung" dropdown on users is replaced by the editable Abteilungen (table `groups`),
// where a member can belong to several. Existing values become memberships.
return new class extends Migration
{
    private const LABELS = [
        'active_service' => 'Aktiver Dienst',
        'youth_brigade' => 'Jugendfeuerwehr',
        'music_group' => 'Musikzug',
        'honorary' => 'Ehrenabteilung',
        'leadership' => 'Wehrführung',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::LABELS as $value => $name) {
            $userIds = DB::table('users')->where('department', $value)->pluck('id');
            if ($userIds->isEmpty()) {
                continue;
            }

            $groupId = DB::table('groups')->where('name', $name)->value('id')
                ?? DB::table('groups')->insertGetId([
                    'name' => $name,
                    'sort_order' => (int) DB::table('groups')->max('sort_order') + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach ($userIds as $userId) {
                DB::table('group_user')->insertOrIgnore(['user_id' => $userId, 'group_id' => $groupId]);
            }
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('department'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('department', 30)->nullable()->after('status'));
    }
};
