<?php

namespace Modules\Unterweisungen\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** A kind of Unterweisung or Einweisung: Kürzel, interval, for whom it is required */
class Topic extends Model
{
    public const KINDS = ['unterweisung' => 'Unterweisung', 'einweisung' => 'Einweisung'];

    public const COUNTS_AS = ['atemschutz' => 'Atemschutz-Unterweisung (FwDV 7)'];

    /** Usual ones to start with – adjust to Land, Unfallkasse and Wehr */
    public const PRESETS = [
        ['Jährliche Unterweisung (UVV / DGUV Vorschrift 49)', 'UVV', 'unterweisung', 12, true, null],
        ['Einsatzstellenhygiene', 'Hyg', 'unterweisung', 12, true, null],
        ['Atemschutz-Unterweisung (FwDV 7)', 'AS-U', 'unterweisung', 12, false, 'atemschutz'],
        ['Absturzsicherung', 'Abs', 'unterweisung', 12, false, null],
        ['Motorkettensäge', 'MKS', 'einweisung', 12, false, null],
        ['Hydraulisches Rettungsgerät', 'HRG', 'einweisung', null, false, null],
    ];

    protected $table = 'uw_topics';

    protected $fillable = ['name', 'short', 'kind', 'interval_months', 'vehicle_id', 'counts_as', 'description', 'for_all', 'group_ids', 'function_ids', 'is_active'];

    protected function casts(): array
    {
        return ['interval_months' => 'integer', 'for_all' => 'boolean', 'group_ids' => 'array', 'function_ids' => 'array', 'is_active' => 'boolean'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class)->orderByDesc('held_on');
    }

    /** Does $user need it? Everyone, or by Abteilung or Funktion (sub-functions included) */
    public function requiredFor(User $user): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->for_all) {
            return true;
        }
        $groups = $user->relationLoaded('groups') ? $user->groups->pluck('id') : $user->groups()->pluck('groups.id');
        $functions = $user->relationLoaded('functions') ? $user->functions->pluck('id') : $user->functions()->pluck('member_functions.id');

        return $groups->intersect($this->group_ids ?? [])->isNotEmpty()
            || $functions->intersect(MemberFunction::idsWithChildren($this->function_ids ?? []))->isNotEmpty();
    }

    public function audienceLabel(): string
    {
        if ($this->for_all) {
            return 'alle aktiven Kameraden';
        }
        $parts = [...Group::whereIn('id', $this->group_ids ?? [])->pluck('name')->all(), ...MemberFunction::whereIn('id', $this->function_ids ?? [])->pluck('name')->all()];

        return $parts ? implode(', ', $parts) : 'niemand verpflichtend';
    }

    public function label(): string
    {
        return $this->name.($this->short ? ' ('.$this->short.')' : '');
    }
}
