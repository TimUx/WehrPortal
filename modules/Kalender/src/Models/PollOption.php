<?php

namespace Modules\Kalender\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One date proposal of a Terminabfrage */
class PollOption extends Model
{
    public $timestamps = false;

    protected $table = 'kal_poll_options';

    protected $fillable = ['poll_id', 'starts_at', 'ends_at', 'all_day'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean'];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'option_id');
    }

    /** "Di, 14.06. 19:00–21:00" or "Sa, 12.06. (ganztägig)" in Berlin time */
    public function label(): string
    {
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $start = $this->starts_at->copy()->setTimezone('Europe/Berlin');
        $end = $this->ends_at->copy()->setTimezone('Europe/Berlin');

        return $days[$start->dayOfWeek].', '.$start->format('d.m.').($this->all_day ? ' (ganztägig)' : ' '.$start->format('H:i').'–'.$end->format('H:i'));
    }
}
