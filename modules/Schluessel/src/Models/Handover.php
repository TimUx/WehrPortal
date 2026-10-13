<?php

namespace Modules\Schluessel\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Schluessel\src\Services\KeyService;

/** One handover of a key to a Kamerad, with the Schlüsselquittung, until it comes back */
class Handover extends Model
{
    protected $table = 'sk_handovers';

    protected $fillable = ['key_id', 'user_id', 'issued_at', 'due_on', 'returned_at', 'issued_by', 'returned_to', 'note', 'signature', 'reminded_at'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'due_on' => 'date', 'returned_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function key(): BelongsTo
    {
        return $this->belongsTo(Key::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_to');
    }

    public function overdue(): bool
    {
        return $this->returned_at === null && $this->due_on !== null && $this->due_on->lt(KeyService::today());
    }
}
