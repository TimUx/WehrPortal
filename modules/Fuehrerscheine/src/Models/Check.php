<?php

namespace Modules\Fuehrerscheine\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One check of a Führerschein: validated or rejected, with what was confirmed */
class Check extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fs_checks';

    protected $fillable = ['license_id', 'result', 'snapshot', 'note', 'checked_by', 'created_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
