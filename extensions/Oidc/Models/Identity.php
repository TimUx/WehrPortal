<?php

namespace Extensions\Oidc\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Identity extends Model
{
    protected $table = 'x_oidc_identities';

    protected $fillable = ['user_id', 'issuer', 'subject', 'last_login_at'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
