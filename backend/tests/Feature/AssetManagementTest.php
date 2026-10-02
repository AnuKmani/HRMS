<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsTrainingAssets;
use Tests\TestCase;

/**
 * /api/v1/assets — the company's property register, and who may hand what
 * to whom.
 *
 * The file is arranged around the three rules that need a lock rather than
 * a check, because those are the ones a controller could get wrong by
 * reading a row and writing it back a moment later:
 *
 *  - **an asset out on somebody's desk cannot be handed to somebody else.**
 *    `assign()` re-reads for an active hand-over *inside* a
 *    `lockForUpdate()` — which is why one test below plants an active row
 *    while the asset still says `available` and expects a 409. A status
 *    column alone would have sailed straight past it.
 *  - **a hand-back needs something to hand back.** No active row, a second
 *    return, a return of a return: all three are 409s naming the state.
 *  - **a status may only walk where Asset::TRANSITIONS allows.** Two of the
 *    six are refused outright with sentences that name the right endpoint,
 *    because both would otherwise leave an asset whose status and open
 *    hand-over disagree.
 *
 * Two quieter rules get a test each because they are the kind that only
 * shows up in production otherwise: `purchase_cost` never reaches a reader
 * without `assets.manage`, and condition/status are `prohibited` on an edit
 * rather than silently dropped — a client that believed it had set one
 * should get a 422 naming the field, not a 200 and a lie.
 */
class AssetManagementTest extends TestCase
{
    use BuildsTrainingAssets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTrainingAssetStack();

        Storage::fake('local');
        Storage::fake('public');

