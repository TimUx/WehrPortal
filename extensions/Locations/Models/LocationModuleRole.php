<?php

namespace Extensions\Locations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historical grants are never replaced or deleted. Revocation closes a grant once,
 * retaining the original grant and its actor/reason alongside the revocation.
 */
class LocationModuleRole extends Model
{
    protected $table = 'x_location_module_roles';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }
}
