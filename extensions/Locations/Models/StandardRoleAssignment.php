<?php

namespace Extensions\Locations\Models;

use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $profile
 * @property int|null $location_id
 * @property int|null $group_id
 * @property int $granted_by
 * @property CarbonImmutable $granted_at
 * @property string $reason
 * @property CarbonImmutable|null $expires_at
 * @property int|null $revoked_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoke_reason
 * @property string|null $revoked_source
 * @property-read User $member
 * @property-read Location|null $location
 * @property-read Group|null $group
 * @property-read User $grantedBy
 */
class StandardRoleAssignment extends Model
{
    protected $table = 'x_standard_role_assignments';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by')->withoutGlobalScopes();
    }
}
