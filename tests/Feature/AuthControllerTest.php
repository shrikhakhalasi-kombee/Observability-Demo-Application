<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Register ──────────────────────────────────────────────────────────────

    public function test_register_returns_201_with_user_data_and_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name'     => 'Alice',
            'email'    => 'alice@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'name', 'email'],
                     'token',
                 ])
                 ->assertJsonPath('data.name', 'Alice')
                 ->assertJsonPath('data.email', 'alice@example.com');

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_register_returns_422_for_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name'     => 'Bob',
            'email'    => 'dup@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_register_returns_422_for_short_password(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name'     => 'Carol',
            'email'    => 'carol@example.com',
            'password' => 'short',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }

    public function test_register_returns_422_for_malformed_email(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name'     => 'Dave',
            'email'    => 'not-an-email',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function test_login_returns_200_with_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email'    => 'eve@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email'    => 'eve@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['token']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_returns_401_for_wrong_password(): void
    {
        User::factory()->create([
            'email'    => 'frank@example.com',
            'password' => bcrypt('correct'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email'    => 'frank@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401)
                 ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_login_returns_422_for_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/login', []);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email', 'password']);
    }
}
