<?php

namespace Modules\Mitteilungen\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document attached to a Mitteilung (PDF, Office, picture), kept in the database */
class AnnouncementFile extends Model
{
    public const MAX_KB = 10240;

    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv'];

    protected $fillable = ['announcement_id', 'filename', 'mime', 'size', 'data'];

    protected $hidden = ['data'];

    /** @return BelongsTo<Announcement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /** "1,2 MB" */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? str_replace('.', ',', (string) round($this->size / 1048576, 1)).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
