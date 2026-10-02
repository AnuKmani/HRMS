<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/device-tokens and DELETE /api/v1/device-tokens/{deviceToken}.
 *
 * The interesting part of this endpoint is not the insert, it is the two
 * things that go *wrong* on a handset: it re-registers constantly (app
 * start, token rotation, a reinstall that keeps its identifier), and the
 * token Firebase hands back can move to a different account entirely. Both
 * would be constraint violations under a naive insert, so registration is
 * an upsert on (user, device) and a token already claimed by somebody else
 * is taken from them first.
 *
 * And the token itself never leaves the building — not to its owner, not
 * to anybody.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private function become(?string $role = null): User
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole($role);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'device_identifier' => 'device-abcdef12',
            'platform' => 'android',
            'fcm_token' => str_repeat('token-', 8),
            'app_version' => '1.4.0',
        ], $overrides);
    }

    public function test_registration_needs_a_signed_in_account(): void
    {
        $this->postJson('/api/v1/device-tokens', $this->payload())
            ->assertUnauthorized();
    }

    public function test_registration_stores_the_token_and_never_returns_it(): void
    {
        $user = $this->become();

        $response = $this->postJson('/api/v1/device-tokens', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'device_identifier' => 'device-abcdef12',
            'platform' => 'android',
            'active' => true,
        ]);

        // The resource omits `fcm_token`, and the model hides it, so the
        // value is not merely filtered at the end — it never arrives.
        $this->assertStringNotContainsString('token-token', $response->getContent());
        $this->assertStringNotContainsString('fcm_token', $response->getContent());
    }

    public function test_re_registering_the_same_handset_updates_rather_than_duplicates(): void
    {
        $user = $this->become();

        $this->postJson('/api/v1/device-tokens', $this->payload())
            ->assertCreated();

        $rotated = str_repeat('rotated-', 8);

        $this->postJson('/api/v1/device-tokens', $this->payload([
            'fcm_token' => $rotated,
            'app_version' => '1.5.0',
        ]))->assertCreated();

        $rows = DeviceToken::query()->where('user_id', $user->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($rotated, $rows->first()->fcm_token);
        $this->assertSame('1.5.0', $rows->first()->app_version);
    }

    public function test_a_token_moves_when_the_same_handset_signs_in_elsewhere(): void
    {
        $mine = $this->become();
        $token = str_repeat('moving-', 8);

        $this->postJson('/api/v1/device-tokens', $this->payload([
            'device_identifier' => 'shared-handset',
            'fcm_token' => $token,
        ]))->assertCreated();

        // Somebody else signs into the same handset: Firebase reassigns
        // nothing, the *install* is the same, so the token now belongs to
        // the account that holds the phone. The old row is unreachable —
        // it is a credential for a device that will never present it again
        // — so it is dropped, not left behind as a second live target.
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/device-tokens', $this->payload([
            'device_identifier' => 'shared-handset',
            'fcm_token' => $token,
        ]))->assertCreated();

        $this->assertSame(
            0,
            DeviceToken::query()->where('user_id', $mine->id)->count(),
        );
        $this->assertSame(1, DeviceToken::query()->where('fcm_token', $token)->count());
    }

    public function test_deleting_a_token_is_scoped_to_the_caller(): void
    {
        $mine = $this->become();
        $other = User::factory()->create();

        $mineRow = DeviceToken::create([
            'user_id' => $mine->id,
            'device_identifier' => 'device-mine-0001',
            'platform' => 'android',
            'fcm_token' => str_repeat('mine-', 8),
        ]);

        $theirRow = DeviceToken::create([
            'user_id' => $other->id,
            'device_identifier' => 'device-theirs-1',
            'platform' => 'ios',
            'fcm_token' => str_repeat('their-', 8),
        ]);

        // 403 would confirm the id exists on an account that is not yours.
        $this->deleteJson("/api/v1/device-tokens/{$theirRow->id}")->assertNotFound();
        $this->assertNotNull($theirRow->fresh());

        $this->deleteJson("/api/v1/device-tokens/{$mineRow->id}")->assertOk();
        $this->assertNull($mineRow->fresh());
    }

    public function test_registration_rejects_a_platform_that_cannot_receive_a_push(): void
    {
        $this->become();

        $this->postJson('/api/v1/device-tokens', $this->payload([
            'platform' => 'blackberry',
        ]))->assertStatus(422);

        $this->postJson('/api/v1/device-tokens', $this->payload([
            'device_identifier' => 'short',
        ]))->assertStatus(422);

        $this->assertSame(0, DeviceToken::query()->count());
    }
}
