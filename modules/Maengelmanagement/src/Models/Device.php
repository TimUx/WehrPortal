<?php

namespace Modules\Maengelmanagement\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something a defect can be reported for. The code on its QR label opens the report form. */
class Device extends Model
{
    protected $table = 'mm_devices';

    protected $fillable = ['code', 'name', 'location', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Device $device) {
            $device->code = filled($device->code) ? trim($device->code) : static::nextCode();
        });
    }

    /** MM-0001, MM-0002 … – one above the highest number in use */
    public static function nextCode(): string
    {
        $highest = static::where('code', 'like', 'MM-%')->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 3))
            ->max() ?? 0;

        return sprintf('MM-%04d', $highest + 1);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function defects(): HasMany
    {
        return $this->hasMany(Defect::class);
    }

    public function label(): string
    {
        return $this->location ? "{$this->name} ({$this->location})" : $this->name;
    }
}
