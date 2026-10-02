<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes an asset or a hand-over, and what it refuses.
 *
 * Creation, editing, assignment, return and every status change route
 * through here. Two reasons, and the second is the one the brief asks for:
 * the invariants are *concurrency* invariants and cannot live in a
 * controller that reads a row and writes it back a moment later; and Phase
 * 12's audit logging wants one seam rather than five endpoints to hook.
 *
 * **The three rules that need a lock rather than a check:**
 *
 *  - **An asset out on somebody's desk cannot be handed to somebody else.**
 *    `assign()` takes a `lockForUpdate()` on the asset, then re-reads for an
 *    active assignment *inside* that lock. Two simultaneous assigns both
 *    take the lock; the second waits, re-reads, and finds the row the first
 *    one wrote. There is no partial unique index MySQL could express this
 *    with — see the migration — so the guarantee is made here rather than
 *    hoped for.
 *  - **A hand-back needs something to hand back.** `returnAsset()` refuses
 *    an asset with no active assignment, and refuses a second return of the
 *    same row: the first return already flipped `status`, so the re-read
 *    under the lock finds `returned` and says so.
 *  - **A status may only walk to a state the map allows.**
 *    {@see Asset::TRANSITIONS} is the whole vocabulary; this class is the
 *    only writer, so "which sequence turns an available laptop into a
 *    retired one" has exactly one answer.
 *
 * **Nothing is ever deleted.** An assignment is closed, not removed; an
 * asset is retired, not dropped. "Who had this in March?" has to still be
 * answerable in November, and a hard delete is how that stops being true.
 *
 * Refusals are **409 naming the state**, because "that laptop is already
 * out with somebody" and "you may not do that" are different sentences and
 * only one says what to do next.
 */
