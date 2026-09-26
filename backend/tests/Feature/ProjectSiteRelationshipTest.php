<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSiteRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_owns_many_sites(): void
    {
        $project = Project::factory()->create();
        Site::factory()->count(2)->create(['project_id' => $project->id]);

        $this->assertCount(2, $project->sites);
        $this->assertSame($project->id, $project->sites->first()->project_id);
    }

    public function test_site_requires_a_project_and_cascade_is_restrictive(): void
    {
        $site = Site::factory()->create();

        $this->expectException(QueryException::class);

        // Historical HR rows must not be destroyed by deleting a project.
        $site->project->forceDelete();
    }

    public function test_project_manager_is_an_employee(): void
    {
        $manager = Employee::factory()->create();
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $this->assertSame($manager->id, $project->project_manager_id);
        $this->assertSame($manager->id, $project->projectManager->id);

        // The same employee record is reachable from the project side too —
        // one shared model, not a duplicate relationship.
        $this->assertSame($manager->employee_code, $project->projectManager->employee_code);
    }

    public function test_soft_deleting_project_manager_keeps_the_reference_intact(): void
    {
        $manager = Employee::factory()->create();
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $manager->delete();

        // Soft delete only — the employee row still exists, so the project
        // keeps pointing at it and HR can restore them.
        $this->assertSoftDeleted($manager);
        $this->assertSame($manager->id, $project->fresh()->project_manager_id);
    }

    public function test_hard_deleting_project_manager_clears_the_reference(): void
    {
        $manager = Employee::factory()->create();
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $manager->forceDelete();

        $this->assertNull($project->fresh()->project_manager_id);
    }

    public function test_site_manager_and_supervisor_are_employees(): void
    {
        $site = Site::factory()->create();

        $this->assertInstanceOf(Employee::class, $site->siteManager);
        $this->assertInstanceOf(Employee::class, $site->siteSupervisor);
        $this->assertSame($site->site_manager_id, $site->siteManager->id);
        $this->assertSame($site->site_supervisor_id, $site->siteSupervisor->id);
    }

    public function test_site_belongs_to_a_shift(): void
    {
        $shift = Shift::factory()->create();
        $site = Site::factory()->create(['shift_id' => $shift->id]);

        $this->assertSame($shift->id, $site->shift_id);
        $this->assertTrue($site->shift->sites->contains($site));
    }

    public function test_site_working_hours_reference_a_setting_not_a_constant(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'working_hours.night_site',
            'type' => 'json',
            'group' => 'working_hours',
            'label' => 'Night site hours',
            'value' => '{"start":"22:00","end":"06:00","daily_hours":8}',
        ]);

        $site = Site::factory()->create(['working_hours_setting_id' => $setting->id]);

        $this->assertSame($setting->id, $site->working_hours_setting_id);
        $this->assertSame(8, $site->workingHoursSetting->castValue($setting->value)['daily_hours']);
    }

    public function test_site_without_working_hours_setting_falls_back_to_default_setting(): void
    {
        $site = Site::factory()->create(['working_hours_setting_id' => null]);

        $this->assertNull($site->working_hours_setting_id);
        $this->assertNull($site->workingHoursSetting);
    }

    /* ------------------------------------------------------- geofencing */

    public function test_geofence_accepts_point_inside_radius(): void
    {
        $site = Site::factory()->create([
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'geofence_radius' => 100,
        ]);

        // ~30m north of the centre.
        $this->assertTrue($site->withinGeofence(12.9718687, 77.5945627));
    }

    public function test_geofence_rejects_point_outside_radius(): void
    {
        $site = Site::factory()->create([
            'latitude' => 12.9715987,
            'longitude' => 77.5945627,
            'geofence_radius' => 100,
        ]);

        // ~1.1km away.
        $this->assertFalse($site->withinGeofence(12.9815987, 77.5945627));
    }

    public function test_geofence_without_coordinates_is_always_false(): void
    {
        $site = Site::factory()->withoutGeofence()->create();

        $this->assertFalse($site->withinGeofence(12.9715987, 77.5945627));
    }

    public function test_geofence_radius_is_configurable_per_site_not_hardcoded(): void
    {
        $point = [12.9715987, 77.5945627];
        $offset = [12.9755987, 77.5945627];   // ~445m north

        $tight = Site::factory()->create([
            'latitude' => $point[0], 'longitude' => $point[1], 'geofence_radius' => 100,
        ]);
        $wide = Site::factory()->create([
            'latitude' => $point[0], 'longitude' => $point[1], 'geofence_radius' => 1000,
        ]);

        $this->assertFalse($tight->withinGeofence($offset[0], $offset[1]));
        $this->assertTrue($wide->withinGeofence($offset[0], $offset[1]));
    }

    /* --------------------------------------------------- status vocabularies */

    public function test_project_status_vocabulary_is_enforced(): void
    {
        $this->assertEqualsCanonicalizing(
            ['planned', 'active', 'on_hold', 'completed', 'cancelled'],
            Project::STATUSES,
        );

        foreach (Project::STATUSES as $status) {
            $this->assertSame(
                $status,
                Project::factory()->create(['status' => $status])->fresh()->status,
            );
        }
    }

    public function test_site_code_is_unique(): void
    {
        $site = Site::factory()->create();

        $this->expectException(QueryException::class);

        Site::factory()->create(['code' => $site->code]);
    }

    public function test_project_code_is_unique(): void
    {
        $project = Project::factory()->create();

        $this->expectException(QueryException::class);

        Project::factory()->create(['code' => $project->code]);
    }
}
