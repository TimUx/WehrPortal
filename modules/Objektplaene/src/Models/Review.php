<?php

namespace Modules\Objektplaene\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Unterlagen geprüft": someone confirmed the plans of an object are still right */
class Review extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'op_reviews';

    protected $fillable = ['object_id', 'reviewed_on', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['reviewed_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
