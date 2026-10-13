<?php

namespace Modules\Fuehrerscheine\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Fuehrerscheine\src\Services\LicenseService;

/** The Führerschein of a Kamerad: classes, the Feuerwehr-Fahrberechtigung, the check */
class License extends Model
{
    public const CLASSES = ['AM', 'A1', 'A2', 'A', 'B', 'BE', 'C1', 'C1E', 'C', 'CE', 'D1', 'D1E', 'D', 'DE', 'L', 'T'];

    /** Feuerwehr-Fahrberechtigung (§ 2 Abs. 10a StVG), on the basis of class B */
    public const FIRE = ['FW475' => 'Feuerwehr-Fahrberechtigung bis 4,75 t', 'FW75' => 'Feuerwehr-Fahrberechtigung bis 7,5 t'];

    /** Classes that run out after five years and need a medical check to be extended */
    public const LIMITED = ['C1', 'C1E', 'C', 'CE', 'D1', 'D1E', 'D', 'DE'];

    public const STATUSES = ['draft' => 'Noch nicht eingereicht', 'pending' => 'Wartet auf Prüfung', 'validated' => 'Geprüft', 'rejected' => 'Zurückgewiesen'];

    protected $table = 'fs_licenses';

    protected $fillable = ['user_id', 'document_valid_until', 'classes', 'status', 'submitted_at', 'validated_at', 'validated_by', 'rejected_reason', 'next_check_on', 'reminded'];

    protected function casts(): array
    {
        return ['document_valid_until' => 'date', 'classes' => 'array', 'submitted_at' => 'datetime', 'validated_at' => 'datetime',
            'next_check_on' => 'date', 'reminded' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->select(['id', 'license_id', 'side', 'mime']);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class)->latest('created_at');
    }

    public static function label(string $class): string
    {
        return self::FIRE[$class] ?? 'Klasse '.$class;
    }

    public function documentExpired(): bool
    {
        return $this->document_valid_until !== null && $this->document_valid_until->lt(LicenseService::today());
    }

    public function checkDue(): bool
    {
        return $this->status === 'validated' && $this->next_check_on !== null && $this->next_check_on->lte(LicenseService::today());
    }

    /** @return list<string> the classes that are valid today */
    public function validClasses(): array
    {
        $today = LicenseService::today();

        return collect($this->classes ?? [])
            ->reject(fn ($c) => filled($c['valid_until'] ?? null) && Carbon::parse($c['valid_until'])->lt($today))
            ->pluck('class')->values()->all();
    }

    /** Validated and the card not run out: the classes count for driving */
    public function usable(): bool
    {
        return $this->status === 'validated' && ! $this->documentExpired();
    }

    public function statusLabel(): string
    {
        return $this->checkDue() ? 'Prüfung fällig' : (self::STATUSES[$this->status] ?? $this->status);
    }
}
