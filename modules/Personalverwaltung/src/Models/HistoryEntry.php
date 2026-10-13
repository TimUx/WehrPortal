<?php

namespace Modules\Personalverwaltung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of the Diensthistorie: a stage (Dienstgrad, Abteilung, Funktion, Status) from – until, an Ereignis
 * on one day (Verpflichtung, Ernennung, Versetzung …) or a Dienstzeit in another Wehr, which counts as Dienstzeit.
 */
class HistoryEntry extends Model
{
    public const KINDS = ['rank' => 'Dienstgrad', 'group' => 'Abteilung', 'function' => 'Funktion', 'status' => 'Status', 'event' => 'Ereignis', 'service' => 'Dienstzeit in anderer Wehr'];

    /** Headings in the history */
    public const HEADINGS = ['event' => 'Ereignisse', 'rank' => 'Dienstgrade', 'group' => 'Abteilungen', 'function' => 'Funktionen', 'status' => 'Status', 'service' => 'Dienstzeiten in anderen Wehren'];

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

    public function files(): HasMany
    {
        return $this->hasMany(HistoryFile::class, 'entry_id')->select(['id', 'entry_id', 'filename', 'mime', 'size', 'created_at']);
    }

    /** The running stage, kept by the member data; an Ereignis is never running */
    public function isCurrent(): bool
    {
        return $this->until === null && $this->kind !== 'event';
    }

    /** "seit 01.03.2015" / "01.03.2015 – 31.12.2019" */
    public function period(): string
    {
        $since = $this->since?->format('d.m.Y') ?? 'unbekannt';
        if ($this->kind === 'event') {
            return 'am '.$since;
        }

        return $this->until ? $since.' – '.$this->until->format('d.m.Y') : 'seit '.$since;
    }
}
