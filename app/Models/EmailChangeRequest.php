<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailChangeRequest extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['user_id', 'current_email', 'new_email', 'token_hash', 'expires_at', 'created_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Expired requests are cleared out a day later */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }
}
