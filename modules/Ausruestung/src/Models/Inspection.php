<?php

namespace Modules\Ausruestung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inspection extends Model
{
    protected $table = 'ar_inspections';

    protected $fillable = ['equipment_id', 'inspected_at', 'passed', 'user_id', 'next_due_at', 'notes'];

    protected function casts(): array
    {
        return ['inspected_at' => 'date', 'next_due_at' => 'date', 'passed' => 'boolean'];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
