<?php

namespace Modules\Wartung\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo or PDF of a protocol (e.g. the Fachfirma's own report), kept in the database */
class Attachment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'wm_attachments';

    protected $fillable = ['protocol_id', 'name', 'mime', 'size', 'data', 'hash'];

    protected $hidden = ['data'];

    /** @return BelongsTo<Protocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
