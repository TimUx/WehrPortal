<?php

namespace Extensions\Mitteilungen\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelDestination extends Model
{
    protected $table = 'x_mitteilungen_destinations';

    protected $fillable = ['scope_key', 'location_id', 'transport', 'enabled', 'server_url', 'target', 'token'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'token' => 'encrypted'];
    }
}
