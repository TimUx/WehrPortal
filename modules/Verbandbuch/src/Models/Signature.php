<?php

namespace Modules\Verbandbuch\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A handwritten signature on the screen (PNG), by the recorder and, if possible, the injured person */
class Signature extends Model
{
    public const ROLES = ['recorder' => 'Ersthelfer / Erfasser', 'injured' => 'Verletzte Person'];

    public $timestamps = false;

    protected $table = 'vb_signatures';

    protected $fillable = ['entry_id', 'role', 'name', 'user_id', 'data', 'signed_at'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public function dataUrl(): string
    {
        return 'data:image/png;base64,'.$this->data;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
