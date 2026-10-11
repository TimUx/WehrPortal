<?php

namespace Modules\Schichtplan\src\Models;

use Illuminate\Database\Eloquent\Model;

/** A reusable set of stations */
class Template extends Model
{
    protected $table = 'sp_templates';

    protected $fillable = ['name', 'stations'];

    protected function casts(): array
    {
        return ['stations' => 'array'];
    }
}
