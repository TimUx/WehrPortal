<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Members without an e-mail cannot become users; their issuances are dropped and open items freed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kk_issued_items', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id');
        });

        $now = now();

        foreach (DB::table('kk_members')->orderBy('id')->get() as $member) {
            $email = trim((string) $member->email);
            if ($email === '') {
                continue;
            }

            $userId = DB::table('users')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->value('id');
            $fields = [
                'department' => $member->department,
                'rank' => $member->role,
                'gender' => $member->gender,
                'notes' => $member->notes,
            ];

            if ($userId === null) {
                $status = $member->deleted_at !== null ? 'left' : $member->status;
                $userId = DB::table('users')->insertGetId($fields + [
                    'name' => $member->name,
                    'email' => $email,
                    'status' => $status,
                    'is_active' => $status === 'active',
                    'is_admin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                // Existing accounts keep their login status; only the member data is copied over.
                DB::table('users')->where('id', $userId)->update($fields + ['updated_at' => $now]);
            }

            DB::table('kk_issued_items')->where('member_id', $member->id)->update(['user_id' => $userId]);
        }

        $orphans = DB::table('kk_issued_items')->whereNull('user_id');
        $openItemIds = (clone $orphans)->whereNull('returned_at')->whereNotNull('item_id')->pluck('item_id');
        DB::table('kk_items')->whereIn('id', $openItemIds)->update(['status' => 'available']);
        $dropped = $orphans->delete();

        if ($dropped > 0) {
            echo "  kk_members → users: {$dropped} Ausgabe(n) von Mitgliedern ohne E-Mail entfernt.\n";
        }

        Schema::table('kk_issued_items', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropColumn('member_id');
        });

        Schema::table('kk_issued_items', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::dropIfExists('kk_members');
    }

    public function down(): void
    {
        Schema::create('kk_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('department')->default('active_service');
            $table->string('role')->nullable();
            $table->string('gender')->default('male');
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('kk_issued_items', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->foreignId('member_id')->nullable()->constrained('kk_members')->restrictOnDelete();
        });
    }
};
