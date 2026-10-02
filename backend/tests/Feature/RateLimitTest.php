<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four Phase 12 rate limiters — `upload`, `write`, `device_token`,
 * `export` — and the rule that produced them.
 *
 * Phase 3's `login` and `password_reset` throttles are covered in
 * `ApiErrorHandlingTest`; this file is about the *new* ones and about the
 * property that makes the whole set maintainable: **every limit is a
 * config value read at request time, and none of them appears as a number
 * in a route file.**
 *
 * Reading at request time matters as much as the config file does. A
 * limiter that resolved its numbers when the service provider booted could
 * not be retuned without a deploy *or* a test — the closures in
 * `AppServiceProvider::registerRateLimiters()` call `config()` from inside
 * the request, which is why every test here can set a small number, watch
 * it trip, and leave the shipped default untouched.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * The middleware a route actually carries.
     *
     * @return array<int, mixed>
     */
    private function middlewareFor(string $method, string $uri): array
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array($method, $route->methods(), true) && $route->uri() === ltrim($uri, '/')) {
                return $route->gatherMiddleware();
            }
        }

        $this->fail("No route matches {$method} {$uri}.");
    }

    /* --------------------------------------------------- behaviour */

    public function test_the_device_token_limiter_trips_and_reads_its_limit_from_config(): void
    {
        $this->actingAsRole('Employee');

        config(['rate_limiting.device_token.max_attempts' => 2]);

        $register = fn (string $device): mixed => $this->postJson('/api/v1/device-tokens', [
            'device_identifier' => $device,
            'platform' => 'android',
            'fcm_token' => str_repeat('t', 24),
        ]);

        $register('device-00000001')->assertCreated();
        $register('device-00000002')->assertCreated();
        $register('device-00000003')->assertStatus(429);

        // The route carries the *name*, so the number above travelled
        // through config rather than past a literal baked into routes/.
        $this->assertContains('throttle:device_token', $this->middlewareFor('POST', 'api/v1/device-tokens'));
    }

    public function test_the_write_limiter_trips_on_a_high_risk_mutation(): void
    {
        $this->actingAsRole('HR Admin');

        config(['rate_limiting.write.max_attempts' => 2]);

        // An empty body: the throttle runs before validation, so the first
        // two attempts are 422 (the shape is wrong) and the third is 429
        // (the shape never got looked at). That ordering is the point —
        // rejecting a flood must not cost a hash, a disk seek or a query.
        $post = fn (): mixed => $this->postJson('/api/v1/employees', []);

        $post()->assertStatus(422);
        $post()->assertStatus(422);
        $post()->assertStatus(429);

        $this->assertContains('throttle:write', $this->middlewareFor('POST', 'api/v1/employees'));
    }

    public function test_the_upload_limiter_trips_on_a_multipart_endpoint(): void
    {
        $user = $this->actingAsRole('HR Admin');
        // The employee-documents endpoint requires the user to have an employee record
        Employee::factory()->create(['user_id' => $user->id]);

        config(['rate_limiting.upload.max_attempts' => 1]);

        $post = fn (): mixed => $this->postJson('/api/v1/employee-documents', []);

        $post()->assertStatus(422);
        $post()->assertStatus(429);

        $this->assertContains('throttle:upload', $this->middlewareFor('POST', 'api/v1/employee-documents'));
    }

    public function test_the_export_limiter_trips_on_a_report_download(): void
    {
        $this->actingAsRole('HR Admin');

        config(['rate_limiting.export.max_attempts' => 2]);

        $get = fn (): mixed => $this->getJson('/api/v1/reports/employees.directory/export?format=csv');

        $get()->assertOk();
        $get()->assertOk();
        $get()->assertStatus(429);

        $this->assertContains(
            'throttle:export',
            $this->middlewareFor('GET', 'api/v1/reports/{key}/export'),
        );
    }

    /* ------------------------------------------------- architecture */

    public function test_every_named_limiter_is_defined_in_config_with_a_documented_default(): void
    {
        foreach (['login', 'password_reset', 'attendance', 'upload', 'write', 'device_token', 'export'] as $name) {
            $this->assertIsInt(config("rate_limiting.{$name}.max_attempts"), "Missing limit for {$name}.");
            $this->assertIsInt(config("rate_limiting.{$name}.decay_minutes"), "Missing decay for {$name}.");
            $this->assertGreaterThan(0, config("rate_limiting.{$name}.max_attempts"));
            $this->assertGreaterThan(0, config("rate_limiting.{$name}.decay_minutes"));
        }
    }

    public function test_no_route_file_carries_a_hard_coded_throttle_number(): void
    {
        // The spec's rule, stated as a test: a limit that lives at the
        // route is a limit nobody will find when they go looking for it —
        // and two routes that disagree about uploads will disagree forever.
        foreach (['routes/api.php', 'routes/web.php'] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertNotFalse($source, "Could not read {$file}.");
            $this->assertSame(
                0,
                preg_match('/throttle:\s*\d+/', $source),
                "{$file} contains a hard-coded throttle number. Move it to config/rate_limiting.php.",
            );
        }
    }

    public function test_every_multipart_endpoint_carries_the_upload_limiter(): void
    {
        $multipart = [
            'POST api/v1/leave/{leaveRequest}/certificate',
            'POST api/v1/site-activity-reports/{siteActivityReport}/photos',
            'POST api/v1/daily-site-reports/{dailySiteReport}/photos',
            'POST api/v1/expenses/{expense}/receipts',
            'POST api/v1/employee-documents',
            'PUT api/v1/employee-documents/{document}',
            'POST api/v1/employee-training/{training}/complete',
        ];

        foreach ($multipart as $route) {
            [$method, $uri] = explode(' ', $route, 2);

            $this->assertContains(
                'throttle:upload',
                $this->middlewareFor($method, $uri),
                "{$route} is a file upload without the shared upload limiter.",
            );
        }
    }
}
