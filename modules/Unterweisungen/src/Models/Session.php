<?php

namespace Modules\Unterweisungen\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Unterweisung that was held: topic, day, who instructed, who took part and signed */
class Session extends Model
{
    protected $table = 'uw_sessions';

    protected $fillable = ['topic_id', 'held_on', 'instructor_name', 'instructor_id', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['held_on' => 'date'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(SessionFile::class)->select(['id', 'session_id', 'filename', 'mime', 'size']);
    }
}
