<?php

namespace Tests\Feature;

use App\Jobs\BuildReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reporting\ReportExporter;
use App\Services\Reporting\ReportFilters;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/reports, GET /api/v1/reports/{key}, both export routes, and
 * the two gates around them.
 *
 * The gates are not the same gate. The route's `permission:reports.view`
 * answers "may this account open the reporting screen", and
 * `ReportService::definition()` re-asks the *per-report* permission after
 * the key has come out of the path — so a key typed into a URL cannot reach
 * a report the role does not hold. One gate on the door and a second on
 * each room, which is the only arrangement where a catalogue of thirteen
 * reports is also a set of thirteen authorisation decisions.
 *
 * The other rule under test is that a filter the report cannot answer is a
 * 422 rather than a shrug. Silently dropping `site_id` would hand back
 * every asset while the URL claimed to be showing the ones at Dubai Yard.
 */
class ReportingTest extends TestCase
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

    /* ------------------------------------------------------- the doors */

    public function test_reporting_is_neither_public_nor_reachable_without_the_permission(): void
    {
        $this->getJson('/api/v1/reports')->assertUnauthorized();

        $user = User::factory()->create(); // no role, so no permissions

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports')->assertForbidden();
        $this->getJson('/api/v1/reports/employees.directory')->assertForbidden();
    }

    public function test_the_catalogue_lists_only_reports_this_role_may_run(): void
    {
        $this->actingAsRole('HR Admin');

        $catalogue = $this->getJson('/api/v1/reports')->assertOk();

        $keys = array_column($catalogue->json('data.items'), 'key');

        // The six filters are advertised up front so a client never offers
        // a control the data cannot answer.
        foreach (['from', 'to', 'site_id', 'department_id', 'employee_id', 'status'] as $filter) {
            $this->assertContains($filter, $catalogue->json('data.filters'));
        }

        $this->assertContains('employees.directory', $keys);
        $this->assertContains('leave.register', $keys);
        $this->assertContains('payroll.register', $keys);

        // Site Engineers may open the reporting screen but not the payroll
        // room; the catalogue must not map the company for them either.
        Sanctum::actingAs(User::factory()->create()->assignRole('Site Engineer'));

        $engineerKeys = array_column(
            $this->getJson('/api/v1/reports')->assertOk()->json('data.items'),
            'key',
        );

        $this->assertContains('sites.status', $engineerKeys);
        $this->assertNotContains('payroll.register', $engineerKeys);
    }

    public function test_the_per_report_permission_stops_a_key_typed_into_the_url(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Site Engineer'); // reports.view, not payroll.view

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/payroll.register')
            ->assertForbidden();

        $this->getJson('/api/v1/reports/payroll.register/export?format=csv')
            ->assertForbidden();
    }

    public function test_an_unknown_report_key_is_a_404(): void
    {
        $this->actingAsRole('HR Admin');

        $this->getJson('/api/v1/reports/not.a.report')->assertNotFound();
    }

    /* -------------------------------------------------------- running */

    public function test_a_report_returns_rows_and_the_four_numbers_of_a_page(): void
    {
        $this->actingAsRole('HR Admin');

        $response = $this->getJson('/api/v1/reports/employees.directory?per_page=10')
            ->assertOk();

        $meta = $response->json('data.meta');

        $this->assertSame(['current_page', 'last_page', 'per_page', 'total', 'has_next'], array_keys($meta));
        $this->assertSame(1, $response->json('data.meta.current_page'));
        $this->assertSame(10, $response->json('data.meta.per_page'));
        $this->assertIsArray($response->json('data.items'));
    }

    public function test_the_six_filters_are_the_report_s_own_list(): void
    {
        $this->actingAsRole('HR Admin');

        $report = $this->getJson('/api/v1/reports/employees.directory')
            ->assertOk()
            ->json('data.report');

        $supported = $report['supported_filters'];

        $this->assertIsArray($supported);
        $this->assertNotEmpty($supported);

        // Whatever a report does not support it says so about — the
        // catalogue is the contract a filter screen is built against, so
        // nothing may appear here that is not one of the six.
        foreach ($supported as $filter) {
            $this->assertContains($filter, ReportFilters::ALL);
        }
    }

    public function test_a_filter_the_report_cannot_answer_is_a_422_not_a_silent_drop(): void
    {
        $this->actingAsRole('HR Admin');

        // An asset is not *at* a site, it is assigned to a person, so the
        // register has no site to filter on — and quietly ignoring the
        // parameter would show every asset while the URL claimed Dubai Yard.
        $this->getJson('/api/v1/reports/assets.register?site_id=5')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_a_filter_value_that_does_not_parse_is_a_422_too(): void
    {
        $this->actingAsRole('HR Admin');

        // Dropping it would widen the report from what was asked, which is
        // the same lie an unsupported column tells, just quieter.
        $this->getJson('/api/v1/reports/employees.directory?from=last-tuesday')
            ->assertStatus(422);
    }

    /* ------------------------------------------------------- exporting */

    public function test_the_small_export_streams_a_file_on_the_request_thread(): void
    {
        $this->actingAsRole('HR Admin');

        $response = $this->get('/api/v1/reports/employees.directory/export?format=csv');

        $response->assertOk();

        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            'text/csv',
            (string) $response->headers->get('Content-Type'),
        );

        $streamed = $response->streamedContent();

        // A header row and a trailing newline; with zero seeded employees
        // the body may well be only the header, which is still a complete
        // and openable file rather than a 200 with nothing behind it.
        $this->assertStringContainsString(',', $streamed);
        $this->assertNotSame('', trim($streamed));
    }

    public function test_a_queued_export_is_accepted_not_built_in_the_response(): void
    {
        $user = $this->actingAsRole('HR Admin');

        Queue::fake();

        $this->postJson('/api/v1/reports/employees.directory/exports', [
            'format' => 'csv',
        ])
            ->assertCreated()
            ->assertJsonPath('data.report_key', 'employees.directory')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.ready', false);

        Queue::assertPushed(BuildReportExport::class);

        // The row is the user's, and the job is handed only its id — the
        // file is built off the request thread and re-checks the permission
        // when it gets there.
        $this->assertDatabaseHas('report_exports', [
            'report_key' => 'employees.directory',
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_an_unsupported_export_format_is_a_422(): void
    {
        $this->actingAsRole('HR Admin');

        $this->getJson('/api/v1/reports/employees.directory/export?format=docx')
            ->assertStatus(422);
    }

    public function test_the_export_history_is_scoped_to_the_caller(): void
    {
        $mine = $this->actingAsRole('HR Admin');
        $other = User::factory()->create();

        Queue::fake();

        $this->postJson('/api/v1/reports/employees.directory/exports', [
            'format' => 'csv',
        ])->assertCreated();

        $exportId = (int) $this->getJson('/api/v1/report-exports')
            ->assertOk()
            ->json('data.items.0.id');

        // Someone else's file is not theirs to download, and a 404 says so
        // without confirming that an export with that id exists at all.
        // Give them reports.view so the index is reachable, but they own no exports.
        $other->assignRole('Site Engineer');
        Sanctum::actingAs($other);

        $this->getJson('/api/v1/report-exports')->assertOk()->assertJsonPath('data.items', []);
        $this->getJson("/api/v1/report-exports/{$exportId}/file")->assertNotFound();

        // And the resource never carries the path it lives at.
        Sanctum::actingAs($mine);

        $body = $this->getJson('/api/v1/report-exports')->getContent();

        $this->assertStringNotContainsString('storage/', $body);
        $this->assertStringNotContainsString('"path"', $body);
    }

    public function test_a_finished_export_file_is_streamable_by_its_owner(): void
    {
        $user = $this->actingAsRole('HR Admin');

        $this->postJson('/api/v1/reports/employees.directory/exports', [
            'format' => 'csv',
        ])->assertCreated();

        // `QUEUE_CONNECTION=sync` in phpunit.xml would have run it inline
        // had the queue not been faked; faking it first lets this assert the
        // *pending* state above and this one run the job by hand, which is
        // the sequence production follows anyway.
        $exportId = ReportExport::query()->value('id');

        (new BuildReportExport($exportId))->handle(app(ReportExporter::class));

        $this->get("/api/v1/report-exports/{$exportId}/file")
            ->assertOk()
            ->assertHeader('Content-Disposition');
    }
}
