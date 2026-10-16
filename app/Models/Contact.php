<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Ansprechpartner of the Wehr (Wehrführer, Jugendwart …) with phone and mail, for everyone */
class Contact extends Model
{
    protected $fillable = ['role', 'name', 'phone', 'email', 'note', 'group_id', 'sort_order'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** "tel:+491701234567" from what was typed */
    public function telUrl(): ?string
    {
        $phone = trim((string) $this->phone);
        if ($phone === '' || ! preg_match('/^\+?[0-9\s().\/-]+$/u', $phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 3) {
            return null;
        }

        return 'tel:'.(str_starts_with($phone, '+') ? '+' : '').$digits;
    }
}
