<?php

namespace Modules\Schluessel\src\Models;

use App\Models\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Objektplaene\src\Models\PlanObject;

/** A key, transponder or card: what it opens, where it is kept, who has it */
class Key extends Model
{
    public const KINDS = ['schluessel' => 'Schlüssel', 'transponder' => 'Transponder / Chip', 'karte' => 'Schließkarte', 'handsender' => 'Handsender / Toröffner'];

    public const STATUSES = ['available' => 'Vorhanden', 'issued' => 'Ausgegeben', 'lost' => 'Verloren', 'retired' => 'Ausgesondert'];

    protected $table = 'sk_keys';

    protected $fillable = ['code', 'name', 'kind', 'locking', 'place_id', 'vehicle_id', 'object_id', 'storage', 'status', 'notes'];

    protected $attributes = ['kind' => 'schluessel', 'status' => 'available'];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(PlanObject::class, 'object_id');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(Handover::class)->latest('issued_at')->latest('id');
    }

    public function current(): HasOne
    {
        return $this->hasOne(Handover::class)->whereNull('returned_at')->latestOfMany('issued_at');
    }

    /** "Ausgegeben" while someone has it; the stored status otherwise */
    public function statusLabel(): string
    {
        return $this->status === 'available' && $this->current ? self::STATUSES['issued'] : (self::STATUSES[$this->status] ?? $this->status);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** "Gerätehaus Musterstadt · HLF 20 · Grundschule" – what it opens */
    public function opensLabel(): string
    {
        return collect([$this->place?->name, $this->vehicle?->name, $this->object?->name, $this->locking])->filter()->implode(' · ');
    }
}
