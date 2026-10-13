<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * An Erziehungsberechtigte(r) of a Kamerad, e.g. in the Jugendfeuerwehr. No login of their own:
 * they get the Kalender mails of the child and answer for it with a signed link.
 */
class Guardian extends Model
{
    use Notifiable;

    protected $fillable = ['user_id', 'name', 'email', 'phone'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