        $this->withHeaders(['Accept' => 'application/json']);
    }

    /* ------------------------------------------------------------- register */

    public function test_hr_registers_an_asset_and_nothing_may_take_its_code(): void
    {
        $this->signInAs('HR Admin');

        $payload = $this->assetPayload();

        $this->postJson('/api/v1/assets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.asset_code', $payload['asset_code'])
            ->assertJsonPath('data.status', Asset::STATUS_AVAILABLE)
            ->assertJsonPath('data.current_condition', Asset::CONDITION_GOOD)
            ->assertJsonPath('data.is_assignable', true)
            ->assertJsonPath('data.asset_type.code', 'LAPTOP');

        $this->postJson('/api/v1/assets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_code']);

        $this->assertSame(1, Asset::query()->count());
    }

    public function test_a_new_asset_is_born_available_and_status_is_not_a_create_field(): void
    {
        $this->signInAs('HR Admin');

        $this->postJson('/api/v1/assets', $this->assetPayload(['status' => 'assigned']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // An asset already out with somebody, on the day it was registered
        // and with no hand-over behind it, is exactly the fabrication the
        // prohibition exists to stop.
        $this->postJson('/api/v1/assets', $this->assetPayload(['current_condition' => 'poor']))
            ->assertCreated()
            ->assertJsonPath('data.current_condition', Asset::CONDITION_POOR);

        $this->assertSame(0, AssetAssignment::query()->count());
    }

    public function test_the_cost_is_only_shown_to_a_reader_entitled_to_the_register(): void
    {
        $this->signInAs('HR Admin');
        [$holderUser, $holder] = $this->makeSeat('Employee');
        $this->makeSeat('Employee');

        $theirs = $this->makeAsset(['purchase_cost' => 4200.00]);
        $this->makeAsset(['asset_code' => 'POOL-1']);

        $this->makeAssignment($theirs, $holder);

        // HR holds `assets.manage`, so the money is theirs to read.
        $items = $this->getJson('/api/v1/assets')->assertOk()->json('data.items');
        $first = collect($items)->firstWhere('id', $theirs->id);

        $this->assertArrayHasKey('purchase_cost', $first);
        $this->assertSame(4200.0, (float) $first['purchase_cost']);

        // An employee holds `assets.view` but not `assets.manage`, so the
        // field is *omitted* rather than nulled — a null would read like
        // "this laptop was free", and free is a different fact.
        $this->become($holderUser);

        $visible = $this->getJson('/api/v1/assets')->assertOk()->json('data.items');

        $this->assertCount(1, $visible, 'The pool is not part of this person\'s world.');
        $this->assertArrayNotHasKey('purchase_cost', $visible[0]);
        $this->assertArrayHasKey('asset_code', $visible[0], 'The barcode a label will carry is not a secret.');
    }

    /* ------------------------------------------------------------ hand-over */

    public function test_hr_hands_an_asset_to_somebody_and_the_register_follows(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', [
            'employee_id' => $holder->id,
            'expected_return_date' => now()->addWeek()->toDateString(),
            'remarks' => 'Issued with the site kit.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Asset::STATUS_ASSIGNED)
            ->assertJsonPath('data.is_assignable', false)
            ->assertJsonPath('data.current_assignment.employee_id', $holder->id)
            ->assertJsonPath('data.current_assignment.status', AssetAssignment::STATUS_ACTIVE);

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'status' => Asset::STATUS_ASSIGNED,
        ]);
        $this->assertSame(1, AssetAssignment::query()->active()->count());
    }

    public function test_an_asset_out_with_somebody_cannot_go_out_again(): void
    {
        $this->signInAs('HR Admin');
        [, $first] = $this->makeSeat('Employee');
        [, $second] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', [
            'employee_id' => $first->id,
        ])->assertOk();

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', [
            'employee_id' => $second->id,
        ])->assertStatus(409);

        $this->assertSame(1, AssetAssignment::query()->count(), 'One asset, one hand-over.');
    }

    public function test_a_hand_over_nobody_recorded_is_still_caught(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        [, $newcomer] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        // The active row exists while the asset still says `available` —
        // an inconsistent state no status check would ever notice, and the
        // whole reason the re-read happens inside the lock rather than
        // beside it.
        $this->makeAssignment($asset, $holder);

        $this->assertSame(Asset::STATUS_AVAILABLE, $asset->fresh()->status);

        $response = $this->postJson('/api/v1/assets/'.$asset->id.'/assign', [
            'employee_id' => $newcomer->id,
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('already assigned', (string) $response->json('message'));
        $this->assertSame(1, AssetAssignment::query()->count());
    }

    public function test_returning_records_what_came_back_and_decides_where_it_goes(): void
    {
        $this->signInAs('HR Admin');
        [, $one] = $this->makeSeat('Employee');
        [, $two] = $this->makeSeat('Employee');

        $sound = $this->makeAsset();
        $broken = $this->makeAsset();

        $this->postJson('/api/v1/assets/'.$sound->id.'/assign', ['employee_id' => $one->id])->assertOk();
        $this->postJson('/api/v1/assets/'.$broken->id.'/assign', ['employee_id' => $two->id])->assertOk();

        $this->postJson('/api/v1/assets/'.$sound->id.'/return', [
            'returned_condition' => Asset::CONDITION_GOOD,
            'remarks' => 'Back in one piece.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Asset::STATUS_AVAILABLE)
            ->assertJsonPath('data.current_condition', Asset::CONDITION_GOOD)
            ->assertJsonPath('data.current_assignment', null);

        $this->postJson('/api/v1/assets/'.$broken->id.'/return', [
            'returned_condition' => Asset::CONDITION_POOR,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Asset::STATUS_MAINTENANCE)
            ->assertJsonPath('data.current_condition', Asset::CONDITION_POOR);

        $this->assertSame(
            0,
            AssetAssignment::query()->active()->count(),
            'Both hand-overs are closed.',
        );
        $this->assertSame(
            2,
            AssetAssignment::query()->returned()->count(),
            'Closed, never removed — "who had this in March?" has to be answerable in November.',
        );
    }

    public function test_a_return_needs_something_to_return(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        // Never handed out.
        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_GOOD,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That asset is not currently assigned, so there is nothing to return.');

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', ['employee_id' => $holder->id])->assertOk();

        // A condition is the whole point of the endpoint; without it there
        // is nothing to record and nothing to decide the next status from.
        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [])->assertUnprocessable();

        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_GOOD,
        ])->assertOk();

        // And a second return is the first one's sentence, because the
        // first flipped the row inside its own lock.
        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_GOOD,
        ])->assertStatus(409);

        $this->assertSame(1, AssetAssignment::query()->count());
    }

    public function test_the_hand_over_acts_are_not_self_service(): void
    {
        [, $me] = $this->signInAs('Employee');
        $asset = $this->makeAsset();

        // `assets.view` is held by every employee — they cannot be shown
        // the laptop on their desk behind a permission they lack — but it
        // opens none of the three acts.
        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', [
            'employee_id' => $me->id,
        ])->assertForbidden();

        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_GOOD,
        ])->assertForbidden();

        $this->patchJson('/api/v1/assets/'.$asset->id.'/status', [
            'status' => Asset::STATUS_LOST,
        ])->assertForbidden();

        $this->postJson('/api/v1/assets', $this->assetPayload())->assertForbidden();
    }

    /* -------------------------------------------------------------- status */

    public function test_status_moves_follow_the_map_and_the_two_that_are_not_moves_say_so(): void
    {
        $this->signInAs('HR Admin');

        $retired = $this->makeAsset();
        $this->patchJson('/api/v1/assets/'.$retired->id.'/status', ['status' => Asset::STATUS_RETIRED])
            ->assertOk()
            ->assertJsonPath('data.status', Asset::STATUS_RETIRED)
            ->assertJsonPath('data.is_assignable', false);

        $this->patchJson('/api/v1/assets/'.$retired->id.'/status', ['status' => Asset::STATUS_AVAILABLE])
            ->assertStatus(409)
            ->assertJsonPath('message', 'An asset that is retired cannot become available.');

        $fresh = $this->makeAsset();

        // Handing out is not a status change: it opens a hand-over as well
        // as moving the column, and a client that set `assigned` directly
        // would produce an asset nobody could ever return.
        $this->patchJson('/api/v1/assets/'.$fresh->id.'/status', ['status' => Asset::STATUS_ASSIGNED])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Use the assign action to hand an asset to somebody.');
    }

    public function test_bringing_an_assigned_asset_back_is_a_return_not_a_status_change(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', ['employee_id' => $holder->id])->assertOk();

        $this->patchJson('/api/v1/assets/'.$asset->id.'/status', ['status' => Asset::STATUS_AVAILABLE])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Use the return action to bring an asset back.');

        $this->assertDatabaseHas('asset_assignments', [
            'asset_id' => $asset->id,
            'status' => AssetAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_retiring_an_asset_somebody_still_holds_is_refused(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/assets/'.$asset->id.'/assign', ['employee_id' => $holder->id])->assertOk();

        $this->patchJson('/api/v1/assets/'.$asset->id.'/status', ['status' => Asset::STATUS_RETIRED])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Return the asset before retiring it — somebody still holds it.');

        $this->postJson('/api/v1/assets/'.$asset->id.'/return', [
            'returned_condition' => Asset::CONDITION_FAIR,
        ])->assertOk();

        $this->patchJson('/api/v1/assets/'.$asset->id.'/status', ['status' => Asset::STATUS_RETIRED])
            ->assertOk();
    }

    /* -------------------------------------------------------------- editing */

    public function test_an_edit_corrects_the_master_record_but_never_condition_or_status(): void
    {
        $this->signInAs('HR Admin');
        $asset = $this->makeAsset();

        $this->putJson('/api/v1/assets/'.$asset->id, [
            'name' => 'ThinkPad T14 (rev B)',
            'serial_number' => 'PF-12345',
            'purchase_cost' => 3900.00,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'ThinkPad T14 (rev B)')
            ->assertJsonPath('data.serial_number', 'PF-12345');

        $this->putJson('/api/v1/assets/'.$asset->id, [
            'current_condition' => Asset::CONDITION_POOR,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_condition']);

        $this->putJson('/api/v1/assets/'.$asset->id, [
            'status' => Asset::STATUS_LOST,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(
            Asset::CONDITION_GOOD,
            $asset->fresh()->current_condition,
            'Condition changes when an asset is handed over, so it is recorded with the hand-over.',
        );
        $this->assertSame(Asset::STATUS_AVAILABLE, $asset->fresh()->status);
    }

    public function test_a_retired_asset_is_written_off_not_editable(): void
    {
        $this->signInAs('HR Admin');
        $asset = $this->makeAsset(['status' => Asset::STATUS_RETIRED]);

        $this->putJson('/api/v1/assets/'.$asset->id, ['name' => 'Something else'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A retired asset cannot be edited.');

        // …and it is written off rather than dropped: "what did we own in
        // 2026?" has to still be answerable in 2030.
        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'status' => Asset::STATUS_RETIRED]);
        $this->assertDatabaseMissing('assets', ['name' => 'Something else']);
    }

    /* -------------------------------------------------------------- filters */

    public function test_filters_pick_out_the_type_the_state_the_holder_and_the_deadline(): void
    {
        $this->signInAs('HR Admin');
        [, $holder] = $this->makeSeat('Employee');
        [, $late] = $this->makeSeat('Employee');

        $laptop = $this->makeAsset();
        $this->makeAssignment($laptop, $holder);
        $laptop->update(['status' => Asset::STATUS_ASSIGNED]);

        $tool = $this->makeAsset([
            'asset_code' => 'TOOL-1',
            'asset_type_id' => $this->assetType('TOOL')->id,
            'name' => 'Bosch Hammer Drill',
            'current_condition' => Asset::CONDITION_POOR,
        ]);

        $lateAsset = $this->makeAsset(['asset_code' => 'LATE-1', 'name' => 'Sony Handycam']);
        $this->makeAssignment($lateAsset, $late, [
            'expected_return_date' => now()->subDays(3)->toDateString(),
        ]);
        $lateAsset->update(['status' => Asset::STATUS_ASSIGNED]);

        $this->getJson('/api/v1/assets?status=available')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'TOOL-1');

        $this->getJson('/api/v1/assets?status=assigned')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $this->getJson('/api/v1/assets?condition=poor')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'TOOL-1');

        $this->getJson('/api/v1/assets?asset_type_id='.$this->assetType('TOOL')->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'TOOL-1');

        $this->getJson('/api/v1/assets?employee_id='.$holder->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-00001');

        // Only the two that are still out *and* past their date: a hand-back
        // that arrived late is in the past tense, and "overdue" means "what
        // do I have to chase".
        $this->getJson('/api/v1/assets?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'LATE-1');

        $this->getJson('/api/v1/assets?search=ThinkPad')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-00001');
    }

    public function test_an_employee_reads_only_what_has_been_handed_to_them(): void
    {
        [, $me] = $this->signInAs('Employee');
        [, $colleague] = $this->makeSeat('Employee');

        $mine = $this->makeAsset();
        $theirs = $this->makeAsset(['asset_code' => 'COLL-1']);

        $this->makeAssignment($mine, $me);
        $this->makeAssignment($theirs, $colleague);

        $items = $this->getJson('/api/v1/assets')->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame($mine->id, $items[0]['id']);

        $this->getJson('/api/v1/assets/'.$theirs->id)->assertForbidden();

        // …and a piece of the pool nobody has been given is not part of
        // their register either.
        $pool = $this->makeAsset(['asset_code' => 'POOL-2']);

        $this->getJson('/api/v1/assets/'.$pool->id)->assertForbidden();
        $this->assertCount(1, $this->getJson('/api/v1/assets')->assertOk()->json('data.items'));
    }
}
