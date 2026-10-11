<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackMessage extends Model
{
    protected $fillable = [
        'feedback_thread_id',
        'user_id',
        'from_admin',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'from_admin' => 'boolean',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(FeedbackThread::class, 'feedback_thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
