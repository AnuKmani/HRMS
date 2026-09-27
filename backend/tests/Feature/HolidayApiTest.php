<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * GET/POST/PUT /api/v1/holidays.
 *
 * The *rules* of the calendar — a public holiday is excluded from a day count,
 * a site holiday excludes only its own site, an inactive one counts as a
 * working day again — are proved in LeaveRequestTest, where the consequence of
 * getting them wrong is a wrong number. What this file covers is the surface
 * they are maintained through:
 *
 *   - readable by every signed-in account, because "is the office open?" is
 *     not a privileged question, and writable only by `holidays.manage`;
 *   - scoped the same way in the list and in a single `show`, so a day the
 *     collection hides cannot be found by guessing an id;
 *   - unique on (date, type, site), checked with a real `whereNull` for the
 *     public case where `Rule::unique` would pass a second copy;
 *   - retired by status, never deleted — there is no DELETE route, and the
 *     last test says so out loud rather than leaving it to be discovered.
 */
class HolidayApiTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    private Site $here;

    private Site $away;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();

        $this->here = Site::factory()->create();
        $this->away = Site::factory()->create([
            'site_supervisor_id' => null,
            'site_manager_id' => null,
        ]);
    }

    /* ------------------------------------------------------- read vs write */

    public function test_the_calendar_is_readable_by_anybody_but_writable_only_by_hr(): void
    {
        [, $employee] = $this->signInAs('Employee');
        $employee->update(['primary_site_id' => $this->here->id]);

        // "Is the office open?" is not a privileged question, so there is no
        // `holidays.view` permission to be missing — and no view policy to be
        // denied by either.
        $this->getJson('/api/v1/holidays')->assertOk();

        // Writing is a different matter entirely.
        $payload = ['name' => 'Founders Day', 'date' => '2026-10-02', 'type' => 'public'];
        $this->postJson('/api/v1/holidays', $payload)->assertForbidden();

        $created = Holiday::create($payload);

        $this->putJson("/api/v1/holidays/{$created->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/holidays/{$created->id}")->assertStatus(405);

        // And neither refusal wrote anything.
        $this->assertSame('Founders Day', Holiday::query()->findOrFail($created->id)->name);
    }

    public function test_a_reader_gets_public_and_company_days_plus_the_sites_they_belong_to(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];

        $this->become($hr);
        $public = $this->holiday('Public Day', '2026-10-05', 'public')->json('data');
        $company = $this->holiday('Company Day', '2026-10-06', 'company')->json('data');
        $ours = $this->holiday('Our Site Day', '2026-10-07', 'site', $this->here->id)->json('data');
        $theirs = $this->holiday('Their Site Day', '2026-10-08', 'site', $this->away->id)->json('data');

        [, $employee] = $this->signInAs('Employee');
        $employee->update(['primary_site_id' => $this->here->id]);

        $ids = array_column(
            $this->getJson('/api/v1/holidays')->assertOk()->json('data.items'),
            'id',
        );

        $this->assertSame(
            [$public['id'], $company['id'], $ours['id']],
            $ids,
            'A site day belonging to somebody else is not published by the permission that reads yours.',
        );

        $this->getJson("/api/v1/holidays/{$ours['id']}")->assertOk();
        $this->getJson("/api/v1/holidays/{$theirs['id']}")->assertForbidden();
        $this->getJson("/api/v1/holidays/{$public['id']}")->assertOk();

        // Holding the write permission opens the whole calendar — configuring
        // a site holiday you cannot then read would be a strange edit.
        $this->become($hr);
        $this->assertCount(
            4,
            $this->getJson('/api/v1/holidays')->assertOk()->json('data.items'),
        );
    }

    public function test_the_calendar_narrows_by_date_type_status_and_name(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];
        $this->become($hr);

        $this->holiday('Deepavali', '2026-10-20', 'public');
        $this->holiday('Company Offsite', '2026-10-21', 'company');
        $retired = $this->holiday('Old Day', '2026-10-22', 'public', null, 'inactive');
        $this->holiday('Late Deepavali', '2026-11-20', 'public');

        $this->assertSame(4, $this->total([]));
        $this->assertSame(3, $this->total(['from' => '2026-10-01', 'to' => '2026-10-31']));
        $this->assertSame(3, $this->total(['type' => 'public']), 'Retired days are still days.');
        $this->assertSame(1, $this->total(['type' => 'company']));
        $this->assertSame(3, $this->total(['status' => 'active']));
        $this->assertSame(1, $this->total(['status' => 'inactive']));
        $this->assertSame(2, $this->total(['search' => 'Deepavali']));
        $this->assertSame(1, $this->total(['from' => '2026-11-01']));

        // The default is whatever you ask for, not a hidden default filter —
        // a retired day is still a day that happened.
        $ids = array_column($this->getJson('/api/v1/holidays')->json('data.items'), 'id');
        $this->assertContains($retired->json('data.id'), $ids);
    }

    /* ------------------------------------------------------------ identity */

    public function test_a_scope_on_a_date_can_hold_only_one_holiday(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];
        $this->become($hr);

        $this->holiday('Republic Day', '2026-01-26', 'public')->assertCreated();

        // A *real* `whereNull`, which is the part `Rule::unique` cannot do: a
        // public holiday has `site_id IS NULL`, and SQL equality to NULL is
        // never true, so the naive rule would wave a second copy straight
        // through while claiming to have checked.
        $this->postJson('/api/v1/holidays', [
            'name' => 'Republic Day Again',
            'date' => '2026-01-26',
            'type' => 'public',
        ])->assertStatus(422);

        // Same date, different scope: a company lunch on a public holiday is a
        // different row and a different day calculation.
        $this->postJson('/api/v1/holidays', [
            'name' => 'Founders Lunch',
            'date' => '2026-01-26',
            'type' => 'company',
        ])->assertCreated();

        // And two site holidays can share a date as long as they are not the
        // same site — that is the whole point of a site scope.
        $this->holiday('Block A Off', '2026-02-14', 'site', $this->here->id);
        $this->holiday('Block B Off', '2026-02-14', 'site', $this->away->id);

        $this->postJson('/api/v1/holidays', [
            'name' => 'Block A Off, Really',
            'date' => '2026-02-14',
            'type' => 'site',
            'site_id' => $this->here->id,
        ])->assertStatus(422);

        $this->assertSame(4, Holiday::query()->count());
        $this->assertSame(
            $this->here->id,
            Holiday::query()->where('name', 'Block A Off')->firstOrFail()->site_id,
        );
    }

    public function test_a_site_holiday_without_a_site_is_refused(): void
    {
        $this->become($this->makeSeat('HR Admin')[0]);

        $this->postJson('/api/v1/holidays', [
            'name' => 'Somewhere',
            'date' => '2026-10-02',
            'type' => 'site',
        ])->assertUnprocessable()->assertJsonValidationErrors('site_id');

        $this->postJson('/api/v1/holidays', [
            'name' => 'Someday',
            'date' => '02-10-2026',
            'type' => 'public',
        ])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->postJson('/api/v1/holidays', [
            'name' => 'Someday',
            'date' => '2026-10-02',
            'type' => 'statutory',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->assertSame(0, Holiday::query()->count());
    }

    /* ----------------------------------------------------------- retirement */

    public function test_a_holiday_is_retired_by_status_and_there_is_no_way_to_delete_it(): void
    {
        $this->become($this->makeSeat('HR Admin')[0]);

        $holiday = $this->holiday('Last Year Offsite', '2026-10-22', 'company')->assertCreated()->json('data');

        $this->putJson("/api/v1/holidays/{$holiday['id']}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.is_active', false);

        // The row is still there: an inactive day is how "this used to count"
        // is remembered, and a holiday list is not an append-only log of
        // decisions nobody can unmake.
        $this->assertDatabaseHas('holidays', [
            'id' => $holiday['id'],
            'status' => 'inactive',
        ]);

        // There is no DELETE route, so the act does not exist to be
        // authorised — a 405 rather than a 403, because nothing here is
        // forbidden; it is simply not offered.
        $this->deleteJson("/api/v1/holidays/{$holiday['id']}")->assertStatus(405);
        $this->assertDatabaseHas('holidays', ['id' => $holiday['id']]);
    }

    public function test_editing_a_holiday_cannot_make_it_collide_with_another(): void
    {
        $this->become($this->makeSeat('HR Admin')[0]);

        $taken = $this->holiday('Taken Day', '2026-10-10', 'public')->json('data');
        $free = $this->holiday('Free Day', '2026-10-11', 'public')->json('data');

        $this->putJson("/api/v1/holidays/{$free['id']}", ['date' => '2026-10-10'])
            ->assertStatus(422);

        // Moving it onto a date nobody has claimed is the ordinary case.
        $this->putJson("/api/v1/holidays/{$free['id']}", ['date' => '2026-10-12'])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-10-12');

        // A PUT that mentions nothing but a rename still has to *not* collide,
        // and it checks the row's own date, type and site — not the payload's.
        $this->putJson("/api/v1/holidays/{$free['id']}", ['name' => 'Still Free'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Still Free');

        $this->putJson("/api/v1/holidays/{$taken['id']}", ['name' => 'Renamed'])->assertOk();
        $this->assertSame(2, Holiday::query()->count());
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * @return TestResponse
     */
    private function holiday(
        string $name,
        string $date,
        string $type,
        ?int $siteId = null,
        string $status = 'active',
    ) {
        return $this->postJson('/api/v1/holidays', array_filter([
            'name' => $name,
            'date' => $date,
            'type' => $type,
            'site_id' => $siteId,
            'status' => $status,
        ], fn ($value) => $value !== null))->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function total(array $query): int
    {
        return count($this->getJson('/api/v1/holidays?'.http_build_query($query))
            ->assertOk()
            ->json('data.items'));
    }
}
