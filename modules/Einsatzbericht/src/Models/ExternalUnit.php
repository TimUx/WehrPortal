<?php

namespace Modules\Einsatzbericht\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An external Wehr at a large Einsatz, registered by scanning the QR code (one per phone) */
class ExternalUnit extends Model
{
    public const STATUSES = [
        'anfahrt' => 'Auf Anfahrt',
        'einsatzstelle' => 'An Einsatzstelle',
        'bereitstellung' => 'Im Bereitstellungsraum',
        'einsatzbereit' => 'Einsatzbereit',
        'abgerueckt' => 'Abgerückt',
    ];

    protected $table = 'eb_external_units';

    protected $fillable = ['report_id', 'device_hash', 'name', 'vehicle', 'strength'];

    protected $hidden = ['device_hash'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ExternalStatus::class, 'unit_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SituationMessage::class, 'external_unit_id');
    }

    /** "FF Bargteheide · LF 20 · 1/8/9" */
    public function label(): string
    {
        return collect([$this->name, $this->vehicle, $this->strength])->filter()->implode(' · ');
    }
}
