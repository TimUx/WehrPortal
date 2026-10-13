<?php

namespace Modules\Fuehrerscheine\src\Models;

use Illuminate\Database\Eloquent\Model;

/** A photo of the front or back of the Führerschein, only until it is checked */
class Photo extends Model
{
    protected $table = 'fs_photos';

    protected $fillable = ['license_id', 'side', 'mime', 'data'];

    protected $hidden = ['data'];
}
