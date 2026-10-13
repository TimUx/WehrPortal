<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** A place the Wehr meets at, e.g. "Gerätehaus Musterstadt", with the address for the navigation */
class Place extends Model
{
    protected $fillable = ['name', 'address', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return Collection<int, string> id => name, in the configured order */
    public static function options()
    {
        return self::orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
    }

    /** A free text Ort that is the name of a place gets its address and navigation too */
    public static function forLocation(?string $location): ?self
    {
        return filled($location) ? self::whereRaw('lower(name) = ?', [mb_strtolower(trim($location))])->first() : null;
    }

    /** Google Maps route to the address (or to the name, when there is no address) */
    public function navigationUrl(): string
    {
        return 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($this->address ?: $this->name);
    }

    /** "Gerätehaus Musterstadt, Hauptstraße 1, 12345 Musterstadt" */
    public function label(): string
    {
        return $this->name.($this->address ? ', '.$this->address : '');
    }
}
