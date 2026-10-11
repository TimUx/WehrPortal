<?php

use App\Services\ScanRegistry;

// ---------------------------------------------------------------------------
// ScanRegistry unit-style tests (uses the app container)
// ---------------------------------------------------------------------------

test('ScanRegistry returns null when no resolvers are registered', function () {
    $registry = new ScanRegistry;

    expect($registry->resolve('WEHR0001'))->toBeNull();
});

test('ScanRegistry returns the URL from the matching resolver', function () {
    $registry = new ScanRegistry;
    $registry->register('test', fn (string $code) => $code === 'MATCH' ? 'https://example.com/item/1' : null);

    expect($registry->resolve('MATCH'))->toBe('https://example.com/item/1');
    expect($registry->resolve('OTHER'))->toBeNull();
});

test('ScanRegistry tries resolvers in registration order and stops at first match', function () {
    $registry = new ScanRegistry;
    $calls = [];

    $registry->register('first', function (string $code) use (&$calls): ?string {
        $calls[] = 'first';

        return $code === 'HIT' ? 'https://example.com/first' : null;
    });
    $registry->register('second', function (string $code) use (&$calls): ?string {
        $calls[] = 'second';

        return 'https://example.com/second';
    });

    $url = $registry->resolve('HIT');

    expect($url)->toBe('https://example.com/first');
    expect($calls)->toBe(['first']); // second resolver never called
});

// ---------------------------------------------------------------------------
// /i/{code} route
// ---------------------------------------------------------------------------

test('unknown code returns 404', function () {
    // No resolvers registered beyond Kleiderkammer's (which needs a DB row)
    $registry = app(ScanRegistry::class);
    $registry->register('test', fn () => null);

    $this->get('/i/NOTFOUND')->assertNotFound();
});

test('known code redirects to the module URL', function () {
    $registry = app(ScanRegistry::class);
    $registry->register('test', fn (string $code) => $code === 'WEHR0001' ? route('home') : null);

    $this->get('/i/WEHR0001')->assertRedirect(route('home'));
});

test('scan URL is public and does not require login', function () {
    $registry = app(ScanRegistry::class);
    $registry->register('test', fn () => route('home'));

    $this->get('/i/anycode')->assertRedirect();
});
