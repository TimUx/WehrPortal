<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentDocument extends Model
{
    public const KINDS = [
        'manual' => 'Betriebsanleitung',
        'inspection' => 'Prüfbeleg',
        'photo' => 'Foto',
        'other' => 'Sonstiges',
    ];

    protected $table = 'as_equipment_documents';

    protected $fillable = ['equipment_id', 'kind', 'title', 'filename', 'size', 'data', 'uploaded_by'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Equipment, $this> */
    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return BelongsTo<User, $this> */
    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
