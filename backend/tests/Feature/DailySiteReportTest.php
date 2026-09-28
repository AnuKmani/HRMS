<?php

namespace Tests\Feature;

use App\Models\DailySiteReport;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsSiteReports;
use Tests\TestCase;

/**
 * Daily site reports: the official, unique-per-site-per-date record, the
 * rows that make it readable, and who may write one.
 *
 * The three properties worth proving:
 *
 *  - **there is exactly one.** Asked as validation so the answer is a field
 *    error, and enforced by an index so the answer survives two requests
 *    arriving together. Both are asserted here, because only one of them
 *    can be tested without a race.
 *  - **the headline number is derived.** `total_manpower` is the sum of the
 *    categories, not a number the client is free to disagree with — a PDF
 *    that printed a total nobody could reproduce is the failure mode.
 *  - **an Employee does not write this.** The whole company cannot be
 *    preparing the official site-day: the permission simply is not granted,
 *    and the route says so before any rule runs.
 *
 * Dates are pinned to Monday 2026-09-28 by {@see BuildsSiteReports}.
 */
class DailySiteReportTest extends TestCase
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

    public function test_the_document_is_authored_by_the_session_and_never_by_the_payload(): void
    {
        $world = $this->world('Site Supervisor');

        $response = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], [
            'created_by' => 999_999,
            'status' => DailySiteReport::STATUS_SUBMITTED,
            'submitted_at' => '2026-09-28T09:00:00Z',
            'approved_at' => '2026-09-28T09:00:00Z',
        ]));

        $response->assertCreated();

        $this->assertSame($world['user']->id, $response->json('data.created_by'));
        $this->assertSame(DailySiteReport::STATUS_DRAFT, $response->json('data.status'));
        $this->assertNull($response->json('data.approved_at'));
        $this->assertNull($response->json('data.submitted_at'));

        // The four state-bearing columns, none of which any payload can
        // reach, are the ones the service owns outright.
        $row = DailySiteReport::query()->findOrFail($response->json('data.id'));
        $this->assertSame($world['user']->id, $row->created_by);
        $this->assertNull($row->approved_at);
    }

    public function test_the_site_decides_the_project_and_a_mismatch_is_refused(): void
    {
        $world = $this->world('Site Supervisor');
        $elsewhere = Site::factory()->create(['site_supervisor_id' => $world['employee']->id]);

        $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], [
            'project_id' => $elsewhere->project_id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['project_id']);

        $this->assertSame(0, DailySiteReport::query()->count());

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        $this->assertSame(
            $world['site']->project_id,
            DailySiteReport::query()->findOrFail($id)->project_id,
        );
    }

    /* --------------------------------------------------------- uniqueness */

    public function test_there_is_one_official_report_per_site_per_date(): void
    {
        $world = $this->world('Site Supervisor');

        $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated();

        // Asked as validation, so the answer names the field rather than
        // arriving as a 500 with a constraint name in it.
        $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['report_date']);

        // Another site, the same date, is a different document entirely.
        $other = Site::factory()->create(['site_supervisor_id' => $world['employee']->id]);
        $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($other))
            ->assertCreated();

        // And the same site on another day is another document too.
        $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], [
            'report_date' => '2026-09-27',
        ]))->assertCreated();

        $this->assertSame(3, DailySiteReport::query()->count());
    }

    public function test_the_database_itself_refuses_a_second_report_for_one_site_and_date(): void
    {
        $index = collect(Schema::getIndexes('daily_site_reports'))
            ->firstWhere('name', 'dsr_site_date_unique');

        $this->assertNotNull($index, 'The business rule is enforced where it cannot be argued with.');
        $this->assertTrue($index['unique']);

        $this->assertEqualsCanonicalizing(
            ['site_id', 'report_date'],
            $index['columns'],
            'The uniqueness is scoped to the site, not to the date alone.',
        );
    }

    /* ----------------------------------------------------------- children */

    public function test_the_total_head_count_is_the_sum_of_the_categories_and_not_a_number_the_client_picked(): void
    {
        $world = $this->world('Site Supervisor');

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], [
            // Declared as 0 on purpose: the rows below add to 18, so a
            // service that believed the client would store 0 and this
            // assertion would say so immediately.
            'total_manpower' => 0,
        ]))->assertCreated()->json('data.id');

        $this->assertSame(18, DailySiteReport::query()->findOrFail($id)->total_manpower);

        $rows = $this->getJson('/api/v1/daily-site-reports/'.$id)
            ->assertOk()
            ->json('data.manpower');

        $this->assertCount(2, $rows);
        $this->assertSame('Masons', $rows[0]['category']);
        $this->assertSame(12, (int) $rows[0]['count']);
        $this->assertSame('Electricians', $rows[1]['category']);
        $this->assertSame(6, (int) $rows[1]['count']);
    }

    public function test_a_manpower_category_is_a_word_the_site_uses_not_a_value_from_a_list(): void
    {
        $world = $this->world('Site Supervisor');

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], [
            'manpower' => [
                ['category' => 'Scaffolders', 'count' => 4],
                ['category' => 'HSE officers', 'count' => 2],
            ],
        ]))->assertCreated()->json('data.id');

        $this->assertSame(6, DailySiteReport::query()->findOrFail($id)->total_manpower);

        $categories = array_column($this->getJson('/api/v1/daily-site-reports/'.$id)
            ->assertOk()
            ->json('data.manpower'), 'category');

        $this->assertSame(['Scaffolders', 'HSE officers'], $categories);
    }

    public function test_a_row_that_names_no_category_is_refused_rather_than_written_blind(): void
    {
        $world = $this->world('Site Supervisor');

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        // `sometimes` skips a key that is absent, so relaxing the nested
        // rules on update would have let this through to a service that
        // would then read an index that is not there.
        $this->putJson('/api/v1/daily-site-reports/'.$id, [
            'manpower' => [['count' => 4]],
        ])->assertStatus(422)->assertJsonValidationErrors(['manpower.0.category']);

        $this->assertSame(18, DailySiteReport::query()->findOrFail($id)->total_manpower);
    }

    public function test_materials_and_equipment_are_rows_a_reader_can_ask_a_question_of(): void
    {
        $world = $this->world('Site Supervisor');

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        $data = $this->getJson('/api/v1/daily-site-reports/'.$id)
            ->assertOk()
            ->json('data');

        $this->assertSame('Cement', $data['materials'][0]['material_name']);
        $this->assertSame(40.0, (float) $data['materials'][0]['quantity']);
        $this->assertSame('bags', $data['materials'][0]['unit']);

        // Three tonnes and a half is a real quantity; a column that rounded
        // it to keep itself tidy would be a wrong number in a document.
        $this->assertSame(6.5, (float) $data['materials'][1]['quantity']);

        // A pump parked all day has no meter reading — nullable, not zero.
        $this->assertSame('Tower crane', $data['equipment'][0]['equipment_name']);
        $this->assertSame(7.5, (float) $data['equipment'][0]['operating_hours']);
        $this->assertNull($data['equipment'][1]['operating_hours']);
        $this->assertSame('Good', $data['equipment'][0]['condition']);

        // Nothing here looks like an inventory: no stock, no cost, no
        // supplier, no asset tag.
        $this->assertSame(
            ['id', 'equipment_name', 'quantity', 'operating_hours', 'condition', 'remarks', 'sort_order'],
            array_keys($data['equipment'][0]),
        );
    }

    public function test_a_child_set_the_payload_omitted_is_left_alone_and_one_it_sends_is_replaced(): void
    {
        $world = $this->world('Site Supervisor');

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        // A partial correction: only the delays changed, so only the delays
        // change — the rows and the derived total stay as they were.
        $this->putJson('/api/v1/daily-site-reports/'.$id, [
            'delays' => 'Rain from 14:00.',
        ])->assertOk();

        $report = DailySiteReport::query()->findOrFail($id);
        $this->assertSame('Rain from 14:00.', $report->delays);
        $this->assertSame(18, $report->total_manpower);
        $this->assertSame(2, $report->manpower()->count());
        $this->assertSame(2, $report->materials()->count());

        // An empty set is a statement: "this document now has none of
        // these", which is not the same as not mentioning them.
        $this->putJson('/api/v1/daily-site-reports/'.$id, [
            'manpower' => [],
            'materials' => [],
            'equipment' => [],
        ])->assertOk();

        $report->refresh();
        $this->assertSame(0, $report->total_manpower);
        $this->assertSame(0, $report->manpower()->count());
        $this->assertSame(0, $report->materials()->count());
        $this->assertSame(0, $report->equipment()->count());
        $this->assertSame('Rain from 14:00.', $report->delays, 'Untouched fields stay untouched.');
    }

    /* ------------------------------------------------------ authorization */

    public function test_an_employee_is_not_allowed_to_write_the_official_record(): void
    {
        $world = $this->world('Employee');

        // The permission is not granted, so the route refuses before a
        // single rule — and before any policy — runs.
        $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertStatus(403);
        $this->getJson('/api/v1/daily-site-reports')->assertStatus(403);

        $this->assertSame(0, DailySiteReport::query()->count());
    }

    public function test_a_supervisor_only_sees_the_documents_for_the_sites_they_run(): void
    {
        $mine = $this->world('Site Supervisor');
        $mineId = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($mine['site']))
            ->assertCreated()->json('data.id');

        $theirs = $this->world('Site Supervisor');
        $theirId = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($theirs['site']))
            ->assertCreated()->json('data.id');

        $items = $this->getJson('/api/v1/daily-site-reports')->assertOk()->json('data.items');
        $this->assertSame([$theirId], array_column($items, 'id'));

        $this->getJson('/api/v1/daily-site-reports/'.$mineId)->assertStatus(403);
        $this->putJson('/api/v1/daily-site-reports/'.$mineId, ['delays' => 'peek'])->assertStatus(403);

        // Naming another author does not open their list.
        $this->getJson('/api/v1/daily-site-reports?created_by='.$mine['user']->id)
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    /* --------------------------------------------------------------- state */

    public function test_the_document_can_be_corrected_only_while_it_is_a_draft(): void
    {
        $world = $this->world('Site Supervisor');
        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        $this->putJson('/api/v1/daily-site-reports/'.$id, [
            'work_completed' => 'Reinforcement tied for B1 to B3.',
        ])->assertOk()->assertJsonPath('data.status', DailySiteReport::STATUS_DRAFT);

        $this->postJson('/api/v1/daily-site-reports/'.$id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', DailySiteReport::STATUS_SUBMITTED);

        $this->putJson('/api/v1/daily-site-reports/'.$id, [
            'work_completed' => 'One more sentence.',
        ])->assertStatus(409);

        $this->postJson('/api/v1/daily-site-reports/'.$id.'/submit')->assertStatus(409);
    }

    public function test_filters_narrow_the_list_without_reaching_through_the_scope(): void
    {
        $world = $this->world('Site Supervisor');
        $other = Site::factory()->create(['site_supervisor_id' => $world['employee']->id]);

        $today = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');
        $elsewhere = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($other))
            ->assertCreated()->json('data.id');
        $yesterday = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], [
            'report_date' => '2026-09-27',
        ]))->assertCreated()->json('data.id');

        $this->postJson('/api/v1/daily-site-reports/'.$today.'/submit')->assertOk();

        $ids = fn (string $query) => array_column(
            $this->getJson('/api/v1/daily-site-reports'.$query)->assertOk()->json('data.items'),
            'id',
        );

        $this->assertEqualsCanonicalizing([$today, $elsewhere, $yesterday], $ids(''));
        $this->assertSame([$today], $ids('?status=submitted'));
        $this->assertSame([$yesterday], $ids('?to=2026-09-27'));
        $this->assertSame([$elsewhere], $ids('?site_id='.$other->id));
        $this->assertEqualsCanonicalizing([$today, $elsewhere], $ids('?from=2026-09-28'));
        $this->assertSame([$today], $ids('?created_by='.$world['user']->id.'&status=submitted'));
        $this->assertSame([], $ids('?project_id=999999'));
    }

    /* ------------------------------------------------------------ photos */

    public function test_a_daily_report_photograph_follows_the_same_private_rules(): void
    {
        $world = $this->world('Site Supervisor');
        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        $this->post('/api/v1/daily-site-reports/'.$id.'/photos', [
            'photos' => [$this->photo('a.jpg')],
            'caption' => 'Pour at 09:30',
        ])->assertCreated();

        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression(
            '#^site-report-photos/daily/'.$id.'/[0-9a-f-]{36}\.jpg$#',
            $files[0],
        );

        $detail = $this->getJson('/api/v1/daily-site-reports/'.$id)->assertOk();
        $this->assertStringNotContainsString('site-report-photos', (string) $detail->getContent());
        $this->assertSame('Pour at 09:30', $detail->json('data.photos.0.caption'));

        $bytes = $this->get('/api/v1/daily-site-reports/'.$id.'/photos/'.$detail->json('data.photos.0.id'));
        $bytes->assertOk();
        $this->assertStringStartsWith("\xFF\xD8", (string) $bytes->streamedContent());

        // Not a photograph, and nothing written.
        $this->post('/api/v1/daily-site-reports/'.$id.'/photos', [
            'photos' => [UploadedFile::fake()->createWithContent('site.jpg', '<html></html>')],
        ])->assertStatus(422);

        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_an_account_without_the_permission_never_reaches_the_document(): void
    {
        $this->signInAs('Payroll Admin');

        $this->getJson('/api/v1/daily-site-reports')->assertStatus(403);
        $this->postJson('/api/v1/daily-site-reports', [])->assertStatus(403);
    }
}
