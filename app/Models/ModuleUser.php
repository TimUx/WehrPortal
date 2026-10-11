<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleUser extends Model
{
    protected $table = 'module_user';

    public $timestamps = false;

    protected $fillable = ['user_id', 'module', 'role'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
