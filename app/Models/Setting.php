<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        // The row's value is cached in an array, so "not set" is remembered too: Cache::remember()
        // never stores null, and most settings are unset – each lookup was a query on every page
        $cached = Cache::remember(self::cacheKey($key), 300, fn () => ['value' => static::where('key', $key)->value('value')]);

        return $cached['value'] ?? $default;
    }

    /** Not "setting_<key>": entries under that name held the bare value until V.0.9.5.5 */
    private static function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
        Cache::forget(self::cacheKey($key));
    }
}
