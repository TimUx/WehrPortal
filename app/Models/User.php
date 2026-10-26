<?php

namespace App\Models;

use App\Enums\MemberGender;
use App\Enums\MemberStatus;
use App\Filament\ModuleAreas;
use App\Services\AuthMethodRegistry;
use App\Services\ModuleRegistry;
use Database\Factories\UserFactory;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationModuleRoles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Kleiderkammer\src\Services\PersonalAccess;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The start page keeps the own module order unless the Kamerad switches to sorting by use */
    protected $attributes = ['modules_locked' => true];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'birth_date',
        'show_birthday',
        'joined_at',
        'rank_since',
        'street',
        'postal_code',
        'city',
        'calendar_view',
        'avatar_updated_at',
        'is_admin',
        'is_active',
        'status',
        'rank',
        'gender',
        'notes',
        'last_login_at',
        'remember_token',
        'ext_location_id',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'modules_locked' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'avatar_updated_at' => 'datetime',
            'birth_date' => 'date',
            'show_birthday' => 'boolean',
            'joined_at' => 'date',
            'rank_since' => 'date',
            'status' => MemberStatus::class,
            'gender' => MemberGender::class,
        ];
    }

    // status is the source of truth; is_active (login allowed) follows it. Writes to is_active
    // alone (factories, app:ensure-admins) are mapped back onto the status.
    protected static function booted(): void
    {
        static::deleting(fn (User $user) => app(LocationModuleRoles::class)->assertMemberDeletable($user));

        static::saving(function (User $user) {
            if ($user->isDirty('status')) {
                $user->is_active = $user->status === MemberStatus::Active;
            } elseif ($user->isDirty('is_active') || ! $user->exists) {
                $user->status = match (true) {
                    (bool) ($user->is_active ?? true) => MemberStatus::Active,
                    $user->status === MemberStatus::Left => MemberStatus::Left,
                    default => MemberStatus::Inactive,
                };
                $user->is_active ??= true;
            }
        });
    }

    /** FullCalendar view names a user can pick as their default */
    public const CALENDAR_VIEWS = [
        'timeGridWeek' => 'Woche',
        'dayGridMonth' => 'Monat',
        'listWeek' => 'Liste',
    ];

    public function calendarView(): string
    {
        return array_key_exists($this->calendar_view ?? '', self::CALENDAR_VIEWS)
            ? $this->calendar_view
            : 'timeGridWeek';
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** Deciders (fahrzeugbuchung entscheider role) and admins resolve booking conflicts */
    public function canDecide(): bool
    {
        return $this->is_active && ($this->is_admin || $this->hasModuleRole('fahrzeugbuchung', 'entscheider'));
    }

    /** Admins and Kleiderwarte manage the clothing store; other members only see their own equipment */
    public function canManageKleiderkammer(): bool
    {
        if (app(PersonalAccess::class)->enabled()) {
            // Navigation only. Record authorization belongs to the personal-data adapter.
            return app(PersonalAccess::class)->canEnter($this);
        }

        return $this->is_active
            && $this->canUseModule('kleiderkammer')
            && ($this->is_admin || $this->hasModuleRole('kleiderkammer', 'kleiderwart'));
    }

    /** Admins and Verantwortliche handle defects; every other member of the module only reports and follows them */
    public function canManageMaengel(): bool
    {
        return $this->is_active
            && $this->canUseModule('maengelmanagement')
            && ($this->is_admin || $this->hasModuleRole('maengelmanagement', 'verantwortlicher'));
    }

    /** Admins and Gerätewarte manage the equipment; other members see it, report damages and reserve */
    public function canManageAusruestung(): bool
    {
        return $this->is_active
            && $this->canUseModule('ausruestung')
            && ($this->is_admin || $this->hasModuleRole('ausruestung', 'geraetewart'));
    }

    /** Admins and Redakteure (e.g. the Wehrführung) write the notices of the Mitteilungen module */
    public function canPostAnnouncements(): bool
    {
        if (app(LocationModuleRoles::class)->enabled('mitteilungen')) {
            return $this->hasAnyModuleRole('mitteilungen', 'redakteur');
        }

        return $this->is_active
            && $this->canUseModule('mitteilungen')
            && ($this->is_admin || $this->hasModuleRole('mitteilungen', 'redakteur'));
    }

    /** Admins and Terminverwalter create events and add external calendars; everyone else sees and answers */
    public function canManageKalender(): bool
    {
        return $this->is_active
            && $this->canUseModule('kalender')
            && ($this->is_admin || $this->hasModuleRole('kalender', 'terminverwalter'));
    }

    /** Admins and Ausbildungsleiter keep the Lehrgänge of everyone; other members see their own */
    public function canManageLehrgaenge(): bool
    {
        return $this->is_active
            && $this->canUseModule('lehrgaenge')
            && ($this->is_admin || $this->hasModuleRole('lehrgaenge', 'ausbildungsleiter'));
    }

    /** The Wehrführung also sees everyone's Lehrgänge and the Ausbildungsstand, without changing anything */
    public function canViewLehrgaenge(): bool
    {
        return $this->canManageLehrgaenge()
            || ($this->is_active && $this->canUseModule('lehrgaenge') && $this->hasModuleRole('lehrgaenge', 'wehrführung'));
    }

    /** Admins and Planer create and distribute shift plans; other members see the plans they are on */
    public function canManageSchichtplan(): bool
    {
        return $this->is_active
            && $this->canUseModule('schichtplan')
            && ($this->is_admin || $this->hasModuleRole('schichtplan', 'planer'));
    }

    /** An admin who can always log in locally (magic link), even if an external login method is exclusive or down */
    public function isLocalAdmin(): bool
    {
        return app(AuthMethodRegistry::class)->isLocalAdmin($this);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // One panel: admins see all of it, module managers (Kleiderwarte …) only their module's area
        return $this->is_active && $this->hasLogin() && ($this->is_admin || ModuleAreas::for($this) !== []);
    }

    /**
     * Members without an e-mail address (e.g. Jugendfeuerwehr children, Musikzug) have no login: they are
     * listed and handled in the modules like everyone else, but get no login link, Einladung or mail.
     */
    public function hasLogin(): bool
    {
        return filled($this->email);
    }

    /** Erziehungsberechtigte, e.g. of a member of the Jugendfeuerwehr */
    public function guardians(): HasMany
    {
        return $this->hasMany(Guardian::class)->orderBy('name');
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'x_location_user', 'user_id', 'location_id')
            ->withPivot('role')
            ->orderBy('name');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'ext_location_id');
    }

    /** Holds the "Vorstand" function or one of its sub-functions */
    public function isVorstand(): bool
    {
        $ids = MemberFunction::vorstandIds();

        return $ids !== [] && $this->functions()->whereIn('member_functions.id', $ids)->exists();
    }

    /** Functions (or sub-functions) this member holds, e.g. Jugendwart */
    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(MemberFunction::class, 'member_function_user');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function avatar(): HasOne
    {
        return $this->hasOne(UserAvatar::class);
    }

    /** Versioned URL so browsers can cache the image until it changes; null without avatar */
    public function avatarUrl(): ?string
    {
        return $this->avatar_updated_at
            ? route('avatars.show', ['user' => $this->id, 'v' => $this->avatar_updated_at->timestamp])
            : null;
    }

    /** "Hanna Hydrant" -> "HH" for the placeholder */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    public function feedbackThreads(): HasMany
    {
        return $this->hasMany(FeedbackThread::class);
    }

    public function loginTokens(): HasMany
    {
        return $this->hasMany(LoginToken::class);
    }

    public function moduleAccess(): HasMany
    {
        return $this->hasMany(ModuleUser::class);
    }

    /**
     * Whether this user may use a module.
     * Admins may use any active module without an explicit grant.
     * Auto-assign modules are accessible to all active users.
     * Otherwise, the user needs an explicit entry in module_user.
     */
    public function canUseModule(string $key): bool
    {
        $scopedRoles = app(LocationModuleRoles::class);
        if ($scopedRoles->enabled($key)) {
            if (! $scopedRoles->eligible($this, $key)) {
                return false;
            }
            if ($scopedRoles->isGlobalAdmin($this) || $scopedRoles->canEnter($this, $key)) {
                return true;
            }
        }

        if (! app(ModuleRegistry::class)->isActive($key)) {
            return false;
        }

        if ($this->is_admin) {
            return true;
        }

        // Auto-assign: all active users have access without an explicit record
        $autoAssign = Cache::remember("module_auto_assign_{$key}", 60, fn () => Module::where('key', $key)->value('auto_assign')
        );

        // For a module meant only for some (e.g. the Vorstand), "automatisch freischalten" covers only them
        if ($autoAssign && app(ModuleRegistry::class)->isMeantFor($key, $this)) {
            return true;
        }

        return $this->moduleAccess()->where('module', $key)->exists();
    }

    /** Whether this user holds a specific role within a module. */
    public function hasModuleRole(string $key, string $role, ?int $locationId = null): bool
    {
        $scopedRoles = app(LocationModuleRoles::class);
        if ($scopedRoles->enabled($key)) {
            return $scopedRoles->hasRole($this, $key, $role, $locationId);
        }

        return $this->moduleAccess()
            ->where('module', $key)
            ->where('role', $role)
            ->exists();
    }

    /** Navigation only; object authorization must use hasModuleRole with an explicit target. */
    public function hasAnyModuleRole(string $key, string $role): bool
    {
        $scopedRoles = app(LocationModuleRoles::class);

        return $scopedRoles->enabled($key) ? $scopedRoles->hasAnyRole($this, $key, $role) : $this->hasModuleRole($key, $role);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
