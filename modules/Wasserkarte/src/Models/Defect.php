<?php

namespace Modules\Wasserkarte\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A defect reported by a Kamerad (e.g. Deckel festgefroren, zugeparkt, Schild fehlt) */
class Defect extends Model
{
    protected $table = 'wk_defects';

    protected $fillable = ['source_id', 'reported_by', 'description', 'photo', 'resolved_at', 'resolved_by', 'resolution'];

    protected $hidden = ['photo'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
