<?php

namespace Extensions\Hydrantenkontrolle\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inspection extends Model
{
    protected $table = 'x_hc_inspections';

    protected $fillable = ['inspection_year_id', 'hydrant_id', 'snapshot', 'checked_at', 'inspector_id', 'inspector_name', 'defects', 'is_ok', 'notes', 'version'];

    protected $attributes = ['defects' => '[]', 'is_ok' => false, 'version' => 1];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'checked_at' => 'date', 'defects' => 'array', 'is_ok' => 'boolean', 'version' => 'integer'];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(InspectionYear::class, 'inspection_year_id');
    }

    public function hydrant(): BelongsTo
    {
        return $this->belongsTo(Hydrant::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function status(): string
    {
        return $this->is_ok ? 'ok' : ($this->defects !== [] ? 'defects' : 'unchecked');
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            'ok' => 'In Ordnung',
            'defects' => 'Mängel',
            default => 'Ungeprüft',
        };
    }
}
