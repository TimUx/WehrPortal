<?php

namespace Modules\Einsatzbericht\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SituationPhoto extends Model
{
    protected $table = 'eb_situation_photos';

    protected $fillable = ['message_id', 'mime', 'data'];

    protected $hidden = ['data'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SituationMessage::class, 'message_id');
    }

    /** For the print view, without another request */
    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.$this->data;
    }
}
