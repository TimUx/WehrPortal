<?php

namespace Modules\Fahrzeugbuchung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** A message on a booking. Edits keep the old text as a revision, deletes are soft. */
class BookingComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'booking_id',
        'user_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Earlier versions, oldest first */
    public function revisions(): HasMany
    {
        return $this->hasMany(BookingCommentRevision::class)->orderBy('created_at')->orderBy('id');
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function edit(string $body): void
    {
        if ($body === $this->body) {
            return;
        }

        DB::transaction(function () use ($body) {
            $this->revisions()->create(['body' => $this->body, 'created_at' => now()]);
            $this->update(['body' => $body]);
            $this->forceFill(['edited_at' => now()])->save();
        });
    }
}
