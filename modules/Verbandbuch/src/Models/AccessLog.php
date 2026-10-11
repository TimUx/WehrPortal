<?php

namespace Modules\Verbandbuch\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who opened, printed or changed an entry; goes with the entry when it is deleted */
class AccessLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'angelegt', 'viewed' => 'angesehen', 'printed' => 'gedruckt', 'amended' => 'Nachtrag', 'reported' => 'Unfallanzeige vermerkt',
        'voided' => 'storniert', 'hold' => 'Aufbewahrung verlängert', 'released' => 'Aufbewahrung normal',
    ];

    protected $table = 'vb_access_logs';

    protected $fillable = ['entry_id', 'user_id', 'action'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
