<?php

namespace Tests\Feature\Concerns;

use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * The Phase 7 furniture: four seeders (roles, permissions, the grants that
 * join them, and the settings the PDF reads) and the smallest world a site
 * report can be about — one project, one site, one person posted to it.
 *
 * Deliberately *not* `BuildsLeaveStack`. That trait seeds leave types and
 * approval workflows, neither of which a site report has any opinion about,
 * and a Phase 7 test that seeded them would be asserting nothing while
 * paying for six tables' worth of setup.
 *
 * Dates are pinned to Monday 2026-09-28, the same anchor the Phase 6 tests
 * reason from, so "the report for today" has one answer for the whole suite
 * and a `report_date` of `before_or_equal:today` cannot start failing in
 * January.
 */
trait BuildsSiteReports
{
    use SignsInAccounts;

    /**
     * The fixed Monday this phase reasons from.
     */
    private const ANCHOR = '2026-09-28';

    protected function seedSiteReports(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->travelTo(Carbon::parse(self::ANCHOR.' 09:00:00'));
    }

    /**
     * The smallest world a report can be about: a project, one of its
     * sites, an employee standing on that site, and the account behind
     * them — signed in and ready to file.
     *
     * Two routes into `mayReportAt()` are wired rather than one, because
     * tests need both and a builder that only ever proved the first would
     * let a regression in the second pass unnoticed:
     *
     *  - `primary_site_id` (and a matching active assignment) puts the site
     *    in `attachedSiteIds()` — the Employee/Supervisor door;
     *  - `project_manager_id` on the project puts it in
     *    `scopedProjectIds()` when the role is Project Manager — the
     *    managed-project door.
     *
     * @param  array<string, mixed>  $siteOverrides
     * @param  array<string, mixed>  $employeeOverrides
     * @return array{project: Project, site: Site, employee: Employee, user: User}
     */
    protected function world(
        string $role = 'Employee',
        array $siteOverrides = [],
        array $employeeOverrides = [],
    ): array {
        $project = Project::factory()->create();

        $site = Site::factory()->create($siteOverrides + [
            'project_id' => $project->id,
            'site_manager_id' => null,
            'site_supervisor_id' => null,
        ]);

        $employee = Employee::factory()->create($employeeOverrides + [
            'employment_status' => Employee::STATUS_ACTIVE,
            'primary_project_id' => $project->id,
            'primary_site_id' => $site->id,
        ]);

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'assignment_type' => EmployeeSiteAssignment::TYPE_PRIMARY,
            'start_date' => '2026-09-01',
            'end_date' => null,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        if ($role === 'Project Manager') {
            $project->update(['project_manager_id' => $employee->id]);
        }

        $user = User::factory()->create();
        $user->assignRole($role);
        $employee->update(['user_id' => $user->id]);

        $this->become($user);

        return [
            'project' => $project,
            'site' => $site,
            'employee' => $employee,
            'user' => $user,
        ];
    }

    /**
     * Post a *second* employee onto the same site, without touching the
     * first one's primary posting — the fixture for "is a colleague's
     * report visible to me?".
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Employee}
     */
    protected function colleagueOn(string $role, Site $site, array $overrides = []): array
    {
        [$user, $model] = $this->makeSeat($role, $overrides + [
            'employment_status' => Employee::STATUS_ACTIVE,
            'primary_project_id' => $site->project_id,
            'primary_site_id' => $site->id,
        ]);

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $model->id,
            'project_id' => $site->project_id,
            'site_id' => $site->id,
            'assignment_type' => EmployeeSiteAssignment::TYPE_ADDITIONAL,
            'start_date' => '2026-09-01',
            'end_date' => null,
            'status' => EmployeeSiteAssignment::STATUS_ACTIVE,
        ]);

        return [$user, $model];
    }

    /**
     * A valid activity-report body for [Site::id], so a test can spend its
     * lines on the one field it is actually exercising.
     *
     * GPS is on by default: a report without one is the *interesting* case
     * for submit, so tests drop it with an explicit override rather than
     * every other test having to add it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function activityPayload(Site $site, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $site->project_id,
            'site_id' => $site->id,
            'report_date' => self::ANCHOR,

            'work_category' => 'Civil — slab',
            'work_performed' => 'Shuttering and reinforcement for the second slab, grids B1 to B4.',
            'progress_percentage' => 65,

            'manpower' => '12 masons, 8 labourers, 3 electricians',
            'materials_used' => 'Cement 40 bags, aggregate 6 tonnes',
            'equipment_used' => 'Tower crane, concrete pump',

            'issues' => null,
            'safety_issues' => null,
            'remarks' => null,

            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'gps_accuracy' => 8.5,
        ], $overrides);
    }

    /**
     * A valid daily-report body, with child rows so the derived total and
     * the four child tables are all exercised by default.
     *
     * `total_manpower` is sent as 0 on purpose: the rows below add up to 18,
     * so a service that believed the client's number would store 0 and the
     * next assertion would say so immediately.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function dailyPayload(Site $site, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $site->project_id,
            'site_id' => $site->id,
            'report_date' => self::ANCHOR,

            'total_manpower' => 0,
            'manpower' => [
                ['category' => 'Masons', 'count' => 12],
                ['category' => 'Electricians', 'count' => 6],
            ],

            'work_planned' => 'Slab reinforcement and shuttering on grids B1 to B4.',
            'work_completed' => 'Reinforcement tied for B1 to B4; shuttering started on B1.',

            'materials' => [
                ['material_name' => 'Cement', 'quantity' => 40, 'unit' => 'bags', 'remarks' => 'OPC 53'],
                ['material_name' => 'Aggregate', 'quantity' => 6.5, 'unit' => 'tonnes'],
            ],

            'equipment' => [
                ['equipment_name' => 'Tower crane', 'quantity' => 1, 'operating_hours' => 7.5, 'condition' => 'Good'],
                ['equipment_name' => 'Concrete pump', 'quantity' => 1, 'operating_hours' => null, 'condition' => 'Idle'],
            ],

            'safety_observations' => 'All workers wearing helmets; edge protection inspected at 08:00.',
            'delays' => null,
            'issues' => null,
            'remarks' => null,
        ], $overrides);
    }

    /**
     * A real, decodable JPEG of the given size — 1x1 unless asked
     * otherwise — rather than a file whose *name* ends in `.jpg`.
     *
     * `UploadedFile::fake()->image()` needs GD and writes a genuine image,
     * which is what makes it useful here: the request's `ImageContent` rule
     * and ReportPhotoStore's sanitizer both read the bytes, and neither can
     * be satisfied by a filename.
     */
    protected function photo(string $name = 'site.jpg', int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /**
     * The bytes of a plain, metadata-free JPEG — a starting point to attach
     * metadata to, since PHP can write pixels but not an EXIF block.
     */
    protected function jpegBytes(int $width = 800, int $height = 600): string
    {
        $source = UploadedFile::fake()->image('site.jpg', $width, $height);

        return (string) file_get_contents($source->getRealPath());
    }

    /**
     * Splice a well-formed APP1 (EXIF) segment immediately after SOI, seeded
     * with strings that would be conspicuous in any photograph: a GPS fix, a
     * make, a model. The TIFF directory inside is deliberately not valid —
     * the assertion is that metadata does not survive re-encoding, not that
     * a particular parser can read it.
     */
    protected function withExif(string $jpeg): string
    {
        $payload = 'Exif'."\0\0"
            .'GPS:28.6138,77.2090;Make=TestCamera;Model=PixelTest;HRMS_EXIF_MARKER';

        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}
