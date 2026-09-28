<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Laura',
            'email' => 'laura@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('users', ['email' => 'laura@example.com']);
        $this->assertDatabaseCount('auth_tokens', 1);
        $this->assertNotSame('password123', $response->json('user.password'));
    }

    public function test_it_logs_in_with_the_registered_password(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Laura',
            'email' => 'laura@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'laura@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_it_rejects_invalid_login_credentials(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonPath('message', 'El correo o la contraseña no son correctos.');
    }
}