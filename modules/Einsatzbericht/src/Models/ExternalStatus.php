<?php

namespace Modules\Einsatzbericht\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalStatus extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'eb_external_statuses';

    protected $fillable = ['unit_id', 'status', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ExternalUnit::class, 'unit_id');
    }

    public function label(): string
    {
        return ExternalUnit::STATUSES[$this->status] ?? $this->status;
    }
}
