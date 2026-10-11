<?php

namespace Modules\Finanzen\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Beleg (photo or PDF) of a Buchung, kept in the database like the other photos of the app */
class Receipt extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fin_receipts';

    protected $fillable = ['entry_id', 'name', 'mime', 'size', 'data'];

    protected $hidden = ['data'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
