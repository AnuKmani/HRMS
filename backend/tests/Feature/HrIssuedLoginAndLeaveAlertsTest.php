<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Mail\NotificationMail;
use App\Models\Employee;
use App\Models\InAppNotification;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * The business flow, end to end:
 *
 *  1. HR creates an employee's login and chooses the first password.
 *  2. The employee signs in with it and must set their own before anything
 *     else works.
 *  3. The employee applies for leave; HR gets a notification (bell + email).
 *  4. HR approves from the notification; the employee is notified back.
 */
class HrIssuedLoginAndLeaveAlertsTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    private function signIn(string $email, string $password): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'test-phone',
        ])->assertOk()->json('data.token');
    }

    /**
     * @return array{0: User, 1: Employee, 2: string}
     */
    private function hrCreatesLogin(string $password = '12345'): array
    {
        [$hr] = $this->signInAs('HR Admin');
        $employee = Employee::factory()->create([
            'email' => 'meera@example.test',
            'reporting_manager_id' => null,
        ]);

        $this->postJson("/api/v1/employees/{$employee->id}/account", [
            'role' => 'Employee',
            'password' => $password,
        ])->assertCreated()->assertJsonPath('data.temporary_password', $password);

        return [$hr, $employee->refresh(), $password];
    }

    public function test_hr_chooses_the_first_password_and_the_employee_must_replace_it(): void
    {
        [, $employee] = $this->hrCreatesLogin('12345');

        $token = $this->signIn('meera@example.test', '12345');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        // Nothing else until the password is their own.
        $this->withToken($token)->getJson('/api/v1/leave')
            ->assertForbidden()
            ->assertJsonPath('errors.password', 'password_change_required');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => '12345',
            'password' => 'MyOwnPass9',
            'password_confirmation' => 'MyOwnPass9',
        ])->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.must_change_password', false);
        $this->withToken($token)->getJson('/api/v1/leave')->assertOk();

        $this->assertFalse((bool) $employee->user->fresh()->must_change_password);
    }

    public function test_a_password_left_blank_is_generated(): void
    {
        $this->signInAs('HR Admin');
        $employee = Employee::factory()->create(['email' => 'blank@example.test']);

        $password = $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Employee'])
            ->assertCreated()
            ->json('data.temporary_password');

        $this->assertSame(12, strlen($password));
    }

    public function test_hr_resets_a_forgotten_password(): void
    {
        [$hr, $employee] = $this->hrCreatesLogin('12345');
        $employee->user->forceFill(['must_change_password' => false])->save();

        $this->become($hr);
        $this->postJson("/api/v1/employees/{$employee->id}/account/reset-password", ['password' => 'temp99'])
            ->assertOk()
            ->assertJsonPath('data.temporary_password', 'temp99');

        $this->assertTrue((bool) $employee->user->fresh()->must_change_password);
        $this->signIn('meera@example.test', 'temp99');
    }

    public function test_an_employee_cannot_reset_someone_elses_password(): void
    {
        [, $employee] = $this->hrCreatesLogin();
        $this->signInAs('Employee');

        $this->postJson("/api/v1/employees/{$employee->id}/account/reset-password")->assertForbidden();
    }

    public function test_manager_then_hr_each_alerted_on_their_turn_and_the_employee_kept_informed(): void
    {
        Mail::fake();

        [$hr, $employee] = $this->hrCreatesLogin();
        $employee->user->forceFill(['must_change_password' => false])->save();
        [$manager] = $this->makeSeat('Project Manager');

        // The employee applies.
        $this->become($employee->user);
        $leave = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-05', '2026-10-06');
        $this->postJson("/api/v1/leave/{$leave['id']}/submit")->assertOk();

        // The manager's bell has it; HR's does not yet — nothing to act on.
        $this->assertSame(1, $this->alerts($manager, 'leave.submitted'));
        $this->assertSame(0, $this->alerts($hr, 'leave.submitted'));

        $this->become($manager);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 1);
        $this->postJson("/api/v1/leave/{$leave['id']}/approve", ['remarks' => 'OK from me'])->assertOk();

        // Now HR is alerted, pointing at the request; the employee hears progress.
        $alert = InAppNotification::query()->where('user_id', $hr->id)->where('type', 'leave.submitted')->sole();
        $this->assertSame('/leave/'.$leave['id'], $alert->data['route']);
        $this->assertSame(1, $this->alerts($employee->user, 'leave.progress'));

        $this->become($hr);
        $this->postJson("/api/v1/leave/{$leave['id']}/approve", ['remarks' => 'Enjoy'])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // The employee hears the answer, in the app and by email.
        $this->assertSame(1, $this->alerts($employee->user, 'leave.approved'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('meera@example.test')
            && $mail->notification->type === 'leave.approved');
    }

    public function test_a_rejection_alerts_the_employee(): void
    {
        [, $employee] = $this->hrCreatesLogin();
        $employee->user->forceFill(['must_change_password' => false])->save();
        [$manager] = $this->makeSeat('Project Manager');

        $this->become($employee->user);
        $leave = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-05', '2026-10-06');
        $this->postJson("/api/v1/leave/{$leave['id']}/submit")->assertOk();

        $this->become($manager);
        $this->postJson("/api/v1/leave/{$leave['id']}/reject", ['remarks' => 'Site handover that week'])->assertOk();

        $this->assertSame(1, $this->alerts($employee->user, 'leave.rejected'));
    }

    private function alerts(User $user, string $type): int
    {
        return InAppNotification::query()->where('user_id', $user->id)->where('type', $type)->count();
    }

    public function test_email_copies_can_be_switched_off(): void
    {
        Setting::query()->where('key', 'notifications.email_enabled')->update(['value' => '0']);
        app(SettingsService::class)->refresh();

        Queue::fake();

        [$hr, $employee] = $this->hrCreatesLogin();
        $employee->user->forceFill(['must_change_password' => false])->save();
        $this->become($employee->user);
        $leave = $this->draftLeave($this->leaveTypeId('AL'), '2026-10-05', '2026-10-06');
        $this->postJson("/api/v1/leave/{$leave['id']}/submit")->assertOk();

        Queue::assertNotPushed(SendNotificationEmail::class);
    }
}
