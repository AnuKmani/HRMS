<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Every failure status the API promises, asserted against the envelope
 * { success, message, errors } so the Flutter client can parse one shape
 * regardless of what went wrong.
 *
 * Also proves no internal detail — stack trace, exception class, SQL — ever
 * reaches the caller.
 */
class ApiErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 403 case needs a real role to assign; without the RBAC seed
        // assignRole() throws RoleDoesNotExist.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    /* ------------------------------------------------------------- 401 */

    public function test_unauthenticated_request_returns_a_401_envelope(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors', []);

        $this->assertIsString($response->json('message'));
        $this->assertNotEmpty($response->json('message'));
    }

    /* ------------------------------------------------------------- 403 */

    public function test_forbidden_request_returns_a_403_envelope(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $response = $this->withToken($token)->getJson('/api/v1/roles');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors', []);

        $this->assertStringNotContainsString('Spatie', $response->getContent());
        $this->assertStringNotContainsString('UnauthorizedException', $response->getContent());
    }

    /* ------------------------------------------------------------- 404 */

    public function test_unknown_route_returns_a_404_envelope(): void
    {
        User::factory()->create();

        $response = $this->getJson('/api/v1/no-such-endpoint');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->assertIsString($response->json('message'));
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
    }

    public function test_missing_record_returns_a_404_envelope_without_naming_the_model(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $response = $this->withToken($token)->deleteJson('/api/v1/auth/sessions/999999');

        $response->assertStatus(404)->assertJsonPath('success', false);
        $this->assertStringNotContainsString('Model', $response->getContent());
        $this->assertStringNotContainsString('App\\', $response->getContent());
    }

    /* ------------------------------------------------------------- 405 */

    public function test_unsupported_method_returns_a_405_envelope(): void
    {
        $response = $this->getJson('/api/v1/auth/login');

        $response->assertStatus(405)->assertJsonPath('success', false);
        $this->assertIsString($response->json('message'));
    }

    /* ------------------------------------------------------------- 422 */

    public function test_validation_failure_returns_a_422_envelope_with_field_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $errors = $response->json('errors');
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);

        // `message` is Laravel's summary of the validation: the first field
        // error, plus a count of the rest — so a banner and per-field hints
        // can both be drawn from one response.
        $this->assertIsString($response->json('message'));
        $this->assertNotEmpty($response->json('message'));
        $this->assertStringStartsWith($errors['email'][0], $response->json('message'));
    }

    /* ------------------------------------------------------------- 429 */

    public function test_repeated_login_attempts_return_a_429_envelope(): void
    {
        $user = User::factory()->create();
        $attempts = (int) config('rate_limiting.login.max_attempts');

        for ($i = 0; $i < $attempts; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)->assertJsonPath('success', false);
        $this->assertIsString($response->json('message'));
        $this->assertNotEmpty($response->json('message'));

        // Lets a client back off instead of guessing.
        $this->assertNotEmpty($response->headers->get('Retry-After'));
    }

    public function test_login_throttle_is_configured_rather_than_hard_coded(): void
    {
        $this->assertSame(5, (int) config('rate_limiting.login.max_attempts'));
        $this->assertSame(1, (int) config('rate_limiting.login.decay_minutes'));

        // Changing the config changes the behaviour — no route edit required.
        config(['rate_limiting.login.max_attempts' => 2]);

        $email = User::factory()->create()->email;

        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'x'])
            ->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'x'])
            ->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'x'])
            ->assertStatus(429);
    }

    /* ------------------------------------------------------------- 500 */

    public function test_server_errors_return_safe_json_without_internal_detail(): void
    {
        // A deliberately broken endpoint, registered only for this test.
        Route::prefix('api')->middleware('api')->get('/_boom', function () {
            throw new RuntimeException('internal: connection to payroll_db failed at /var/www/secret.sql');
        });

        $response = $this->getJson('/api/_boom');

        $response->assertStatus(500)->assertJsonPath('success', false);

        $content = $response->getContent();
        $this->assertStringNotContainsString('payroll_db', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $this->assertStringNotContainsString('#0', $content);
        $this->assertIsString($response->json('message'));
    }

    /* ------------------------------------------------------ envelope keys */

    public function test_success_and_failure_envelopes_have_a_stable_shape(): void
    {
        $user = User::factory()->create();

        $ok = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertEqualsCanonicalizing(
            ['success', 'message', 'data'],
            array_keys($ok->json())
        );
        $this->assertTrue($ok->json('success'));

        $failed = $this->getJson('/api/v1/auth/me');

        $this->assertEqualsCanonicalizing(
            ['success', 'message', 'errors'],
            array_keys($failed->json())
        );
        $this->assertFalse($failed->json('success'));

        // `errors` must serialise as a JSON object, never a list, so Dart can
        // type it as Map<String, dynamic> without a runtime check.
        $this->assertStringContainsString('"errors":{}', $failed->getContent());
        $this->assertStringContainsString('"success":false', $failed->getContent());
    }
}
