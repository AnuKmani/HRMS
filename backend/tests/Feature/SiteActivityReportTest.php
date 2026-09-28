<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Site;
use App\Models\SiteActivityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsSiteReports;
use Tests\TestCase;

/**
 * Site activity reports: whose account of a site-day it is, where it may be
 * filed from, and what happens to the photographs attached to it.
 *
 * Four properties are worth proving here, and each has its own way of being
 * forgotten:
 *
 *  - **authorship is the session.** `employee_id` in a payload has nowhere
 *    to go; if a test can set it, so can a client.
 *  - **place is the site, not the payload.** The project a report is filed
 *    under is the site's own project, checked before it is written.
 *  - **state moves once.** `draft -> submitted`, and a submitted report is
 *    frozen — 409, because the person is authorized and the record is not
 *    accepting edits.
 *  - **photographs are private.** Private disk, server-minted name, re-encoded
 *    so no camera or GPS block survives, and no path in any response.
 *
 * Dates are pinned to Monday 2026-09-28 by {@see BuildsSiteReports}.
 */
class SiteActivityReportTest extends TestCase
{
    use BuildsSiteReports, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSiteReports();

        Storage::fake('local');
        Storage::fake('public');
    }

    /* -------------------------------------------------------- authorship */

    public function test_the_report_belongs_to_the_session_and_ignores_the_ids_the_client_sent(): void
    {
        $world = $this->world('Employee');

        $response = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            // Three columns a client would love to write, none of which are
            // in StoreSiteActivityReportRequest's rules.
            'employee_id' => 999_999,
            'status' => SiteActivityReport::STATUS_SUBMITTED,
            'submitted_at' => '2026-09-28T09:00:00Z',
        ]));

        $response->assertCreated();

        $this->assertSame($world['employee']->id, $response->json('data.employee_id'));
        $this->assertSame(SiteActivityReport::STATUS_DRAFT, $response->json('data.status'));
        $this->assertNull($response->json('data.submitted_at'));

        $this->assertSame(1, SiteActivityReport::query()->count());
    }

    public function test_the_site_decides_the_project_and_a_mismatch_is_refused(): void
    {
        $world = $this->world('Employee');
        $elsewhere = Project::factory()->create();

        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'project_id' => $elsewhere->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, SiteActivityReport::query()->count());

        // Even a *correct* pair stored from the site: the row's project is
        // the site's project, so the two cannot drift apart later.
        $id = $this->postJson(
            '/api/v1/site-activity-reports',
            $this->activityPayload($world['site']),
        )->assertCreated()->json('data.id');

        $this->assertSame(
            $world['site']->project_id,
            SiteActivityReport::query()->findOrFail($id)->project_id,
        );
    }

    public function test_the_described_work_is_bounded_before_it_is_stored(): void
    {
        $world = $this->world('Employee');

        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'progress_percentage' => 101,
        ]))->assertStatus(422)->assertJsonValidationErrors(['progress_percentage']);

        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'work_category' => str_repeat('Civil', 20),
        ]))->assertStatus(422)->assertJsonValidationErrors(['work_category']);

        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'work_performed' => '',
        ]))->assertStatus(422)->assertJsonValidationErrors(['work_performed']);

        $this->assertSame(0, SiteActivityReport::query()->count());
    }

    /* --------------------------------------------------------------- GPS */

    public function test_a_draft_may_be_written_with_no_fix_but_a_submission_may_not(): void
    {
        $world = $this->world('Employee');

        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'latitude' => null,
            'longitude' => null,
            'gps_accuracy' => null,
        ]))->assertCreated()->json('data.id');

        $this->assertFalse($this->getJson('/api/v1/site-activity-reports/'.$id)
            ->assertOk()
            ->json('data.has_gps_fix'));

        $this->postJson('/api/v1/site-activity-reports/'.$id.'/submit', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude']);
    }

    public function test_a_submission_carries_the_fix_it_was_made_with_and_freezes_the_report(): void
    {
        $world = $this->world('Employee');

        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'latitude' => null,
            'longitude' => null,
            'gps_accuracy' => null,
        ]))->assertCreated()->json('data.id');

        $submitted = $this->postJson('/api/v1/site-activity-reports/'.$id.'/submit', [
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'gps_accuracy' => 6.5,
        ])->assertOk();

        $this->assertSame(SiteActivityReport::STATUS_SUBMITTED, $submitted->json('data.status'));
        $this->assertTrue($submitted->json('data.has_gps_fix'));
        $this->assertNotNull($submitted->json('data.submitted_at'));
        $this->assertFalse($submitted->json('data.is_editable'));

        // Frozen. 409 rather than 403: this person plainly *may* edit, the
        // record is simply no longer accepting edits.
        $this->putJson('/api/v1/site-activity-reports/'.$id, [
            'remarks' => 'One more thing',
        ])->assertStatus(409);

        $this->postJson('/api/v1/site-activity-reports/'.$id.'/submit', [
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'gps_accuracy' => 6.5,
        ])->assertStatus(409);
    }

    public function test_half_a_fix_and_an_unbelievable_fix_are_both_refused(): void
    {
        $world = $this->world('Employee');

        // Latitude alone is a line, not a place.
        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'longitude' => null,
            'gps_accuracy' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors(['gps_accuracy']);

        // (0, 0) is inside both ranges and is what an uninitialised receiver
        // reports. It is not where anybody stands.
        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'latitude' => 0,
            'longitude' => 0,
        ]))->assertStatus(422)->assertJsonValidationErrors(['latitude']);

        // The ceiling is attendance's own config value, not a second one.
        config(['hrms.attendance.max_gps_accuracy_metres' => 50]);
        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'gps_accuracy' => 60,
        ]))->assertStatus(422)->assertJsonValidationErrors(['gps_accuracy']);

        $this->assertSame(0, SiteActivityReport::query()->count());
    }

    /* ------------------------------------------------------ authorization */

    public function test_a_person_may_only_file_where_they_are_placed(): void
    {
        $world = $this->world('Employee');
        $elsewhere = Site::factory()->create();

        $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($elsewhere))
            ->assertStatus(403)
            ->assertJsonPath('message', 'You can only file a report for a site you are assigned to.');

        $this->assertSame(0, SiteActivityReport::query()->count());
    }

    public function test_an_employee_reads_only_their_own_reports(): void
    {
        $mine = $this->world('Employee');
        $mineId = $this->postJson(
            '/api/v1/site-activity-reports',
            $this->activityPayload($mine['site']),
        )->assertCreated()->json('data.id');

        $theirs = $this->world('Employee');
        $theirId = $this->postJson(
            '/api/v1/site-activity-reports',
            $this->activityPayload($theirs['site']),
        )->assertCreated()->json('data.id');

        // Their own list holds their own report and nothing else, even
        // though both accounts hold `site_activity_reports.view`.
        $items = $this->getJson('/api/v1/site-activity-reports')->assertOk()->json('data.items');
        $this->assertSame([$theirId], array_column($items, 'id'));

        $this->getJson('/api/v1/site-activity-reports/'.$mineId)->assertStatus(403);
        $this->putJson('/api/v1/site-activity-reports/'.$mineId, ['remarks' => 'peek'])->assertStatus(403);

        // A narrowing filter does not become a way through the scope: naming
        // somebody else's employee id returns nothing, not their rows.
        $this->getJson('/api/v1/site-activity-reports?employee_id='.$mine['employee']->id)
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_a_supervisor_reads_the_reports_of_the_sites_they_run(): void
    {
        $world = $this->world('Site Supervisor');
        [$reporter, $reporterEmployee] = $this->colleagueOn('Employee', $world['site']);

        $this->become($reporter);
        $filed = $this->postJson(
            '/api/v1/site-activity-reports',
            $this->activityPayload($world['site']),
        )->assertCreated()->json('data.id');

        $this->become($world['user']);
        $items = $this->getJson('/api/v1/site-activity-reports')->assertOk()->json('data.items');

        $this->assertCount(1, $items, 'The supervisor has filed none, but may read what is filed at their site.');
        $this->assertSame($reporterEmployee->id, $items[0]['employee_id']);
        $this->getJson('/api/v1/site-activity-reports/'.$filed)->assertOk();
    }

    public function test_an_account_without_the_permission_cannot_open_the_module_at_all(): void
    {
        $this->signInAs('Finance');

        $this->getJson('/api/v1/site-activity-reports')->assertStatus(403);
        $this->postJson('/api/v1/site-activity-reports', [])->assertStatus(403);
    }

    /* ------------------------------------------------------------- picker */

    public function test_the_site_picker_answers_which_sites_may_be_reported_about(): void
    {
        $world = $this->world('Employee');

        // The reason this endpoint exists: a field worker holds no
        // `sites.view`, so the ordinary directory is closed to exactly the
        // role the field-reporting form is built for.
        $this->getJson('/api/v1/sites')->assertStatus(403);

        $items = $this->getJson('/api/v1/site-activity-reports/reportable-sites')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame($world['site']->id, $items[0]['id']);
        $this->assertSame($world['project']->id, $items[0]['project_id']);

        // A site nobody placed them on is not offered — and offering it
        // would only trade a 403 at the picker for a 403 at the save.
        Site::factory()->create();

        $this->assertCount(
            1,
            $this->getJson('/api/v1/site-activity-reports/reportable-sites')
                ->assertOk()
                ->json('data.items'),
        );
    }

    /* -------------------------------------------------------------- lists */

    public function test_the_list_filters_by_date_range_status_and_site(): void
    {
        $world = $this->world('Site Supervisor');

        $today = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $yesterday = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site'], [
            'report_date' => '2026-09-27',
        ]))->assertCreated()->json('data.id');

        $this->postJson('/api/v1/site-activity-reports/'.$today.'/submit', [
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'gps_accuracy' => 6.5,
        ])->assertOk();

        $ids = fn (string $query) => array_column(
            $this->getJson('/api/v1/site-activity-reports'.$query)->assertOk()->json('data.items'),
            'id',
        );

        $this->assertEqualsCanonicalizing([$today, $yesterday], $ids(''));
        $this->assertSame([$today], $ids('?status=submitted'));
        $this->assertEqualsCanonicalizing([$today, $yesterday], $ids('?from=2026-09-27&to=2026-09-28'));
        $this->assertSame([$yesterday], $ids('?to=2026-09-27'));
        $this->assertSame([$today], $ids('?site_id='.$world['site']->id.'&status=submitted'));
        $this->assertSame([], $ids('?work_category=Electrical'));
    }

    /* ------------------------------------------------------------ photos */

    public function test_a_photograph_is_stored_privately_and_nothing_about_its_location_leaves(): void
    {
        $world = $this->world('Employee');

        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $response = $this->post('/api/v1/site-activity-reports/'.$id.'/photos', [
            'photos' => [$this->photo('a.jpg'), $this->photo('b.jpg')],
            'caption' => 'Rebar before the pour',
        ]);

        $response->assertCreated();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('Rebar before the pour', $response->json('data.0.caption'));

        // Private disk, under a folder the report owns, under names the
        // client had no hand in.
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression(
                '#^site-report-photos/activity/'.$id.'/[0-9a-f-]{36}\.jpg$#',
                $file,
            );
        }

        // Not one byte of a path, a directory name or a URL in either the
        // upload response or the report itself.
        $detail = $this->getJson('/api/v1/site-activity-reports/'.$id)->assertOk();

        $this->assertStringNotContainsString('site-report-photos', (string) $detail->getContent());
        $this->assertStringNotContainsString('"path"', (string) $detail->getContent());

        $photo = $detail->json('data.photos.0');
        $this->assertSame(['id', 'caption', 'sort_order', 'mime_type', 'size_bytes', 'created_at'], array_keys($photo));

        // The bytes are reachable, and only through the report's own route.
        $bytes = $this->get('/api/v1/site-activity-reports/'.$id.'/photos/'.$photo['id']);

        $bytes->assertOk();
        $this->assertStringContainsString('image/jpeg', (string) $bytes->headers->get('content-type'));
        $this->assertStringContainsString(
            'no-store',
            strtolower((string) $bytes->headers->get('cache-control')),
        );
        $this->assertStringStartsWith("\xFF\xD8", (string) $bytes->streamedContent());
    }

    public function test_a_photograph_is_re_encoded_so_no_camera_or_gps_metadata_survives(): void
    {
        $world = $this->world('Employee');
        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $original = $this->withExif($this->jpegBytes());

        $this->assertStringContainsString('HRMS_EXIF_MARKER', $original);

        $this->post('/api/v1/site-activity-reports/'.$id.'/photos', [
            'photos' => [UploadedFile::fake()->createWithContent('site.jpg', $original)],
        ])->assertCreated();

        $stored = Storage::disk('local')->get(Storage::disk('local')->allFiles()[0]);

        $this->assertNotSame($original, $stored, 'The upload was stored verbatim.');
        $this->assertStringStartsWith("\xFF\xD8", (string) $stored);
        $this->assertStringNotContainsString('HRMS_EXIF_MARKER', (string) $stored);
        $this->assertStringNotContainsString('28.6138,77.2090', (string) $stored);
        $this->assertStringNotContainsString('TestCamera', (string) $stored);
        $this->assertStringNotContainsString('Exif', (string) $stored);
    }

    public function test_a_file_that_is_not_a_photograph_is_refused_and_nothing_is_written(): void
    {
        $world = $this->world('Employee');
        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $this->post('/api/v1/site-activity-reports/'.$id.'/photos', [
            'photos' => [UploadedFile::fake()->create('notes.txt', 4, 'text/plain')],
        ])->assertStatus(422)->assertJsonValidationErrors(['photos.0']);

        // A plausible name over bytes that are not a photograph at all.
        $this->post('/api/v1/site-activity-reports/'.$id.'/photos', [
            'photos' => [UploadedFile::fake()->createWithContent('site.jpg', '<?php system($_GET["c"]);')],
        ])->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $this->getJson('/api/v1/site-activity-reports/'.$id)
            ->assertOk()
            ->json('data.photo_count'));
    }

    public function test_photographs_stop_changing_once_the_report_is_submitted(): void
    {
        $world = $this->world('Employee');
        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $photoId = $this->post('/api/v1/site-activity-reports/'.$id.'/photos', [
            'photos' => [$this->photo()],
        ])->assertCreated()->json('data.0.id');

        $this->postJson('/api/v1/site-activity-reports/'.$id.'/submit', [
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'gps_accuracy' => 6.5,
        ])->assertOk();

        $this->post('/api/v1/site-activity-reports/'.$id.'/photos', ['photos' => [$this->photo()]])
            ->assertStatus(409);
        $this->deleteJson('/api/v1/site-activity-reports/'.$id.'/photos/'.$photoId)
            ->assertStatus(409);

        // Still there: the refusal did not take the evidence with it.
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get('/api/v1/site-activity-reports/'.$id.'/photos/'.$photoId)->assertOk();
    }

    public function test_a_photograph_can_only_be_touched_through_its_own_report(): void
    {
        $world = $this->world('Employee');
        $first = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $photoOfSecond = $this->post('/api/v1/site-activity-reports/'.$second.'/photos', [
            'photos' => [$this->photo()],
        ])->assertCreated()->json('data.0.id');

        // Implicit binding resolves both ids independently, so without a
        // check a caller could name their own report and somebody else's
        // photograph id.
        $this->deleteJson('/api/v1/site-activity-reports/'.$first.'/photos/'.$photoOfSecond)
            ->assertStatus(404);
        $this->get('/api/v1/site-activity-reports/'.$first.'/photos/'.$photoOfSecond)
            ->assertStatus(404);

        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1, SiteActivityReport::query()->findOrFail($second)->photos()->count());
    }

    public function test_a_report_cannot_hold_more_than_twelve_photographs(): void
    {
        $world = $this->world('Employee');
        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $report = SiteActivityReport::query()->findOrFail($id);

        for ($i = 0; $i < 12; $i++) {
            $report->photos()->create([
                'path' => 'site-report-photos/activity/'.$id.'/'.$i.'.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1000,
                'sort_order' => $i,
            ]);
        }

        $this->post('/api/v1/site-activity-reports/'.$id.'/photos', ['photos' => [$this->photo()]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos']);

        $this->assertSame(12, $report->photos()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_empty_photo_batch_is_refused(): void
    {
        $world = $this->world('Employee');
        $id = $this->postJson('/api/v1/site-activity-reports', $this->activityPayload($world['site']))
            ->assertCreated()->json('data.id');

        $this->post('/api/v1/site-activity-reports/'.$id.'/photos', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos']);
    }
}
