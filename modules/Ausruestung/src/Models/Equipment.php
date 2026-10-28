<?php

namespace Modules\Ausruestung\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\EquipmentStatus;
use Modules\Ausruestung\src\Services\RecallService;

class Equipment extends Model
{
    protected $table = 'ar_equipment';

    protected $attributes = ['status' => 'available'];

    protected $fillable = [
        'code', 'name', 'category_id', 'manufacturer', 'model', 'serial_number', 'batch_number', 'barcode',
        'manufactured_at', 'commissioned_at', 'purchased_at', 'purchase_price',
        'lifetime_years', 'inspection_interval_months', 'next_inspection_at',
        'location_id', 'compartment_id', 'status', 'notes', 'rfid', 'last_seen_at',
        'ext_location_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EquipmentStatus::class,
            'manufactured_at' => 'date',
            'commissioned_at' => 'date',
            'purchased_at' => 'date',
            'next_inspection_at' => 'date',
            'purchase_price' => 'decimal:2',
            'lifetime_years' => 'integer',
            'inspection_interval_months' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /** RFID/NFC tag UIDs are kept upper case without separators ("04:a2:3b" → "04A23B") */
    public static function normalizeTag(?string $value): ?string
    {
        $tag = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $value));

        return $tag === '' ? null : $tag;
    }

    /** The item a scanned code stands for: Inventarnummer, Hersteller-Barcode or RFID/NFC tag */
    public static function findByScan(string $code): ?self
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return static::where('code', $code)->first()
            ?? static::where('barcode', $code)->first()
            ?? (($tag = static::normalizeTag($code)) ? static::where('rfid', $tag)->first() : null);
    }

    protected static function booted(): void
    {
        static::saving(function (Equipment $equipment) {
            if ($equipment->isDirty('rfid')) {
                $equipment->rfid = static::normalizeTag($equipment->rfid);
            }
        });

        static::creating(function (Equipment $equipment) {
            $equipment->code = filled($equipment->code) ? trim($equipment->code) : static::nextCode();
        });

        // New items and changed identifying data are checked against open manufacturer recalls
        static::saved(function (Equipment $equipment) {
            if ($equipment->wasRecentlyCreated || $equipment->wasChanged(['manufacturer', 'model', 'serial_number', 'batch_number', 'manufactured_at'])) {
                app(RecallService::class)->check($equipment);
            }
        });

        // A compartment always belongs to the location of the item
        static::saving(function (Equipment $equipment) {
            if ($equipment->compartment_id) {
                $equipment->location_id = Compartment::whereKey($equipment->compartment_id)->value('location_id');
            }

            $locationId = $equipment->getAttribute('location_id');
            $standortId = $equipment->getAttribute('ext_location_id');
            if (config('locations.enabled')
                && ($equipment->isDirty('location_id') || $equipment->isDirty('ext_location_id'))
                && $locationId
                && $standortId
                && (int) Location::withoutGlobalScopes()->whereKey($locationId)->value('ext_location_id') !== (int) $standortId) {
                throw ValidationException::withMessages([
                    'location_id' => 'Der Lagerort muss zum organisatorischen Standort des Geräts gehören.',
                ]);
            }
        });
    }

    /** AR-0001, AR-0002 … – one above the highest number in use */
    public static function nextCode(): string
    {
        $highest = static::where('code', 'like', 'AR-%')->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 3))
            ->max() ?? 0;

        return sprintf('AR-%04d', $highest + 1);
    }

    /** The Standort (Locations extension) */
    public function standort(): BelongsTo
    {
        return $this->belongsTo(\Extensions\Locations\Models\Location::class, 'ext_location_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class, 'compartment_id');
    }

    /** @return HasMany<Inspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'equipment_id')->latest('inspected_at')->latest('id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'equipment_id');
    }

    /** @return HasMany<Damage, $this> */
    public function damages(): HasMany
    {
        return $this->hasMany(Damage::class, 'equipment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EquipmentEvent::class, 'equipment_id')->latest('occurred_at')->latest('id');
    }

    public function recalls(): BelongsToMany
    {
        return $this->belongsToMany(Recall::class, 'ar_recall_equipment', 'equipment_id', 'recall_id')
            ->withPivot(['previous_status', 'resolved_at', 'resolution'])
            ->withTimestamps();
    }

    public function scopeInService(Builder $query): Builder
    {
        return $query->where('status', '!=', EquipmentStatus::Retired->value);
    }

    /** In service with an inspection due within the given number of days (or overdue) */
    public function scopeInspectionDue(Builder $query, int $days = 0): Builder
    {
        return $query->inService()->whereNotNull('next_inspection_at')->whereDate('next_inspection_at', '<=', today()->addDays($days));
    }

    public function lifetimeYears(): ?int
    {
        return $this->lifetime_years ?? $this->category?->lifetime_years;
    }

    public function inspectionIntervalMonths(): ?int
    {
        return $this->inspection_interval_months ?? $this->category?->inspection_interval_months;
    }

    /** End of the permitted service life, counted from manufacture (or commissioning) */
    public function lifetimeEndsAt(): ?Carbon
    {
        $start = $this->manufactured_at ?? $this->commissioned_at;
        $years = $this->lifetimeYears();

        return $start && $years ? $start->copy()->addYears($years) : null;
    }

    public function lifetimeExpired(): bool
    {
        return (bool) $this->lifetimeEndsAt()?->isPast();
    }

    public function inspectionOverdue(): bool
    {
        return $this->next_inspection_at !== null && $this->next_inspection_at->lt(today());
    }

    /** Can go out to an Einsatz or a loan right now */
    public function isUsable(): bool
    {
        return $this->status === EquipmentStatus::Available && ! $this->inspectionOverdue() && ! $this->lifetimeExpired();
    }

    /** What keeps it from being used, for badges and hints */
    public function warnings(): array
    {
        return array_values(array_filter([
            $this->inspectionOverdue() ? 'Prüfung überfällig' : null,
            $this->lifetimeExpired() ? 'Lebensdauer abgelaufen' : null,
        ]));
    }

    public function placeLabel(): string
    {
        return $this->compartment?->label() ?? $this->location?->name ?? '–';
    }

    public function activeLoan(): ?Loan
    {
        return $this->loans()->where('status', 'active')->latest('handed_out_at')->first();
    }
}
