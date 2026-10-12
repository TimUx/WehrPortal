<?php

namespace Modules\Objektplaene\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One document of an object (e.g. the Feuerwehrplan); its versions keep the history */
class Document extends Model
{
    public const KINDS = [
        'feuerwehrplan' => 'Feuerwehrplan (DIN 14095)', 'objektplan' => 'Objektplan / Lageplan', 'laufkarte' => 'Feuerwehr-Laufkarte',
        'brandschutzordnung' => 'Brandschutzordnung', 'gefahrstoffe' => 'Gefahrstoffliste', 'einsatzplan' => 'Einsatzplan / Alarmplan',
        'sonstiges' => 'Sonstige Unterlage',
    ];

    protected $table = 'op_documents';

    protected $fillable = ['object_id', 'kind', 'title', 'sort_order'];

    public function object(): BelongsTo
    {
        return $this->belongsTo(PlanObject::class, 'object_id');
    }

    /** Without the file contents: the list doesn't need megabytes of base64 */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class)->select(Version::META)->orderByDesc('version');
    }

    public function current(): HasOne
    {
        return $this->hasOne(Version::class)->select(Version::META)->ofMany('version', 'max');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
