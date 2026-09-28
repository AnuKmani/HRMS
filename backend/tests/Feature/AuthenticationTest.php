<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * POST /api/v1/auth/login, /logout, /change-password, GET /auth/me and the
 * session endpoints — exercised end to end with real bearer tokens rather
 * than Sanctum::actingAs, so the token round-trip itself is covered.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password';

    private const NEW_PASSWORD = 'NewSecret123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /**
     * Sign in and return the `data` payload (token + user).
     *
     * @return array<string, mixed>
     */
    private function login(User $user, array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/auth/login', array_merge([
            'email' => $user->email,
            'password' => self::PASSWORD,
        ], $overrides));

        $response->assertOk();

        return $response->json('data');
    }

    /* ----------------------------------------------------------- login */

    public function test_login_returns_a_token_and_the_authenticated_user(): void
    {
        $user = $this->user();
        $user->assignRole('HR Admin');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'Pixel 8',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.roles.0', 'HR Admin');

        $this->assertIsString($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.token'));

        // Roles and permissions come back so the app can decide what to show.
        $permissions = $response->json('data.user.permissions');
        $this->assertContains('employees.view', $permissions);
        // Phase 8 widened HR Admin to *correct* payroll - and deliberately
        // stopped one grant short of the irreversible button. `payroll.lock`
        // belongs to Payroll Admin and Super Admin alone, which is the whole
        // reason the two are separate permissions rather than one.
        $this->assertContains('payroll.manage', $permissions);
        $this->assertNotContains('payroll.lock', $permissions);
    }

    public function test_login_response_never_contains_a_password_hash(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user()->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertStringNotContainsString('$2y$', $response->getContent());
        $this->assertStringNotContainsString('$argon', $response->getContent());
    }

    public function test_plain_text_token_is_never_stored_in_the_database(): void
    {
        $plainText = $this->login($this->user(), ['device_name' => 'Pixel 8'])['token'];

        $stored = PersonalAccessToken::query()->sole();

        [, $secret] = explode('|', $plainText, 2);

        $this->assertSame('Pixel 8', $stored->name);
        $this->assertNotSame($plainText, $stored->token);
        $this->assertStringNotContainsString($secret, $stored->token);
        // createToken() hashes only the entropy half of "id|secret" — the id
        // is stored in the clear as the primary key, the secret never is.
        $this->assertSame(hash('sha256', $secret), $stored->token);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plainText]);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);
    }

    public function test_login_gives_an_identical_answer_for_unknown_email_and_wrong_password(): void
    {
        $user = $this->user();

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'definitely-not-registered@example.com',
            'password' => 'whatever-it-takes',
        ])->assertUnauthorized();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertUnauthorized();

        $this->assertFalse($unknownEmail->json('success'));
        $this->assertSame($unknownEmail->json('message'), $wrongPassword->json('message'));
        $this->assertStringNotContainsString('definitely-not-registered', $unknownEmail->json('message'));
    }

    public function test_deactivated_account_cannot_sign_in_even_with_correct_password(): void
    {
        $user = $this->user(['status' => User::STATUS_INACTIVE]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertUnauthorized();

        $this->assertStringContainsString('deactivated', $response->json('message'));
    }

    public function test_login_defaults_the_device_name_when_not_supplied(): void
    {
        $this->login($this->user());

        $this->assertSame('API client', PersonalAccessToken::query()->sole()->name);
    }

    public function test_login_replaces_the_previous_token_for_the_same_device(): void
    {
        $user = $this->user();

        $first = $this->login($user, ['device_name' => 'Pixel 8'])['token'];
        $second = $this->login($user, ['device_name' => 'Pixel 8'])['token'];

        $this->assertSame(1, PersonalAccessToken::query()->where('name', 'Pixel 8')->count());

        $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_login_rejects_missing_credentials_with_a_422(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertGuest();
    }

    /* -------------------------------------------------------------- me */

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->user();
        $user->assignRole('HR Executive');
        Employee::factory()->create(['user_id' => $user->id, 'salary' => 95000]);

        $token = $this->login($user)['token'];

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles.0', 'HR Executive')
            ->assertJsonPath('data.employee.employment_status', Employee::STATUS_ACTIVE);

        $this->assertArrayNotHasKey('password', $response->json('data'));

        // The employee projection is fixed and narrow — salary, birth date and
        // address are not part of the generic user payload.
        $this->assertEqualsCanonicalizing(
            ['id', 'employee_code', 'full_name', 'photo_path', 'department', 'designation', 'employment_type', 'employment_status'],
            array_keys($response->json('data.employee'))
        );
        $this->assertStringNotContainsString('95000', $response->getContent());
    }

    public function test_me_works_without_an_employee_record(): void
    {
        $token = $this->login($this->user())['token'];

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.employee', null);
    }

    public function test_me_requires_a_bearer_token(): void
    {
        $this->user();

        $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertFalse($response->json('success'));
        $this->assertIsString($response->json('message'));
    }

    public function test_me_rejects_a_token_that_was_signed_out(): void
    {
        $token = $this->login($this->user())['token'];

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_rejects_a_garbage_token(): void
    {
        $this->user();

        $this->withToken('not-a-real-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /* ---------------------------------------------------- change password */

    public function test_change_password_verifies_the_current_password_and_persists_the_new_one(): void
    {
        $user = $this->user();
        $token = $this->login($user)['token'];

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_change_password_rejects_an_incorrect_current_password(): void
    {
        $token = $this->login($this->user())['token'];

        $response = $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'this-is-not-it',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertArrayHasKey('current_password', $response->json('errors'));

        // The password must be untouched after a rejected attempt.
        $this->postJson('/api/v1/auth/login', [
            'email' => User::query()->sole()->email,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    public function test_change_password_rejects_a_weak_or_unconfirmed_password(): void
    {
        $token = $this->login($this->user())['token'];

        $tooShort = $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
        $tooShort->assertStatus(422);
        $this->assertArrayHasKey('password', $tooShort->json('errors'));

        $unconfirmed = $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => 'ValidPass123',
            'password_confirmation' => 'DifferentPass123',
        ]);
        $unconfirmed->assertStatus(422);
        $this->assertArrayHasKey('password', $unconfirmed->json('errors'));
    }

    public function test_change_password_signs_every_other_device_out(): void
    {
        $user = $this->user();
        $phoneA = $this->login($user, ['device_name' => 'Phone A'])['token'];
        $phoneB = $this->login($user, ['device_name' => 'Phone B'])['token'];

        $this->withToken($phoneA)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->withToken($phoneA)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($phoneB)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->user();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnauthorized();
    }

    /* --------------------------------------------------------- sessions */

    public function test_sessions_lists_devices_without_exposing_the_token_hash(): void
    {
        $user = $this->user();
        $this->login($user, ['device_name' => 'Phone A']);
        $current = $this->login($user, ['device_name' => 'Phone B'])['token'];

        $response = $this->withToken($current)->getJson('/api/v1/auth/sessions');

        $response->assertOk()->assertJsonPath('success', true);

        $sessions = $response->json('data');
        $this->assertCount(2, $sessions);
        $this->assertEqualsCanonicalizing(
            ['Phone A', 'Phone B'],
            collect($sessions)->pluck('device')->all()
        );

        // Exact projection: identifiers and timestamps, never the stored hash.
        $this->assertEqualsCanonicalizing(
            ['id', 'device', 'is_current', 'last_used_at', 'created_at', 'expires_at'],
            array_keys($sessions[0])
        );

        $currentFlags = collect($sessions)->pluck('is_current', 'device');
        $this->assertFalse((bool) $currentFlags['Phone A']);
        $this->assertTrue((bool) $currentFlags['Phone B']);
    }

    public function test_revoking_a_session_signs_that_device_out(): void
    {
        $user = $this->user();
        $phoneA = $this->login($user, ['device_name' => 'Phone A'])['token'];
        $phoneB = $this->login($user, ['device_name' => 'Phone B'])['token'];

        $sessions = $this->withToken($phoneB)->getJson('/api/v1/auth/sessions')->json('data');
        $phoneASessionId = collect($sessions)->firstWhere('device', 'Phone A')['id'];

        $this->withToken($phoneB)
            ->deleteJson("/api/v1/auth/sessions/{$phoneASessionId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($phoneA)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($phoneB)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_session_belonging_to_another_account_cannot_be_revoked(): void
    {
        $otherToken = $this->login($this->user(), ['device_name' => 'Other device'])['token'];
        $otherSessionId = PersonalAccessToken::query()
            ->where('name', 'Other device')
            ->sole()
            ->id;

        $mine = $this->login($this->user())['token'];

        // 404, not 403: a 403 would confirm the id exists on someone else's
        // account.
        $this->withToken($mine)
            ->deleteJson("/api/v1/auth/sessions/{$otherSessionId}")
            ->assertNotFound();

        $this->withToken($otherToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_sessions_and_logout_require_authentication(): void
    {
        $this->user();

        $this->getJson('/api/v1/auth/sessions')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/sessions/1')->assertUnauthorized();
    }
}
