<?php

namespace Modules\Fahrzeuge\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo or PDF of a record (Prüfbericht, Rechnung), kept in the database */
class Attachment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fv_attachments';

    protected $fillable = ['record_id', 'name', 'mime', 'size', 'data'];

    protected $hidden = ['data'];

    /** @return BelongsTo<Record, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
