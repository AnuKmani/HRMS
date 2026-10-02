<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/auth/sessions, DELETE /api/v1/auth/sessions/{id},
 * DELETE /api/v1/auth/sessions, POST /api/v1/auth/sessions/revoke.
 *
 * A session screen is only useful if it is honest about three things:
 * which devices are signed in, which one *this* is, and what happens when
 * you kick one. It must also be honest about a fourth — that it never
 * hands a token back — because a page that lists credentials is a page an
 * attacker who has read one response can reuse forever.
 *
 * These tests use real personal access tokens rather than
 * `Sanctum::actingAs`, because `actingAs` installs a transient token that
 * no row backs; a screen about tokens that does not read tokens would pass
 * against an empty set and mean nothing.
 */
class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string} the user and a live token for it
     */
    private function signInWith(string $device): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken($device)->plainTextToken];
    }

    public function test_the_sessions_list_needs_a_signed_in_account(): void
    {
        $this->getJson('/api/v1/auth/sessions')->assertUnauthorized();
    }

    public function test_the_list_shows_this_accounts_devices_and_marks_the_current_one(): void
    {
        [$user, $here] = $this->signInWith('Pixel 8');
        $user->createToken('iPad');

        $response = $this->withToken($here)->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.device', 'Pixel 8')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.device', 'iPad')
            ->assertJsonPath('data.1.is_current', false);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_the_list_never_returns_a_token_or_its_hash(): void
    {
        [, $here] = $this->signInWith('Pixel 8');

        $body = $this->withToken($here)->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->getContent();

        // A personal access token is `{id}|{hash}`; the hash half is the
        // secret, the whole string is the credential.
        $secret = (string) explode('|', $here, 2)[1];

        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString($here, $body);

        // Nor the *column name*: a response that mentions `token` at all
        // invites a client to start depending on the field being there.
        foreach (['token', 'access_token', 'plain_text'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_revoking_one_session_kills_that_device_and_no_other(): void
    {
        [$user, $here] = $this->signInWith('Pixel 8');
        $tablet = $user->createToken('iPad');

        $id = $user->tokens()->where('name', 'iPad')->value('id');

        $this->withToken($here)
            ->deleteJson("/api/v1/auth/sessions/{$id}")
            ->assertOk();

        // The revoked token is dead — which is the whole point of the
        // screen — and the caller's own token still works.
        $this->withToken($tablet->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->withToken($here)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_revoking_somebody_elses_session_is_a_404_not_a_403(): void
    {
        [, $here] = $this->signInWith('Pixel 8');

        $other = User::factory()->create();
        $other->createToken('Elsewhere');

        $otherTokenId = $other->tokens()->value('id');

        $this->withToken($here)
            ->deleteJson("/api/v1/auth/sessions/{$otherTokenId}")
            ->assertNotFound();

        $this->assertNotNull($other->tokens()->first());
    }

    public function test_revoking_every_session_signs_the_caller_out_too(): void
    {
        [$user, $here] = $this->signInWith('Pixel 8');
        $user->createToken('iPad');

        $this->withToken($here)->deleteJson('/api/v1/auth/sessions')->assertOk();

        // Deliberate, and documented at the route: there is no
        // "keep this device" flag, because a control that silently keeps
        // the session you were trying to end is the one people cannot find
        // when they need it most. Sign out here means sign out everywhere,
        // including here.
        $this->assertSame(0, $user->tokens()->count());

        $this->withToken($here)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_plaintext_token_can_be_revoked_without_a_session_but_only_your_own(): void
    {
        [$user, $here] = $this->signInWith('Pixel 8');
        $tablet = $user->createToken('iPad');

        $this->withToken($here)
            ->postJson('/api/v1/auth/sessions/revoke', [])
            ->assertStatus(422);

        $this->withToken($here)
            ->postJson('/api/v1/auth/sessions/revoke', [
                'token' => $tablet->plainTextToken,
            ])
            ->assertOk();

        $this->withToken($tablet->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // Somebody else's real token, presented correctly, still 404s:
        // the endpoint is scoped to the caller's own tokens, so it cannot
        // be used to walk the table by guessing.
        $stranger = User::factory()->create();
        $strangerToken = $stranger->createToken('Not yours')->plainTextToken;

        $this->withToken($here)
            ->postJson('/api/v1/auth/sessions/revoke', [
                'token' => $strangerToken,
            ])
            ->assertNotFound();

        $this->assertNotNull($stranger->tokens()->first());
    }

    public function test_an_unknown_session_id_is_a_404(): void
    {
        [, $here] = $this->signInWith('Pixel 8');

        $this->withToken($here)
            ->deleteJson('/api/v1/auth/sessions/999999')
            ->assertNotFound();
    }
}
