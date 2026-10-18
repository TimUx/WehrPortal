<?php

namespace Extensions\Dateien\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property int $node_id
 * @property int|null $version_id
 * @property string $provider
 * @property string $level
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $last_seen_at
 */
class OfficeSession extends Model
{
    protected $table = 'x_files_office_sessions';

    protected $fillable = ['token_hash', 'user_id', 'node_id', 'version_id', 'provider', 'level', 'expires_at', 'revoked_at', 'ended_at', 'last_seen_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'ended_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class)->withTrashed();
    }

    /** @return BelongsTo<Version, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Whether the file is being edited in the office right now: an open edit session with activity
     * within the idle window, or a WOPI lock that has not expired. Manual uploads wait meanwhile.
     */
    public static function editing(int $nodeId): bool
    {
        $idle = now()->subMinutes(max(5, (int) config('dateien.office.edit_idle_minutes', 60)));

        return static::where('node_id', $nodeId)->where('level', 'edit')
            ->whereNull('ended_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where(fn ($q) => $q->where('last_seen_at', '>', $idle)->orWhere(fn ($q) => $q->whereNull('last_seen_at')->where('created_at', '>', $idle)))
            ->exists()
            || DB::table('x_files_office_locks')->where('node_id', $nodeId)->where('expires_at', '>', now())->exists();
    }
}
