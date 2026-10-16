<?php

use Extensions\ExtensionsServiceProvider;

it('registers the extensions provider through package discovery', function () {
    expect(app()->getProviders(ExtensionsServiceProvider::class))->not->toBeEmpty();
});
