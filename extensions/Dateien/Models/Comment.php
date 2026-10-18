<?php

namespace Extensions\Dateien\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property int $user_id
 * @property string $body
 */
class Comment extends Model
{
    protected $table = 'x_files_comments';

    protected $fillable = ['node_id', 'user_id', 'body'];

    protected function casts(): array
    {
        return ['node_id' => 'integer', 'user_id' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
