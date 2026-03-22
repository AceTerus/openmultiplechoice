<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SupabaseTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupabaseTokenExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exchanges_supabase_token_for_sanctum_token(): void
    {
        $claims = [
            'sub' => 'supabase-user-123',
            'email' => 'learner@example.com',
            'user_metadata' => [
                'full_name' => 'Learner Example',
            ],
            'email_confirmed' => true,
        ];

        $this->mock(SupabaseTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('supabase-session-token')
                ->andReturn($claims);
        });

        $response = $this->withHeader('Authorization', 'Bearer supabase-session-token')
            ->postJson('/api/supabase/exchange-token');

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'name', 'public_name', 'email'],
            ])
            ->assertJsonPath('user.email', 'learner@example.com');

        $this->assertDatabaseHas('users', [
            'supabase_id' => 'supabase-user-123',
            'email' => 'learner@example.com',
        ]);
    }

    public function test_it_links_existing_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'existing@example.com',
            'name' => 'Existing Name',
            'public_name' => 'Existing Public',
        ]);

        $claims = [
            'sub' => 'supabase-existing',
            'email' => 'existing@example.com',
            'user_metadata' => [
                'full_name' => 'Updated Name',
            ],
        ];

        $this->mock(SupabaseTokenVerifier::class, function ($mock) use ($claims) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('supabase-existing-token')
                ->andReturn($claims);
        });

        $response = $this->withHeader('Authorization', 'Bearer supabase-existing-token')
            ->postJson('/api/supabase/exchange-token');

        $response->assertOk()
            ->assertJsonPath('user.id', $existing->id);

        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'supabase_id' => 'supabase-existing',
        ]);
    }

    public function test_token_is_required(): void
    {
        $response = $this->postJson('/api/supabase/exchange-token');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }
}



