<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ahmed',
            'username' => 'ahmed',
            'email' => 'ahmed@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    // ---------- Register ----------

    public function test_user_can_register(): void
    {
        $this->postJson('/api/v1/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonStructure(['success', 'user', 'token']);

        $this->assertDatabaseHas('users', [
            'email' => 'ahmed@test.com',
            'role' => 'user',
        ]);
    }

    public function test_register_cannot_set_role_to_admin(): void
    {
        $this->postJson('/api/v1/register', $this->registerPayload([
            'role' => 'admin',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'ahmed@test.com',
            'role' => 'user',
        ]);
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ahmed@test.com']);

        $this->postJson('/api/v1/register', $this->registerPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_register_fails_with_duplicate_username(): void
    {
        User::factory()->create(['username' => 'ahmed']);

        $this->postJson('/api/v1/register', $this->registerPayload([
            'email' => 'other@test.com',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_register_fails_when_password_confirmation_does_not_match(): void
    {
        $this->postJson('/api/v1/register', $this->registerPayload([
            'password_confirmation' => 'different123',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_register_fails_with_short_password(): void
    {
        $this->postJson('/api/v1/register', $this->registerPayload([
            'password' => '123',
            'password_confirmation' => '123',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    // ---------- Login ----------

    public function test_user_can_login(): void
    {
        User::factory()->create([
            'email' => 'ahmed@test.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'ahmed@test.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['success', 'user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'ahmed@test.com']);

        $this->postJson('/api/v1/login', [
            'email' => 'ahmed@test.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_login_fails_with_unknown_email(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'nobody@test.com',
            'password' => 'password123',
        ])->assertUnauthorized();
    }

    // ---------- Logout ----------

    public function test_user_can_logout_and_token_is_deleted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/logout')->assertUnauthorized();
    }

    public function test_products_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
