<?php

namespace Extensions\Vereinsverwaltung\Models;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Association extends Model
{
    protected $table = 'x_vv_associations';

    protected $fillable = ['name', 'location_id'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function boardMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'x_vv_association_user', 'association_id', 'user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'association_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class, 'association_id');
    }
}
