<?php

namespace Modules\Unterweisungen\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Kamerad at an Unterweisung, with the signature on the screen */
class Attendance extends Model
{
    protected $table = 'uw_attendances';

    protected $fillable = ['session_id', 'user_id', 'signature', 'signed_at', 'reminded_on'];

    protected $hidden = ['signature'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'reminded_on' => 'date'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
