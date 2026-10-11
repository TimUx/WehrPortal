<?php

namespace Modules\Kalender\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An external calendar (iCal URL) whose events are imported read-only */
class Source extends Model
{
    protected $table = 'kal_sources';

    protected $fillable = ['name', 'url', 'type', 'is_active', 'last_synced_at', 'last_error'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_synced_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'source_id');
    }
}
