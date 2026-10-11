<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Module extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'is_active', 'auto_assign'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_assign' => 'boolean',
        ];
    }

    /** Grants of this module (module_user rows) */
    public function users(): HasMany
    {
        return $this->hasMany(ModuleUser::class, 'module', 'key');
    }

    protected static function booted(): void
    {
        $clear = function (Module $module) {
            Cache::forget('modules_active_keys');
            Cache::forget("module_auto_assign_{$module->key}");
        };
        static::saved($clear);
        static::deleted($clear);
    }
}
