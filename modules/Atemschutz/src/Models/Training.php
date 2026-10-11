<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A yearly duty of an Atemschutzgeräteträger (FwDV 7): Belastungsübung, Unterweisung, Einsatzübung */
class Training extends Model
{
    public const KINDS = ['belastungsuebung' => 'Belastungsübung', 'unterweisung' => 'Unterweisung', 'einsatzuebung' => 'Einsatz/Übung unter Atemschutz'];

    protected $table = 'as_trainings';

    protected $fillable = ['user_id', 'kind', 'done_on', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['done_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
