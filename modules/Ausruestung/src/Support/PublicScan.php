<?php

namespace Modules\Ausruestung\src\Support;

use App\Models\Setting;
use App\Services\ModuleRegistry;

/**
 * "Geräteinfo ohne Login": scanning the label of a device without being logged in shows what
 * matters at the device – name, status, next inspection, where it belongs – and nothing more.
 * Off until a Gerätewart switches it on.
 */
final class PublicScan
{
    private const KEY = 'ausruestung.public_scan';

    public static function enabled(): bool
    {
        return app(ModuleRegistry::class)->isActive('ausruestung') && Setting::get(self::KEY) === '1';
    }

    public static function set(bool $on): void
    {
        Setting::set(self::KEY, $on ? '1' : '0');
    }
}
