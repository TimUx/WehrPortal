<?php

namespace Extensions\Dateien\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $ext_location_id
 * @property bool $public_shares
 * @property bool $is_location_default
 */
class Space extends Model
{
    protected $table = 'x_files_spaces';

    protected $fillable = ['name', 'ext_location_id', 'quota_bytes', 'public_shares', 'is_location_default'];

    protected function casts(): array
    {
        return [
            'quota_bytes' => 'integer',
            'ext_location_id' => 'integer',
            'public_shares' => 'boolean',
            'is_location_default' => 'boolean',
        ];
    }

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** @return HasMany<Permission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'target_id')->where('target_type', 'space');
    }
}
