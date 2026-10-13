<?php

namespace Modules\Unterweisungen\src\Models;

use Illuminate\Database\Eloquent\Model;

/** A document of an Unterweisung (e.g. the slides, the Betriebsanweisung, the scanned list) */
class SessionFile extends Model
{
    protected $table = 'uw_files';

    protected $fillable = ['session_id', 'filename', 'mime', 'size', 'data'];

    protected $hidden = ['data'];
}
