<?php

namespace Modules\Kalender\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollVote extends Model
{
    public const ANSWERS = ['yes' => 'Ja', 'maybe' => 'Wenn nötig', 'no' => 'Nein'];

    protected $table = 'kal_poll_votes';

    protected $fillable = ['option_id', 'user_id', 'answer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}
