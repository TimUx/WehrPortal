<?php

namespace Extensions\Anwesenheit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An upload to a list (e.g. a photo of the paper list); kept as base64, attached to the mail */
class AttendanceFile extends Model
{
    protected $table = 'x_att_files';

    protected $fillable = ['list_id', 'filename', 'size', 'data'];

    protected $hidden = ['data'];

    public function list(): BelongsTo
    {
        return $this->belongsTo(AttendanceList::class, 'list_id');
    }

    public function bytes(): string
    {
        return (string) base64_decode($this->data, true);
    }
}
