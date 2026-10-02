<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsTrainingAssets;
use Tests\TestCase;

/**
 * GET /api/v1/asset-assignments — the hand-over log, and why it is read-only.
 *
 * The log is a control document: it answers "who had this laptop in March,
 * what condition did it leave in, and who handed it over?" — and every one
 * of those questions has to still be answerable in November. Three
 * consequences get a test each:
 *
 *  - **a return closes a row without unwriting the hand-over.** The
 *    assigned condition, the assigned-by and the assigned date all survive
 *    the hand-back beside the four things the hand-back itself recorded.
 *    Only `remarks` moves — there is one of them, and the later account of
 *    the same row is the one that stands.
 *  - **the cross-employee log needs `assets.history.view`.** A holder of
 *    `assets.view` alone reads their own rows through ownership — an
 *    employee asking "what have I been handed?" is answered without it —
 *    while "who else has held anything" is HR's and Management's because
 *    it is a control document rather than a personal file.
 *  - **there is no writer here at all.** `assign` and `return` live on
 *    AssetController and go through AssetService, where the lock is. A
 *    second endpoint that could append a row would be a second place to get
 *    "at most one active hand-over per asset" wrong.
 */
class AssetAssignmentHistoryTest extends TestCase
{
    use BuildsTrainingAssets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTrainingAssetStack();

        Storage::fake('local');
        $this->withHeaders(['Accept' => 'application/json']);
    }

    public function test_a_return_closes_the_row_without_rewriting_the_hand_over(): void
    {
        [$hr] = $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        $asset = $this->makeAsset(['current_condition' => Asset::CONDITION_NEW]);

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', [
            'employee_id' => $holder->id,
            'assigned_condition' => Asset::CONDITION_NEW,
            'remarks' => 'Issued on day one.',
        ])->assertOk();

        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_FAIR,
            'remarks' => 'Screen cracked in transit.',
        ])->assertOk();

        $row = AssetAssignment::query()->where('asset_id', $asset->id)->firstOrFail();

        // The hand-over half, untouched by the hand-back.
        $this->assertSame($hr->id, $row->assigned_by);
        $this->assertSame(Asset::CONDITION_NEW, $row->assigned_condition);
        $this->assertNotNull($row->assigned_date);

        // The hand-back half, added rather than substituted.
        $this->assertSame($hr->id, $row->returned_by);
        $this->assertSame(Asset::CONDITION_FAIR, $row->returned_condition);
        $this->assertNotNull($row->returned_date);
        $this->assertSame(AssetAssignment::STATUS_RETURNED, $row->status);

        // `remarks` is the one column that is a *narrative* rather than a
        // fact, and there is one of them: the later account of the same row
        // is the one that stands, because "what happened to it?" has a
        // single current answer.
        $this->assertSame('Screen cracked in transit.', $row->remarks);

        // And the asset itself took the condition it came back in — condition
        // tracking is this row, not a second table.
        $this->assertSame(Asset::CONDITION_FAIR, $asset->fresh()->current_condition);
    }

    public function test_a_hand_back_that_says_nothing_leaves_the_hand_over_story_in_place(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', [
            'employee_id' => $holder->id,
            'remarks' => 'Issued on day one.',
        ])->assertOk();

        // No remarks on the way back: there is nothing new to say, and a
        // return that blanked the column would erase the only account of
        // why this asset was ever handed to anybody.
        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_GOOD,
        ])->assertOk();

        $row = AssetAssignment::query()->where('asset_id', $asset->id)->firstOrFail();

        $this->assertSame('Issued on day one.', $row->remarks);
        $this->assertSame(AssetAssignment::STATUS_RETURNED, $row->status);
    }

    public function test_the_cross_employee_log_is_behind_its_own_permission(): void
    {
        $this->signInAs('Management');
        [$employeeUser] = $this->makeSeat('Employee');
        [, $colleague] = $this->makeSeat('Employee');

        $asset = $this->makeAsset();
        $row = $this->makeAssignment($asset, $colleague);

        // Management holds `assets.history.view` but not `assets.manage`, so
        // the log of who else has held anything is theirs while the register
        // itself stays HR's.
        $this->getJson('/api/v1/asset-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $row->id);

        $this->getJson('/api/v1/asset-assignments/'.$row->id)->assertOk();

        // An employee holds `assets.view` and nothing about the log: their
        // own hand-overs are their own, a colleague's are not theirs to read.
        $this->become($employeeUser);

        $this->getJson('/api/v1/asset-assignments')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->getJson('/api/v1/asset-assignments/'.$row->id)->assertForbidden();
    }

    public function test_your_own_hand_over_is_readable_without_the_log_permission(): void
    {
        [$employeeUser, $employee] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();
        $row = $this->makeAssignment($asset, $employee);

        $this->become($employeeUser);

        // "What have I been handed?" is answered by ownership alone — the
        // log permission is about *somebody else's* hand-overs, and asking
        // for it first would put a door between an employee and their own
        // desk.
        $this->getJson('/api/v1/asset-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $row->id);

        $this->getJson('/api/v1/asset-assignments/'.$row->id)
            ->assertOk()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.status', AssetAssignment::STATUS_ACTIVE)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_the_asset_detail_carries_its_own_history_whole(): void
    {
        $this->signInAs('HR Admin');
        [, $first] = $this->makeSeat('Employee');
        [, $second] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', ['employee_id' => $first->id])->assertOk();
        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_GOOD,
        ])->assertOk();
        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', ['employee_id' => $second->id])->assertOk();

        $data = $this->getJson('/api/v1/assets/'.$asset->id)->assertOk()->json('data');

        $this->assertNotNull($data['assignments'], 'The detail screen has the history, so it says so.');
        $this->assertCount(2, $data['assignments']);

        $byHolder = collect($data['assignments'])->keyBy('employee_id');

        $this->assertSame(
            AssetAssignment::STATUS_RETURNED,
            $byHolder[$first->id]['status'],
            'A closed hand-back is still on the list — that is the question the screen exists to answer.',
        );
        $this->assertSame('good', $byHolder[$first->id]['returned_condition']);
        $this->assertSame(
            AssetAssignment::STATUS_ACTIVE,
            $byHolder[$second->id]['status'],
        );
        $this->assertSame($second->id, $data['current_assignment']['employee_id']);
    }

    public function test_there_is_no_way_to_author_a_hand_over_directly(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();
        $row = $this->makeAssignment($asset, $holder);

        // Reading only: the method that would append a row does not exist,
        // because `assign` and `return` are on AssetController where the
        // lock lives.
        $this->postJson('/api/v1/asset-assignments', [
            'asset_id' => $asset->id,
            'employee_id' => $holder->id,
        ])->assertStatus(405);

        $this->putJson('/api/v1/asset-assignments/'.$row->id, [
            'status' => AssetAssignment::STATUS_RETURNED,
        ])->assertStatus(405);

        $this->deleteJson('/api/v1/asset-assignments/'.$row->id)->assertStatus(405);

        $this->assertSame(1, AssetAssignment::query()->count());
        $this->assertSame(AssetAssignment::STATUS_ACTIVE, $row->fresh()->status);
    }
}
