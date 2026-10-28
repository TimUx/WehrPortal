<?php

namespace Modules\Schichtplan\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Station extends Model
{
    protected $table = 'sp_stations';

    protected $fillable = ['plan_id', 'name', 'capacity', 'sort_order', 'lead_user_id'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'sort_order' => 'integer'];
    }

    /** Stationsverantwortung for the whole event */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
