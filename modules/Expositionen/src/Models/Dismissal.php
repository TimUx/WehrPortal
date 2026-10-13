<?php

namespace Modules\Expositionen\src\Models;

use Illuminate\Database\Eloquent\Model;

/** A Kamerad said "keine Exposition" for an Einsatz */
class Dismissal extends Model
{
    protected $table = 'ex_dismissals';

    protected $fillable = ['user_id', 'report_id'];
}
