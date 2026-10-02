<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * POST /auth/forgot-password and POST /auth/reset-password, live.
 *
 * Phase 12 removed the PASSWORD_RESET_ENABLED gate and the 501 it guarded,
 * so there is no "unavailable" branch left to assert — which is the point.
 * What is asserted instead is that the two answers are still
 * indistinguishable for a known and an unknown address, that a real link
 * really is built and queued, and that a successful swap kills every token
 * issued before it.
 *
 * Notification::fake() keeps these runs off any transport;
 * MAIL_MAILER=array in phpunit.xml is the belt to that pair of braces.
 * Nothing in the application ever claims a message was sent when it was
 * not — see PasswordResetController for what "sent" means under each
 * configured mailer.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const RESET_PASSWORD = 'BrandNew12345';

    /* ------------------------------------------------------------ request */

    public function test_forgot_password_is_available(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => User::factory()->create()->email,
        ]);

        // 202, not 501 and not 200: the resource is a request for a link
        // that has been accepted for delivery, not a completed transaction.
        $response->assertStatus(202)->assertJsonPath('success', true);

        // The gate is gone rather than defaulted: an endpoint that only
        // works after somebody remembers a flag is an endpoint that is
        // broken in every environment where nobody remembered.
        $this->assertNull(config('auth.password_reset.enabled'));
    }

    public function test_validation_still_runs_before_the_broker(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', []);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /* ------------------------------------------------ enumeration safety */

    public function test_forgot_password_answers_identically_for_known_and_unknown_addresses(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->json('data'), $unknown->json('data'));

        // A link really was queued for the registered address — the endpoint
        // is wired to the broker, not returning a canned success. The
        // address nobody holds an account for produces nothing to assert
        // on, which is itself the guarantee: there is no notifiable.
        Notification::assertSentTo($user, PasswordResetMail::class);
    }

    /* --------------------------------------------------------- the mail */

    public function test_the_reset_mail_is_queued_and_points_at_the_reset_page(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);

        Notification::assertSentTo($user, PasswordResetMail::class, function (PasswordResetMail $notification, array $channels) use ($user): bool {
            $mail = $notification->toMail($user);

            // The URL is one this application can serve. Out of the box the
            // broker builds it from a named route that does not exist here,
            // so without AppServiceProvider's callback this would throw
            // rather than send — which is why the link is asserted and not
            // merely the notification's existence.
            $this->assertStringContainsString('/reset-password?token=', (string) $mail->actionUrl);
            $this->assertStringContainsString('email='.urlencode($user->email), (string) $mail->actionUrl);

            return $channels === ['mail'];
        });
    }

    /* ------------------------------------------------------------- reset */

    public function test_reset_password_swaps_the_password_and_kills_every_session(): void
    {
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

    public function test_reset_password_is_not_available_unauthenticated_but_not_disabled(): void
    {
        // A stray token for an address nobody has ever asked a link for
        // answers 422 naming `token` — the endpoint is *running*, and the
        // reason it refuses is that the link is wrong, not that the feature
        // is switched off. Before Phase 12 this same request answered 501.
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'anything',
            'email' => 'someone@example.com',
            'password' => self::RESET_PASSWORD,
            'password_confirmation' => self::RESET_PASSWORD,
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertArrayHasKey('token', $response->json('errors'));
    }

    public function test_reset_password_rejects_a_bad_token_without_touching_the_account(): void
    {
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

    /* ----------------------------------------------------- the landing page */

    public function test_the_reset_page_needs_both_parts_of_the_link(): void
    {
        $response = $this->get('/reset-password');

        $response->assertOk();
        $this->assertStringContainsString('incomplete', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_reset_page_offers_a_form_when_the_link_is_complete(): void
    {
        $response = $this->get('/reset-password?token=abc123&email=someone%40example.com');

        $response->assertOk();
        $this->assertStringContainsString('Choose a new password', $response->getContent());
        $this->assertStringContainsString('someone@example.com', $response->getContent());
        // The two values ride into the script as JSON with the hex
        // escapes on, so a token containing `<` cannot become markup.
        $this->assertStringContainsString('/api/v1/auth/reset-password', $response->getContent());
    }
}
