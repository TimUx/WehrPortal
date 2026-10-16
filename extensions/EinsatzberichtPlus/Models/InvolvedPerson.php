<?php

namespace Extensions\EinsatzberichtPlus\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Einsatzbericht\src\Models\Report;

/** A third party at the Einsatz: affected, damaged party, causer, witness, owner … (personal data, encrypted) */
class InvolvedPerson extends Model
{
    public const ROLES = [
        'betroffen' => 'Betroffene Person',
        'geschaedigt' => 'Geschädigte Person',
        'verursacher' => 'Verursacher',
        'halter' => 'Fahrzeughalter / Eigentümer',
        'zeuge' => 'Zeuge / Melder',
        'sonstige' => 'Sonstige',
    ];

    protected $table = 'x_ebp_persons';

    protected $fillable = ['report_id', 'role', 'name', 'contact', 'note'];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'contact' => 'encrypted', 'note' => 'encrypted'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
