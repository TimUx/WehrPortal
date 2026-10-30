<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A timed free-text comment in the log of an Überwachung */
class LogEntry extends Model
{
    protected $table = 'as_log';

    public $timestamps = false;

    protected $fillable = ['operation_id', 'body', 'noted_at', 'user_id'];

    protected function casts(): array
    {
        return ['noted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
