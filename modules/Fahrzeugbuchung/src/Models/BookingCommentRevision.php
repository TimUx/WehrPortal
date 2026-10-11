<?php

namespace Modules\Fahrzeugbuchung\src\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCommentRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'body',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
