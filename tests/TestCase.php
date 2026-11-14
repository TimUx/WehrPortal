<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Deferred work (the login mail) runs at once, so tests see it without a full request
        $this->withoutDefer();
    }
}
