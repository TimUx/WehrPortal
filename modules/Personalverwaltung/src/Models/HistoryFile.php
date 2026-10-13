<?php

namespace Modules\Personalverwaltung\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Nachweis at an entry of the Diensthistorie: the file is base64 in the database */
class HistoryFile extends Model
{
    public const MAX_KB = 10240;

    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    protected $table = 'pm_history_files';

    protected $fillable = ['entry_id', 'filename', 'mime', 'size', 'data', 'uploaded_by'];

    protected $hidden = ['data'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(HistoryEntry::class, 'entry_id');
    }
}
