<?php

namespace Modules\Einsatzbericht\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Lagemeldung: short situation report with photos, the Einsatztagebuch of the report */
class SituationMessage extends Model
{
    protected $table = 'eb_situation_messages';

    protected $attributes = ['important' => false];

    protected $fillable = ['report_id', 'user_id', 'external_unit_id', 'text', 'important'];

    protected function casts(): array
    {
        return ['important' => 'boolean'];
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sent by an external Wehr through the QR code */
    public function externalUnit(): BelongsTo
    {
        return $this->belongsTo(ExternalUnit::class, 'external_unit_id');
    }

    /** "Hanna Hydrant" or "Extern: FF Nachbarstadt · LF 20" */
    public function authorLabel(): string
    {
        return $this->externalUnit ? 'Extern: '.$this->externalUnit->label() : ($this->user?->name ?? 'unbekannt');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(SituationPhoto::class, 'message_id');
    }
}
