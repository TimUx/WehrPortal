<?php

namespace Extensions\Locations\Models;

use Illuminate\Database\Eloquent\Model;

class LeadershipAssignment extends Model
{
    protected $table = 'x_leadership_assignments';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'granted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'valid_from' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
