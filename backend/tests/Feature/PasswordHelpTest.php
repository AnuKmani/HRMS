<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\InAppNotification;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Someone forgot their password: HR is told, HR resets it, they choose a
 * new one. And the last resort on the server.
 */
class PasswordHelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_forgot_password_alerts_hr_with_a_link_to_the_profile(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');
        $engineer = User::factory()->create(['email' => 'arun@example.test']);
        $engineer->assignRole('Site Engineer');
        $employee = Employee::factory()->create(['user_id' => $engineer->id, 'email' => 'arun@example.test']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'Arun@Example.test'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'If that email address is registered, HR has been asked to reset your password. They will give you a new temporary password.');

        $alert = InAppNotification::query()->where('user_id', $hr->id)->sole();
        $this->assertSame('security.password_help', $alert->type);
        $this->assertSame('/employees/'.$employee->id, $alert->data['route']);
        $this->assertStringContainsString('forgot their password', $alert->body);

        // The person who asked is not told about themselves.
        $this->assertSame(0, InAppNotification::query()->where('user_id', $engineer->id)->count());

        // Asking again the same day does not flood HR's bell.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'arun@example.test'])->assertStatus(202);
        $this->assertSame(1, InAppNotification::query()->where('user_id', $hr->id)->count());
    }

    public function test_resetting_the_password_clears_the_alert_for_every_hr_user(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');
        $otherHr = User::factory()->create();
        $otherHr->assignRole('HR Admin');
        $engineer = User::factory()->create(['email' => 'arun@example.test']);
        $engineer->assignRole('Site Engineer');
        $employee = Employee::factory()->create(['user_id' => $engineer->id, 'email' => 'arun@example.test']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'arun@example.test'])->assertStatus(202);
        $this->assertSame(2, InAppNotification::query()->whereNull('read_at')->count());

        $this->actingAs($hr)
            ->postJson("/api/v1/employees/{$employee->id}/account/reset-password", ['password' => '12345'])
            ->assertOk();

        $this->assertSame(0, InAppNotification::query()->whereNull('read_at')->count());
        $this->actingAs($otherHr)->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 0);
    }

    public function test_an_unknown_email_gets_the_same_answer_and_alerts_nobody(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'If that email address is registered, HR has been asked to reset your password. They will give you a new temporary password.');

        $this->assertSame(0, InAppNotification::query()->count());
    }

    public function test_the_server_command_resets_anyone_including_the_only_super_admin(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.test']);
        $admin->assignRole('Super Admin');
        $admin->createToken('old-phone');

        $this->artisan('hrms:reset-password', ['email' => 'boss@example.test', '--password' => '12345'])
            ->expectsOutputToContain('Temporary password: 12345')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check('12345', $admin->password));
        $this->assertTrue((bool) $admin->must_change_password);
        $this->assertSame(0, $admin->tokens()->count());

        $this->artisan('hrms:reset-password', ['email' => 'missing@example.test'])->assertFailed();
    }
}
