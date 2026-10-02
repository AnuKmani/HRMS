<?php

namespace Tests\Feature;

use App\Models\DailySiteReport;
use App\Services\SettingsService;
use App\Services\SiteReport\DailySiteReportPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\BuildsSiteReports;
use Tests\TestCase;

/**
 * The daily site report as a document.
 *
 * Four properties, and the first two are the design decision this whole
 * endpoint rests on:
 *
 *  - **built on demand.** Nothing is written to disk, so nothing can go
 *    stale beside the row it was copied from.
 *  - **private.** `no-store`, an `inline` disposition with a name derived
 *    from the row, and both the permission *and* the row-level policy —
 *    which are separate questions, proved here by revoking one while
 *    leaving the other.
 *  - **complete.** Every section the spec asks for is present, and an empty
 *    one says "Not recorded" rather than vanishing.
 *  - **honest about what it embeds.** Photographs are inlined as `data:`
 *    URIs — never fetched, never linked — and capped so a report with
 *    twenty frames does not produce a document nobody can open.
 */
class DailySiteReportPdfTest extends TestCase
{
    use BuildsSiteReports, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSiteReports();

        Storage::fake('local');
        Storage::fake('public');
    }

    /* ---------------------------------------------------------- content */

    public function test_the_document_carries_every_section_a_reader_needs(): void
    {
        $report = $this->filedReport([
            'safety_observations' => 'Edge protection inspected at 08:00.',
            'delays' => 'Rain from 14:00 to 16:00.',
            'issues' => 'Waiting on the rebar delivery.',
            'remarks' => 'Concrete pour scheduled for tomorrow.',
        ]);

        $html = app(DailySiteReportPdf::class)->html($report);

        foreach ([
            'Daily Site Report',
            $report->site->name,
            $report->project->name,
            '28/09/2026',
            'Prepared by',
            // The creator's name is HTML-escaped in the PDF (e.g., O'Hara -> O&#039;Hara)
            htmlspecialchars($report->creator->name, ENT_QUOTES, 'UTF-8'),
            'Manpower',
            'Total head count: <strong>18</strong>',
            'Masons',
            'Electricians',
            'Work planned',
            'Work completed',
            'Materials used',
            'Cement',
            'Equipment used',
            'Tower crane',
            'Safety observations',
            'Edge protection inspected at 08:00.',
            'Delays',
            'Rain from 14:00 to 16:00.',
            'Issues',
            'Waiting on the rebar delivery.',
            'Remarks',
            'Concrete pour scheduled for tomorrow.',
            'Reference',
            'Generated ',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, 'Missing from the document: '.$needle);
        }
    }

    public function test_a_section_nobody_filled_in_says_so_rather_than_disappearing(): void
    {
        $report = $this->filedReport([
            'safety_observations' => null,
            'delays' => null,
            'issues' => null,
            'remarks' => null,
            'materials' => [],
            'equipment' => [],
        ]);

        $html = app(DailySiteReportPdf::class)->html($report);

        // A reader cannot tell "there were no delays" from "nobody looked"
        // unless the document says which it is.
        $this->assertSame(4, substr_count($html, 'Not recorded'), 'One per empty narrative section.');
        $this->assertStringContainsString('None recorded.', $html);
        $this->assertStringContainsString('None attached.', $html);
    }

    public function test_the_company_name_is_a_setting_not_a_constant(): void
    {
        $report = $this->filedReport();
        $this->assertStringContainsString('HRMS', app(DailySiteReportPdf::class)->html($report));

        DB::table('settings')
            ->where('key', 'reporting.company_name')
            ->update(['value' => 'Acme Builders Pvt Ltd']);
        app(SettingsService::class)->refresh();

        $this->assertStringContainsString(
            'Acme Builders Pvt Ltd',
            app(DailySiteReportPdf::class)->html($report),
        );
    }

    public function test_free_text_typed_on_a_phone_is_escaped_before_it_reaches_the_parser(): void
    {
        $report = $this->filedReport([
            'delays' => '<script>alert("x")</script> and "quotes" & <b>tags</b>',
        ]);

        $html = app(DailySiteReportPdf::class)->html($report);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;tags&lt;/b&gt;', $html);
    }

    /* ----------------------------------------------------------- transport */

    public function test_the_pdf_is_generated_on_demand_and_never_written_to_disk(): void
    {
        $world = $this->world('Site Supervisor');
        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        $first = $this->get('/api/v1/daily-site-reports/'.$id.'/pdf');

        $first->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $first->getContent());
        $this->assertStringContainsString('application/pdf', (string) $first->headers->get('content-type'));
        $this->assertStringContainsString('daily-site-report-'.$id.'-28092026.pdf', (string) $first->headers->get('content-disposition'));
        $this->assertStringContainsString(
            'no-store',
            strtolower((string) $first->headers->get('cache-control')),
            'A shared tablet must not leave a copy in a proxy.',
        );

        // Nothing `.pdf`, nothing at all: the response came out of memory.
        $this->assertSame([], array_filter(
            Storage::disk('local')->allFiles(),
            fn (string $file) => str_ends_with($file, '.pdf'),
        ));

        // Correct the document, ask again, and the answer has moved on —
        // which is the whole argument against storing a copy.
        $this->putJson('/api/v1/daily-site-reports/'.$id, [
            'delays' => 'Rain until 16:00.',
        ])->assertOk();

        $this->assertStringContainsString(
            'Rain until 16:00.',
            app(DailySiteReportPdf::class)->html($this->freshReport($id)),
        );

        $this->get('/api/v1/daily-site-reports/'.$id.'/pdf')->assertOk();
        $this->assertSame([], array_filter(
            Storage::disk('local')->allFiles(),
            fn (string $file) => str_ends_with($file, '.pdf'),
        ));
    }

    public function test_the_permission_and_the_row_level_rule_are_both_required(): void
    {
        $mine = $this->world('Site Supervisor');
        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($mine['site']))
            ->assertCreated()->json('data.id');

        $this->get('/api/v1/daily-site-reports/'.$id.'/pdf')->assertOk();

        // A different supervisor, holding the permission, whose site this is
        // not: the row question says no.
        $other = $this->world('Site Supervisor');
        $this->get('/api/v1/daily-site-reports/'.$id.'/pdf')->assertStatus(403);

        // Still able to *read* the document through JSON — the two abilities
        // are separate, so revoking `.pdf` must not take `.view` with it.
        // Revoked from the role rather than the account: this user holds
        // both grants through Site Supervisor, and revoking one of them
        // directly would only unlink a direct assignment that was never
        // there to unlink.
        $this->become($mine['user']);
        Role::query()->where('name', 'Site Supervisor')->sole()
            ->revokePermissionTo('daily_site_reports.pdf');

        $this->getJson('/api/v1/daily-site-reports/'.$id)->assertOk();
        $this->get('/api/v1/daily-site-reports/'.$id.'/pdf')->assertStatus(403);

        $this->assertFalse($mine['user']->can('daily_site_reports.pdf'));
        $this->assertTrue(
            $mine['user']->can('daily_site_reports.view'),
            'A revoked PDF grant must leave the ability to read the document itself.',
        );
    }

    /* ------------------------------------------------------------- photos */

    public function test_photographs_are_embedded_rather_than_linked(): void
    {
        $world = $this->world('Site Supervisor');
        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        $this->post('/api/v1/daily-site-reports/'.$id.'/photos', [
            'photos' => [$this->photo('a.jpg')],
            'caption' => 'Rebar before the pour',
        ])->assertCreated();

        $html = app(DailySiteReportPdf::class)->html($this->freshReport($id));

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringContainsString('Rebar before the pour', $html);

        // No path, no URL: dompdf will fetch an `http://` source if it is
        // given one, and a private disk path is not fetchable at all.
        $this->assertStringNotContainsString('site-report-photos', $html);
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
    }

    public function test_only_six_photographs_are_embedded_and_the_rest_are_counted(): void
    {
        $world = $this->world('Site Supervisor');
        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site']))
            ->assertCreated()->json('data.id');

        $report = DailySiteReport::query()->findOrFail($id);

        for ($i = 0; $i < 8; $i++) {
            $path = 'site-report-photos/daily/'.$id.'/'.$i.'.jpg';
            Storage::disk('local')->put($path, $this->jpegBytes());

            $report->photos()->create([
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'caption' => 'Frame '.($i + 1),
                'sort_order' => $i,
            ]);
        }

        $html = app(DailySiteReportPdf::class)->html($report);

        $this->assertSame(
            DailySiteReportPdf::MAX_PHOTOS,
            substr_count($html, 'data:image/jpeg;base64,'),
            'A twenty-frame report would otherwise build a twenty-megabyte document.',
        );
        $this->assertStringContainsString('2 more photograph(s) attached to this report.', $html);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * A filed, submitted daily report for the anchored Monday, with the
     * fixture's child rows intact.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function filedReport(array $overrides = []): DailySiteReport
    {
        $world = $this->world('Site Supervisor');

        $id = $this->postJson('/api/v1/daily-site-reports', $this->dailyPayload($world['site'], $overrides))
            ->assertCreated()
            ->json('data.id');

        return $this->freshReport($id);
    }

    private function freshReport(int $id): DailySiteReport
    {
        return DailySiteReport::query()
            ->with(['creator', 'project', 'site', 'manpower', 'materials', 'equipment', 'photos'])
            ->findOrFail($id);
    }
}
