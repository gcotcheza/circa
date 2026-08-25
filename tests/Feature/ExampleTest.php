<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Step 3 put everything behind session auth, so the root URL is no longer a
     * 200 for anyone. The redirect IS the smoke test: routing, the middleware
     * stack and the auth redirect target all resolve.
     */
    public function test_the_application_redirects_guests_to_the_login_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
