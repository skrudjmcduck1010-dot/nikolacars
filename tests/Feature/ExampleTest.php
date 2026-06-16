<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_redirects_to_admin(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }

    public function test_the_login_page_is_available(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
    }
}
