<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The default: attendance is a selfie and a GPS reading, taken wherever the
 * person is. No site, no assignment, no geofence. Sites belong to the Site
 * visit option of the field roles, and to duplicates being refused.
 */
class AttendanceAnywhereTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        Storage::fake(config('hrms.storage.private_disk', 'local'));

        $this->travelTo(Carbon::parse('2026-09-27 09:05:00'));
    }

    private function officeEmployee(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        Employee::factory()->create([
            'user_id' => $user->id,
            'employment_status' => Employee::STATUS_ACTIVE,
            'primary_site_id' => null,
        ]);

        return $user;
    }

    private function point(array $extra = []): array
    {
        return $extra + [
            'latitude' => 25.2048,
            'longitude' => 55.2708,
            'accuracy' => 12,
            'source' => 'online',
        ];
    }

    public function test_an_employee_with_no_site_checks_in_and_out_from_anywhere(): void
    {
        $user = $this->officeEmployee();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('data.can_check_in', true)
            ->assertJsonPath('data.requires_site', false);

        $this->post('/api/v1/attendance/check-in', $this->point([
            'selfie' => UploadedFile::fake()->image('me.jpg', 600, 600),
            'purpose' => 'Head office',
        ]), ['Accept' => 'application/json'])->assertCreated();

        $row = Attendance::query()->sole();
        $this->assertNull($row->site_id);
        $this->assertNotNull($row->check_in_selfie_path);
        $this->assertSame(25.2048, (float) $row->check_in_latitude);

        $this->travelTo(Carbon::parse('2026-09-27 18:00:00'));

        // Somewhere else entirely — still fine; the point is recorded.
        $this->postJson('/api/v1/attendance/check-out', $this->point(['latitude' => 24.4539, 'longitude' => 54.3773]))
            ->assertOk();

        $this->assertNotNull($row->fresh()->check_out_at);
        $this->assertSame(24.4539, (float) $row->fresh()->check_out_latitude);
    }

    public function test_a_named_site_is_recorded_but_not_enforced(): void
    {
        $user = $this->officeEmployee();
        $site = Site::factory()->for(Project::factory())->create(['status' => Site::STATUS_ACTIVE]);
        Sanctum::actingAs($user);

        // No assignment to this site and nowhere near it: still allowed.
        $this->post('/api/v1/attendance/check-in', $this->point([
            'selfie' => UploadedFile::fake()->image('me.jpg', 600, 600),
            'site_id' => $site->id,
            'latitude' => 0.5,
            'longitude' => 0.5,
        ]), ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame($site->id, (int) Attendance::query()->sole()->site_id);
    }

    public function test_an_inactive_employee_still_cannot_check_in(): void
    {
        $user = $this->officeEmployee();
        $user->employee->forceFill(['employment_status' => Employee::STATUS_TERMINATED])->save();
        Sanctum::actingAs($user);

        $this->post('/api/v1/attendance/check-in', $this->point([
            'selfie' => UploadedFile::fake()->image('me.jpg', 600, 600),
        ]), ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_second_project_manager_is_told_the_site_is_already_added(): void
    {
        $project = Project::factory()->create();
        Site::factory()->for($project)->create([
            'name' => 'Marina Tower',
            'code' => 'MT-1',
            'latitude' => 25.08,
            'longitude' => 55.14,
        ]);

        $pm = User::factory()->create();
        $pm->assignRole('Project Manager');
        Sanctum::actingAs($pm);

        $base = [
            'project_id' => $project->id,
            'code' => 'MT-2',
            'status' => Site::STATUS_ACTIVE,
            'geofence_radius' => 150,
        ];

        // Same name, different case and spacing.
        $this->postJson('/api/v1/sites', $base + ['name' => '  marina   tower ', 'latitude' => 1, 'longitude' => 1])
            ->assertStatus(422)
            ->assertJsonFragment(['This site is already added: Marina Tower (MT-1).']);

        // Different name, same spot (about 30 m away).
        $this->postJson('/api/v1/sites', $base + ['name' => 'Tower at Marina', 'latitude' => 25.0803, 'longitude' => 55.14])
            ->assertStatus(422)
            ->assertJsonFragment(['This site is already added: Marina Tower (MT-1).']);

        // Genuinely new.
        $this->postJson('/api/v1/sites', $base + ['name' => 'JLT Cluster D', 'latitude' => 25.07, 'longitude' => 55.15])
            ->assertCreated();
    }
}
