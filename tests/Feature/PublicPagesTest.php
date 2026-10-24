<?php

test('search engines are kept out', function () {
    expect(file_get_contents(public_path('robots.txt')))->toContain("Disallow: /\n");

    $this->get(route('auth.magic-link.form'))
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

test('404 page is German, with logo and the fire brigade text', function () {
    $this->get('/gibt-es-nicht')
        ->assertNotFound()
        ->assertSee('Ups! Hier ist etwas in Flammen aufgegangen.')
        ->assertSee('Die Seite, die du suchst, existiert leider nicht (mehr).')
        ->assertSee('Keine Sorge, unsere Kameraden sind bereits alarmiert!')
        ->assertSee('images/wehrportal-logo.svg')
        ->assertDontSee('Not Found');
});
