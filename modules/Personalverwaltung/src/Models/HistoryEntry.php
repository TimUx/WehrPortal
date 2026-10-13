<?php

namespace Modules\Personalverwaltung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stage of a career: a Dienstgrad, an Abteilung, a Funktion or a status, from – until */
class HistoryEntry extends Model
{
    public const KINDS = ['rank' => 'Dienstgrad', 'group' => 'Abteilung', 'function' => 'Funktion', 'status' => 'Status'];

    protected $table = 'pm_history';

    protected $fillable = ['user_id', 'kind', 'ref_id', 'label', 'since', 'until', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['since' => 'date', 'until' => 'date', 'ref_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCurrent(): bool
    {
        return $this->until === null;
    }

    /** "seit 01.03.2015" / "01.03.2015 – 31.12.2019" */
    public function period(): string
    {
        $since = $this->since?->format('d.m.Y') ?? 'unbekannt';

        return $this->until ? $since.' – '.$this->until->format('d.m.Y') : 'seit '.$since;
    }
}
