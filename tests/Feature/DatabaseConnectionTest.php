<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
  use RefreshDatabase;

  public function test_can_create_user(): void
  {
    User::factory()->create([
      'email' => 'ci-test@example.com',
    ]);

    $this->assertDatabaseHas('users', [
      'email' => 'ci-test@example.com',
    ]);
  }
}
