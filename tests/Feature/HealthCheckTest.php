<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_application_is_up(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
