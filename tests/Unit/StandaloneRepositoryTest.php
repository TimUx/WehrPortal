<?php

test('package metadata identifies WehrPortal and keeps lockfiles in sync', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents(base_path('package-lock.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['name'])->toBe('timux/wehrportal')
        ->and($composer['license'])->toBe('AGPL-3.0-only')
        ->and($package['name'])->toBe('wehrportal')
        ->and($lock['name'])->toBe($package['name'])
        ->and($lock['packages']['']['name'])->toBe($package['name']);
});

test('workflows have no original repository binding or automatic deployment', function () {
    expect(base_path('.github/workflows/upstream-sync.yml'))->not->toBeFile();

    foreach (glob(base_path('.github/workflows/*.yml')) as $path) {
        expect(file_get_contents($path))
            ->not->toContain('roberteinsle/openwehr', 'TimUx/openwehr-extended', 'COOLIFY_', 'refs/heads/extended');
    }

    foreach (['deploy', 'release'] as $workflow) {
        expect(file_get_contents(base_path(".github/workflows/{$workflow}.yml")))
            ->toContain("vars.PUBLISH_IMAGES == 'true'", 'github.repository');
    }

    expect(file_get_contents(base_path('.github/workflows/security.yml')))
        ->toContain("vars.PUBLISH_IMAGES == 'true'", 'ghcr.io/timux/wehrportal:latest');
});

test('local storage identifiers remain compatible with existing installations', function () {
    expect(file_get_contents(base_path('docker-compose.yml')))
        ->toContain('name: openwehr', 'SESSION_COOKIE=openwehr-session', 'CACHE_PREFIX=openwehr-cache-', 'REDIS_PREFIX=openwehr-database-');
    expect(file_get_contents(base_path('extensions/Offline/resources/js/offline.js')))
        ->toContain("'openwehr-offline-v1'");
    expect(file_get_contents(base_path('modules/Einsatzbericht/src/Livewire/ExternalReport.php')))
        ->toContain("'openwehr_extern'");
    expect(public_path('images/openwehr-logo.svg'))->toBeFile();
    expect(base_path('docs/CNAME'))->not->toBeFile();
});
