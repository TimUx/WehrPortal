<?php

namespace Modules\Verbandbuch\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Nachtrag: the only way to add to or correct an entry once it is signed */
class Amendment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'vb_amendments';

    protected $fillable = ['entry_id', 'user_id', 'user_name', 'text', 'hash'];

    protected function casts(): array
    {
        return ['text' => 'encrypted'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
