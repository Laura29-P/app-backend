<?php

namespace Tests\Feature;

use App\Models\LearningProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_characters_cost_five_ten_and_fifteen_stars_and_owned_characters_are_not_charged_twice(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Player', 'email' => 'player@example.com', 'password' => 'password123',
        ])->json('token');
        $this->withToken($token)->getJson('/api/account/access')->assertOk();
        $this->getJson('/api/characters?client_id=price-test')->assertOk();
        LearningProgress::where('client_id', 'price-test')->update(['stars' => 30]);
        foreach (['niko' => 25, 'sol' => 15, 'mara' => 0] as $character => $balance) {
            $this->postJson('/api/characters/'.$character.'/purchase', ['client_id' => 'price-test'])
                ->assertOk()->assertJsonPath('stars', $balance)->assertJsonPath('selected_character', $character);
        }
        $this->postJson('/api/characters/niko/purchase', ['client_id' => 'price-test'])
            ->assertOk()->assertJsonPath('stars', 0);
    }
}
