<?php

namespace Extensions\Dateien\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $node_id
 * @property int $version_id
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
}
