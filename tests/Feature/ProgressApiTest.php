<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_available_plans_for_upgrade_actions(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Laura',
            'email' => 'plans@example.com',
            'password' => 'password123',
        ])->json('token');

        $this->withToken($token)->getJson('/api/account/access')
            ->assertOk()
            ->assertJsonPath('plans.basic.subjects.0', 'math')
            ->assertJsonPath('plans.family.subjects.1', 'english')
            ->assertJsonPath('plans.premium.subjects.2', 'geo');
    }

    public function test_it_saves_and_returns_progress_for_a_client(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Laura',
            'email' => 'progress@example.com',
            'password' => 'password123',
        ])->json('token');
        $this->withToken($token)->getJson('/api/account/access')->assertOk();
        $payload = [
            'client_id' => 'browser-test-123',
            'age_group' => 'peques',
            'stars' => 1,
            'completed_exercises' => ['m1' => true],
            'current_subject' => 'math',
            'current_index_in_subject' => 1,
        ];

        $this->withToken($token)->putJson('/api/progress', $payload)
            ->assertOk()
            ->assertJsonPath('client_id', 'browser-test-123')
            ->assertJsonPath('stars', 1);

        $this->withToken($token)->getJson('/api/progress?client_id=browser-test-123')
            ->assertOk()
            ->assertJson($payload);
    }

    public function test_it_rejects_an_invalid_progress_payload(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Laura',
            'email' => 'invalid-progress@example.com',
            'password' => 'password123',
        ])->json('token');
        $this->withToken($token)->getJson('/api/account/access')->assertOk();

        $this->withToken($token)->putJson('/api/progress', [
            'client_id' => '',
            'stars' => -1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'client_id',
                'age_group',
                'completed_exercises',
                'current_subject',
                'current_index_in_subject',
            ]);
    }

    public function test_basic_plan_cannot_save_more_than_five_exercises(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Laura',
            'email' => 'basic-limit@example.com',
            'password' => 'password123',
        ]);
        $token = $this->postJson('/api/auth/login', [
            'email' => 'basic-limit@example.com',
            'password' => 'password123',
        ])->json('token');

        User::where('email', 'basic-limit@example.com')->update([
            'trial_started_at' => now(),
            'subscription_plan' => 'basic',
        ]);

        $this->withToken($token)->putJson('/api/progress', [
            'client_id' => 'basic-limit-client',
            'age_group' => 'peques',
            'stars' => 6,
            'completed_exercises' => [
                'm1' => true,
                'm2' => true,
                'm3' => true,
                'm4' => true,
                'm5' => true,
                'm6' => true,
            ],
            'current_subject' => 'math',
            'current_index_in_subject' => 5,
        ])->assertForbidden()->assertJsonPath('code', 'plan_exercise_limit');
    }
}