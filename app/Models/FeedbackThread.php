<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A conversation between a member and the admins */
class FeedbackThread extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'subject',
        'module_key',
        'status',
        'unread_for_user',
        'unread_for_admin',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'unread_for_user' => 'boolean',
            'unread_for_admin' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FeedbackMessage::class)->oldest();
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
