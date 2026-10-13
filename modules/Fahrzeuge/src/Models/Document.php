<?php

namespace Modules\Fahrzeuge\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** A document of the Fahrzeugakte: Fahrzeugschein, Gutachten, Betriebsanleitung … (base64 in the database) */
class Document extends Model
{
    public const KINDS = [
        'registration' => 'Fahrzeugschein / Zulassung', 'report' => 'Gutachten / Prüfbericht', 'manual' => 'Betriebsanleitung',
        'purchase' => 'Kaufvertrag / Rechnung', 'insurance' => 'Versicherung', 'other' => 'Sonstiges',
    ];

    protected $table = 'fv_documents';

    protected $fillable = ['vehicle_id', 'kind', 'title', 'valid_until', 'internal', 'name', 'mime', 'size', 'data', 'uploaded_by'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'internal' => 'boolean', 'size' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(now('Europe/Berlin')->startOfDay());
    }
}
