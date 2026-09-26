<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The forgot-password / reset-password endpoints exist, are validated and are
 * routed — but answer 501 until a mailer can actually deliver, because this
 * environment only has MAIL_MAILER=log.
 *
 * The "enabled" tests below prove the flow is complete rather than stubbed;
 * Notification::fake() keeps those runs from touching any transport at all.
 * Nothing in the application ever claims a message was sent when it was not.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const RESET_PASSWORD = 'BrandNew12345';

    /* ------------------------------------------------ disabled (default) */

    public function test_forgot_password_reports_that_it_is_not_available_yet(): void
    {
        $this->assertFalse((bool) config('auth.password_reset.enabled'));

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => User::factory()->create()->email,
        ]);

        $response->assertStatus(501)->assertJsonPath('success', false);
        $this->assertStringContainsString('not available yet', $response->json('message'));
    }

    public function test_reset_password_reports_that_it_is_not_available_yet(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'anything',
            'email' => 'someone@example.com',
            'password' => self::RESET_PASSWORD,
            'password_confirmation' => self::RESET_PASSWORD,
        ]);

        $response->assertStatus(501)->assertJsonPath('success', false);
    }

    public function test_validation_still_runs_before_the_enabled_check(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', []);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /* ---------------------------------------------------- enabled flow */

    public function test_forgot_password_answers_identically_for_known_and_unknown_addresses(): void
    {
        config(['auth.password_reset.enabled' => true]);
        Notification::fake();

        $user = User::factory()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json('message'), $unknown->json('message'));

        // A link really was queued for the registered address — the endpoint
        // is wired to the broker, not returning a canned success.
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_swaps_the_password_and_kills_every_session(): void
    {
        config(['auth.password_reset.enabled' => true]);
        Notification::fake();

        $user = User::factory()->create();
        $existingToken = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => self::RESET_PASSWORD,
            'password_confirmation' => self::RESET_PASSWORD,
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::RESET_PASSWORD,
        ])->assertOk();

        // The premise of a reset is that the old password may be compromised,
        // so every token issued before it must be gone.
        $this->withToken($existingToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reset_password_rejects_a_bad_token_without_touching_the_account(): void
    {
        config(['auth.password_reset.enabled' => true]);
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => self::RESET_PASSWORD,
            'password_confirmation' => self::RESET_PASSWORD,
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertArrayHasKey('token', $response->json('errors'));

        // Password untouched.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_reset_password_requires_a_matching_confirmation(): void
    {
        config(['auth.password_reset.enabled' => true]);
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'irrelevant',
            'email' => $user->email,
            'password' => self::RESET_PASSWORD,
            'password_confirmation' => 'Different12345',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('password', $response->json('errors'));
    }
}
