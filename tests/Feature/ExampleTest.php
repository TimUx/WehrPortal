<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // A PHPUnit class: the Pest `uses()` in tests/Pest.php doesn't reach it, and the page reads settings
    use RefreshDatabase;

    public function test_root_redirects_to_login_when_unauthenticated(): void
    {
        $this->get('/')->assertRedirect('/anmelden');
    }
}
