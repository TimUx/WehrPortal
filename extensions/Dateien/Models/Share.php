<?php

namespace Extensions\Dateien\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A public, read-only link to a file or folder (milestone 5).
 *
 * @property int $id
 * @property int $node_id
 * @property int $created_by
 * @property string|null $label
 * @property string|null $password_hash
 * @property int|null $max_downloads
 * @property int $download_count
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property Carbon|null $last_access_at
 * @property Carbon|null $created_at
 */
class Share extends Model
{
    protected $table = 'x_files_shares';

    protected $fillable = ['token_hash', 'node_id', 'created_by', 'label', 'password_hash', 'max_downloads', 'download_count', 'expires_at', 'revoked_at', 'revoked_by', 'last_access_at'];

    protected $hidden = ['token_hash', 'password_hash'];

    protected function casts(): array
    {
        return [
            'node_id' => 'integer', 'created_by' => 'integer', 'max_downloads' => 'integer', 'download_count' => 'integer',
            'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_access_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture()
            && ($this->max_downloads === null || $this->download_count < $this->max_downloads);
    }
}
