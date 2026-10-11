<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserModulePreference extends Model
{
    protected $fillable = ['user_id', 'module_key', 'sort_order', 'hidden'];

    protected function casts(): array
    {
        return ['hidden' => 'boolean', 'sort_order' => 'integer'];
    }
}
