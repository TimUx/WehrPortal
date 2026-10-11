<?php

namespace Modules\Kalender\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Response extends Model
{
    public const STATUSES = ['yes' => 'Zugesagt', 'maybe' => 'Vielleicht', 'no' => 'Abgesagt'];

    protected $table = 'kal_responses';

    protected $fillable = ['event_id', 'user_id', 'status', 'comment', 'attended', 'set_by'];

    protected function casts(): array
    {
        return ['attended' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? 'Keine Rückmeldung';
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
