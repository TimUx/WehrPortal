<?php

namespace Extensions;

use Illuminate\Support\ServiceProvider;

/**
 * Loads the fork's own extensions without touching upstream files.
 *
 * Every folder extensions/<Name>/ that contains <Name>ServiceProvider.php (class
 * Extensions\<Name>\<Name>ServiceProvider) is registered automatically. This provider itself is
 * registered through composer.json (extra.laravel.providers), so bootstrap/providers.php stays
 * identical to upstream.
 */
class ExtensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (glob(__DIR__.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $class = "Extensions\\{$name}\\{$name}ServiceProvider";

            if (is_file("{$dir}/{$name}ServiceProvider.php") && class_exists($class)) {
                $this->app->register($class);
            }
        }
    }
}
