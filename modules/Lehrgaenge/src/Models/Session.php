<?php

namespace Modules\Lehrgaenge\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One confirmed day of a Lehrgang, with time and place */
class Session extends Model
{
    protected $table = 'lq_record_sessions';

    protected $fillable = ['record_id', 'date', 'starts_at', 'ends_at', 'location'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class, 'record_id');
    }

    /** "Sa, 05.12.2026 · 08:00–15:45 Uhr · Feuerwehrtechnisches Zentrum" */
    public function label(): string
    {
        $time = $this->starts_at ? $this->starts_at.($this->ends_at ? '–'.$this->ends_at : '').' Uhr' : null;

        return collect([$this->date->locale('de')->translatedFormat('D, d.m.Y'), $time, $this->location])->filter()->implode(' · ');
    }
}
