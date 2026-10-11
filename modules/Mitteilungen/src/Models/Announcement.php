<?php

namespace Modules\Mitteilungen\src\Models;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A notice on the start page; important ones are highlighted and cannot be dismissed */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'important', 'visible_from', 'visible_until', 'created_by'];

    protected function casts(): array
    {
        return ['important' => 'boolean', 'visible_from' => 'datetime', 'visible_until' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Audience; empty means everyone */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    public function dismissedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_dismissals');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('visible_until')->orWhere('visible_until', '>=', now()));
    }

    /** Meant for this Kamerad: no audience set, or member of one of the Abteilungen */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->doesntHave('groups')
            ->orWhereHas('groups', fn ($g) => $g->whereIn('groups.id', $user->groups()->pluck('groups.id'))));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->current()->for($user)
            ->where(fn ($q) => $q->where('important', true)
                ->orWhereDoesntHave('dismissedBy', fn ($d) => $d->where('users.id', $user->id)));
    }
}
