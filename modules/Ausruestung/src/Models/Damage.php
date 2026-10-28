<?php

namespace Modules\Ausruestung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ausruestung\src\Enums\DamageStatus;

class Damage extends Model
{
    protected $table = 'ar_damages';

    protected $fillable = ['equipment_id', 'reported_by', 'description', 'status', 'repair_notes', 'repair_cost', 'resolved_at'];

    protected function casts(): array
    {
        return ['status' => DamageStatus::class, 'resolved_at' => 'datetime', 'repair_cost' => 'decimal:2'];
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
