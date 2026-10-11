<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_login_when_unauthenticated(): void
    {
        $this->get('/')->assertRedirect('/anmelden');
    }
}
