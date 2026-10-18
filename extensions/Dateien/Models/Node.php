<?php

namespace Extensions\Dateien\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Node extends Model
{
    use SoftDeletes;

    protected $table = 'x_files_nodes';

    protected $fillable = ['space_id', 'parent_id', 'name', 'kind', 'inherit_permissions'];

    protected function casts(): array
    {
        return ['inherit_permissions' => 'boolean'];
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /** @return HasMany<Node, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Version, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class)->orderByDesc('number');
    }

    /** @return HasMany<Permission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'target_id')->where('target_type', 'node');
    }
}
