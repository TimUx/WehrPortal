<?php

use Symfony\Component\Yaml\Yaml;

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

    expect(file_get_contents(base_path('.github/workflows/release.yml')))
        ->toContain('github.repository')
        ->not->toContain('PUBLISH_IMAGES');
    expect(file_get_contents(base_path('.github/workflows/security.yml')))
        ->toContain('ghcr.io/timux/wehrportal:latest')
        ->not->toContain('PUBLISH_IMAGES');
});

test('runtime storage and integrations consistently identify WehrPortal', function () {
    expect(file_get_contents(base_path('docker-compose.yml')))
        ->toContain('name: wehrportal', 'DB_DATABASE=wehrportal', 'DB_USERNAME=wehrportal', 'SESSION_COOKIE=wehrportal-session', 'CACHE_PREFIX=wehrportal-cache-', 'REDIS_PREFIX=wehrportal-database-');
    expect(file_get_contents(base_path('extensions/Offline/resources/js/offline.js')))
        ->toContain("'wehrportal-offline-v1'");
    expect(file_get_contents(base_path('extensions/Offline/resources/js/sw.js')))
        ->toContain("'wehrportal-shell-v1'");
    expect(file_get_contents(base_path('modules/Einsatzbericht/src/Livewire/ExternalReport.php')))
        ->toContain("'wehrportal_extern'");
    expect(file_get_contents(base_path('app/Listeners/LogSentMail.php')))
        ->toContain('X-WehrPortal-Resend-Of');
    expect(file_get_contents(base_path('phpunit.xml')))
        ->toContain('value="wehrportal_test"', 'value="wehrportal"');
    expect(file_get_contents(base_path('.devcontainer/init-test-db.sql')))
        ->toContain('CREATE DATABASE wehrportal_test OWNER wehrportal');
    expect(base_path('docs/CNAME'))->not->toBeFile();
});

test('CI checks run once and release images cannot bypass them', function () {
    $ci = file_get_contents(base_path('.github/workflows/deploy.yml'));
    $release = file_get_contents(base_path('.github/workflows/release.yml'));
    expect(substr_count($ci, './vendor/bin/pest'))->toBe(1)
        ->and($ci)->toContain('workflow_call:', 'npm run build', './vendor/bin/pest --parallel');
    expect($release)->toContain('needs: checks', 'uses: ./.github/workflows/deploy.yml', 'Scan Image', "exit-code: '1'");
    expect(strpos($release, 'Scan Image'))->toBeLessThan(strpos($release, 'Push Image'));
});

test('only releases publish images and stable releases update latest', function () {
    $ci = Yaml::parseFile(base_path('.github/workflows/deploy.yml'));
    $release = Yaml::parseFile(base_path('.github/workflows/release.yml'));

    expect(array_keys($ci['jobs']))->toBe(['test', 'security'])
        ->and($release['on']['push']['tags'])->toContain('v[0-9]+.[0-9]+*')
        ->and($release['on']['release']['types'])->toBe(['published'])
        ->and($release['jobs']['checks'])->not->toHaveKey('if')
        ->and($release['jobs']['release'])->not->toHaveKey('if')
        ->and($release['jobs']['release']['permissions']['packages'])->toBe('write');

    $steps = collect($release['jobs']['release']['steps'])->keyBy('name');
    expect($steps['Checkout']['with']['ref'])->toBe('${{ env.RELEASE_TAG }}')
        ->and($steps['Extract Docker Metadata']['with']['flavor'])->toBe('latest=false')
        ->and($steps['Extract Docker Metadata']['with']['tags'])
        ->toContain('type=ref,event=tag', '!github.event.release.prerelease')
        ->and($steps['Build Image']['with']['tags'])->toContain('${{ steps.meta.outputs.tags }}')
        ->and($steps['Push Image']['run'])->toContain('docker push "$tag"')
        ->and($steps['Push Image'])->not->toHaveKey('uses')
        ->and($steps['Create or update GitHub Release']['if'])->toBe("github.event_name == 'push'")
        ->and($steps['Validate version tag matches config/app.php']['run'])->toContain('TAG="$RELEASE_TAG"');
});

test('compose runs pinned registry images and local builds are explicit', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));
    $build = Yaml::parseFile(base_path('docker-compose.build.yml'));

    foreach (['app', 'worker'] as $service) {
        expect($compose['services'][$service])->not->toHaveKey('build')
            ->and($compose['services'][$service]['image'])
            ->toMatch('/^ghcr\\.io\\/timux\\/wehrportal:\\$\\{WEHRPORTAL_VERSION:-v[0-9]+(?:\\.[0-9]+){1,3}\\}$/')
            ->and($compose['services'][$service]['environment'])
            ->toContain('APP_KEY=${APP_KEY:?Set a persistent APP_KEY in .env}', 'DB_PASSWORD=${DB_PASSWORD:?Set a database password in .env}')
            ->and($build['services'][$service]['image'])->toBe('wehrportal:local')
            ->and($build['services'][$service]['build']['dockerfile'])->toBe('Dockerfile');
    }

    expect($compose['services']['app']['image'])->toBe($compose['services']['worker']['image'])
        ->and($compose['services']['pgsql']['environment']['POSTGRES_PASSWORD'])
        ->toBe('${DB_PASSWORD:?Set a database password in .env}');
});

test('runtime sources and infrastructure contain no predecessor identifiers', function () {
    foreach (['app', 'bootstrap', 'config', 'database', 'docker', 'extensions', 'modules', 'public/images', 'routes', '.github', '.devcontainer'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            expect(strtolower($file->getFilename()))->not->toContain('openwehr');
            if (in_array($file->getExtension(), ['php', 'js', 'json', 'yml', 'yaml', 'sh', 'sql', 'svg'], true)) {
                expect(strtolower(file_get_contents($file->getPathname())), $file->getPathname())
                    ->not->toContain('openwehr');
            }
        }
    }
});

test('Dependabot covers the app, E2E tooling, base images and CI actions', function () {
    $config = Yaml::parseFile(base_path('.github/dependabot.yml'));
    $entries = collect($config['updates']);
    expect($entries->pluck('package-ecosystem')->unique()->sort()->values()->all())
        ->toBe(['composer', 'docker', 'github-actions', 'npm']);
    expect($entries->where('package-ecosystem', 'npm')->pluck('directory')->all())->toBe(['/', '/tests/e2e']);
    foreach ($entries as $entry) {
        expect($entry['schedule']['interval'])->toBe('weekly')
            ->and($entry['open-pull-requests-limit'])->toBeLessThanOrEqual(3);
    }
});