class AssetService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Asset
    {
        $asset = new Asset([
            'asset_code' => $data['asset_code'],
            'asset_type_id' => $data['asset_type_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'model' => $data['model'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'purchase_cost' => $data['purchase_cost'] ?? null,
            'current_condition' => $data['current_condition'] ?? Asset::CONDITION_GOOD,
            'status' => Asset::STATUS_AVAILABLE,
            'notes' => $data['notes'] ?? null,
        ]);

        $asset->save();

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Asset $asset, array $data): Asset
    {
        // Retired is terminal: correcting a retired laptop's serial number
        // would be editing history, and a record somebody wrote off should
        // not be quietly editable afterwards.
        if ($asset->status === Asset::STATUS_RETIRED) {
            abort(409, 'A retired asset cannot be edited.');
        }

        $asset->asset_code = $data['asset_code'] ?? $asset->asset_code;
        $asset->asset_type_id = $data['asset_type_id'] ?? $asset->asset_type_id;
        $asset->name = $data['name'] ?? $asset->name;
        $asset->description = $this->pick($data, 'description', $asset->description);
        $asset->serial_number = $this->pick($data, 'serial_number', $asset->serial_number);
        $asset->manufacturer = $this->pick($data, 'manufacturer', $asset->manufacturer);
        $asset->model = $this->pick($data, 'model', $asset->model);
        $asset->purchase_date = $this->pick($data, 'purchase_date', $asset->purchase_date);
        $asset->purchase_cost = $this->pick($data, 'purchase_cost', $asset->purchase_cost);
        $asset->notes = $this->pick($data, 'notes', $asset->notes);

        // Condition and status are NOT part of an edit: they change through
        // assign, return and changeStatus, each of which records *who* and
        // *when*. Accepting them here would be a back door past all three.
        $asset->save();

        return $asset;
    }

    /**
     * Hand an asset to somebody.
     *
     * @param  array<string, mixed>  $data
     */
    public function assign(User $actor, Asset $asset, array $data): AssetAssignment
    {
        return DB::transaction(function () use ($actor, $asset, $data) {
            /** @var Asset $fresh */
            $fresh = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Asset::STATUS_RETIRED) {
                abort(409, 'A retired asset cannot be assigned.');
            }

            if (! $fresh->isAvailable()) {
                abort(409, sprintf(
                    'That asset is not available — it is currently %s.',
                    str_replace('_', ' ', $fresh->status),
                ));
            }

            // Inside the lock, and therefore a real guarantee: the row the
            // first of two concurrent assigns is writing cannot be missed.
            $active = AssetAssignment::query()
                ->where('asset_id', $fresh->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                abort(409, sprintf(
                    'That asset is already assigned to %s.',
                    $active->employee?->full_name ?? 'somebody',
                ));
            }

            $assignment = new AssetAssignment([
                'asset_id' => $fresh->id,
                'employee_id' => $data['employee_id'],
                'assigned_date' => $data['assigned_date'] ?? Carbon::today()->toDateString(),
                'expected_return_date' => $data['expected_return_date'] ?? null,
                // Defaulted from the asset rather than required: the
                // condition at hand-out is *usually* the condition it is
                // in, and a form that has to retype it will eventually
                // record a guess instead of a fact.
                'assigned_condition' => $data['assigned_condition'] ?? $fresh->current_condition,
                'assigned_by' => $actor->id,
                'status' => AssetAssignment::STATUS_ACTIVE,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $assignment->save();

            $fresh->status = Asset::STATUS_ASSIGNED;
            $fresh->save();

            return $assignment;
        });
    }

    /**
     * Take an asset back, and record what came back.
     *
     * @param  array<string, mixed>  $data
     */
    public function returnAsset(User $actor, Asset $asset, array $data): AssetAssignment
    {
        return DB::transaction(function () use ($actor, $asset, $data) {
            /** @var Asset $fresh */
            $fresh = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            $active = AssetAssignment::query()
                ->where('asset_id', $fresh->id)
                ->active()
                ->lockForUpdate()
                ->first();

            // The re-read is what makes a *second* return refused rather
            // than merely unlikely: the first one flipped this row to
            // `returned` inside its own lock, so there is nothing active
            // left to find.
            if ($active === null) {
                abort(409, 'That asset is not currently assigned, so there is nothing to return.');
            }

            $returnedCondition = $data['returned_condition'] ?? $fresh->current_condition;

            $active->returned_date = $data['returned_date'] ?? Carbon::today()->toDateString();
            $active->returned_condition = $returnedCondition;
            $active->returned_by = $actor->id;
            $active->status = AssetAssignment::STATUS_RETURNED;

            if (! empty($data['remarks'])) {
                $active->remarks = $data['remarks'];
            }

            $active->save();

            // What came back *is* the asset's condition now — condition
            // tracking is this line, not a second table.
            $fresh->current_condition = $returnedCondition;
            // Back on the shelf unless it came back needing work: a tool
            // handed in broken should not be offered to the next person
            // simply because the hand-back succeeded.
            $fresh->status = $returnedCondition === Asset::CONDITION_POOR
                ? Asset::STATUS_MAINTENANCE
                : Asset::STATUS_AVAILABLE;
            $fresh->save();

            return $active;
        });
    }

    /**
     * Move an asset's lifecycle status — maintenance, damaged, lost,
     * retired — under a lock and under {@see Asset::TRANSITIONS}.
     *
     * @param  array<string, mixed>  $data
     */
    public function changeStatus(User $actor, Asset $asset, string $status, array $data = []): Asset
    {
        if (! in_array($status, Asset::STATUSES, true)) {
            abort(422, sprintf(
                'Unknown asset status. Expected one of: %s.',
                implode(', ', Asset::STATUSES),
            ));
        }

        return DB::transaction(function () use ($asset, $status, $data) {
            /** @var Asset $fresh */
            $fresh = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($status === Asset::STATUS_ASSIGNED) {
                abort(409, 'Use the assign action to hand an asset to somebody.');
            }

            if ($status === Asset::STATUS_AVAILABLE && $fresh->status === Asset::STATUS_ASSIGNED) {
                abort(409, 'Use the return action to bring an asset back.');
            }

            // **Before** the transition map, not after: `assigned` is not a
            // state `TRANSITIONS` allows to become `retired`, so the
            // generic sentence would fire first and the more useful one —
            // the one that names what to *do* — would sit below it
            // unreachable. A reader deserves the actionable refusal.
            //
            // Writing off an asset somebody still holds would strand the
            // hand-over: the person would be left holding a row that no
            // longer exists as far as the register is concerned. Return it
            // first, and then retire it.
            $hasOpenAssignment = AssetAssignment::query()
                ->where('asset_id', $fresh->id)
                ->active()
                ->exists();

            if ($status === Asset::STATUS_RETIRED && $hasOpenAssignment) {
                abort(409, 'Return the asset before retiring it — somebody still holds it.');
            }

            if (! $fresh->canTransitionTo($status)) {
                abort(409, sprintf(
                    'An asset that is %s cannot become %s.',
                    str_replace('_', ' ', $fresh->status),
                    str_replace('_', ' ', $status),
                ));
            }

            $fresh->status = $status;

            if (! empty($data['current_condition'])
                && in_array($data['current_condition'], Asset::CONDITIONS, true)) {
                $fresh->current_condition = $data['current_condition'];
            }

            if (! empty($data['notes'])) {
                $fresh->notes = $data['notes'];
            }

            $fresh->save();

            return $fresh;
        });
    }

    private function pick(array $data, string $key, mixed $current): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : $current;
    }
}
