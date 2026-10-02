<?php

namespace App\Events;

use App\Models\AssetAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A company asset changed hands — either direction.
 *
 * One event with a `kind` rather than two near-identical classes: the
 * hand-over and the hand-back are the same fact about the same row seen
 * from two ends, and a listener that has to behave differently only in
 * its wording is a listener that should have one place to be written.
 *
 * `kind` is `assigned` or `returned`. Status changes on the asset itself
 * (broken, lost, retired) raise nothing: they are facts about the asset,
 * not about the person who has it, and a push about somebody else's
 * laptop being written off would be noise.
 *
 * After commit, because the assignment row is written in the same
 * transaction that flips the asset's status — a listener reading either
 * one before the commit would see a hand-over that had not happened.
 */
class AssetHandover implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public const ASSIGNED = 'assigned';

    public const RETURNED = 'returned';

    public function __construct(
        public readonly AssetAssignment $assignment,
        public readonly string $kind,
    ) {}
}
