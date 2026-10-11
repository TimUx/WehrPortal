<?php

namespace Modules\Wartung\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** An external Fachfirma (e.g. for Atemschutz, Leitern, elektrische Prüfung) */
class Provider extends Model
{
    protected $table = 'wm_providers';

    protected $fillable = ['name', 'contact_person', 'email', 'phone', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
