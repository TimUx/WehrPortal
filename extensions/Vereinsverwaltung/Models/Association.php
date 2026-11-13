<?php

namespace Extensions\Vereinsverwaltung\Models;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Association extends Model
{
    protected $table = 'x_vv_associations';

    protected $fillable = ['name', 'location_id'];

    /** Ämter of the Vorstand; who has none is a plain Vorstandsmitglied */
    public const OFFICES = [
        'vorsitz' => 'Vorsitzender',
        'kasse' => 'Kassierer',
        'schrift' => 'Schriftführer',
        'beisitz' => 'Beisitzer',
    ];

    /** "Stv. Vorsitzender", "Kassierer", or "Vorstandsmitglied" without an Amt */
    public static function officeLabel(?string $office, bool $deputy = false): string
    {
        $name = self::OFFICES[$office] ?? 'Vorstandsmitglied';

        return $deputy && isset(self::OFFICES[$office]) ? 'Stv. '.$name : $name;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function boardMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'x_vv_association_user', 'association_id', 'user_id')
            ->withPivot(['office', 'is_deputy']);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'association_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class, 'association_id');
    }
}
