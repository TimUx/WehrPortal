<?php

namespace Modules\Wartung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Prüfer's qualification (e.g. Gerätewart Atemschutz) with his Prüfer-ID and how long it is valid */
class Qualification extends Model
{
    protected $table = 'wm_qualifications';

    protected $fillable = ['user_id', 'name', 'inspector_number', 'valid_until', 'notes'];

    protected function casts(): array
    {
        return ['valid_until' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now('Europe/Berlin')->toDateString()));
    }

    public function isValid(): bool
    {
        return $this->valid_until === null || $this->valid_until->gte(now('Europe/Berlin')->startOfDay());
    }

    public function label(): string
    {
        return $this->name.($this->inspector_number ? ' (Prüfer-ID '.$this->inspector_number.')' : '');
    }
}
