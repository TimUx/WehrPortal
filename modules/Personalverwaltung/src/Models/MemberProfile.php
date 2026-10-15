<?php

namespace Modules\Personalverwaltung\src\Models;

use Illuminate\Database\Eloquent\Model;

/** What the Personalverwaltung keeps beyond users: the Dienstausweis */
class MemberProfile extends Model
{
    protected $table = 'pm_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'id_card_number', 'id_card_valid_until', 'birth_place'];

    protected function casts(): array
    {
        return ['id_card_valid_until' => 'date'];
    }
}
