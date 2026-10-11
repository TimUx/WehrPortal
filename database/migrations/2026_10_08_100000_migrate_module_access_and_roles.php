<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // All active users get access to Fahrzeugbuchung (previously implicit for all).
        // Existing deciders (is_decider = true) get the 'entscheider' role; others get null.
        $users = DB::table('users')->where('is_active', true)->get(['id', 'is_decider']);

        $rows = $users->map(fn ($user) => [
            'user_id' => $user->id,
            'module' => 'fahrzeugbuchung',
            'role' => $user->is_decider ? 'entscheider' : null,
        ])->all();

        if ($rows) {
            DB::table('module_user')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        DB::table('module_user')->where('module', 'fahrzeugbuchung')->delete();
    }
};
