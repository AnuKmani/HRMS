<?php

namespace App\Services\Expiry;

use App\Services\Documents\DocumentExpiryService;
use App\Services\Training\TrainingExpiryService;
use Closure;

/**
 * The skeleton both expiry scans share, and nothing else.
 *
 * The algorithm is one thing said twice: **select the ids that need acting
 * on, then act on each one inside its own transaction.** Everything else
 * differs and has to — a passport lapsing and a working-at-heights card
 * lapsing are different rows with different statuses, different event
 * classes and (for documents) a warning window that is a property of a
 * *type* rather than a global. Extracting the parts that must differ would
 * mean a service configured by string column names and a model class, which
 * is harder to read than two explicit classes and answers the wrong
 * question.
 *
 * What is shared is therefore the shape, and it is the part worth sharing:
 *
 *  - **the pass runs one transaction per row, never one per batch.** A
 *    poisoned row costs that row rather than the whole scan, and a second
 *    worker that selected the same ids still has to re-read each one under
 *    `lockForUpdate()` before acting — which is each service's
 *    `attemptExpire()` / `attemptWarn()`.
 *  - **the ids are read once, outside the loop.** Both services' idempotency
 *    lives in their *own* selects (expired rows no longer match; warned rows
 *    carry a marker), so the second run of a scan matches nothing before a
 *    single transaction is opened.
 *
 * `attemptExpire` and `attemptWarn` returning `false` is not an error — it
 * is "this row turned out not to need it", which is the normal outcome of
 * re-checking a row another worker got to first. Only the count cares.
 *
 * @see DocumentExpiryService
 * @see TrainingExpiryService
 */
trait ScansExpiringRows
{
    /**
     * Both passes, in the order that matters: lapse first, then warn.
     *
     * Reversed, a row that lapsed *and* was inside its window could be
     * warned about a certificate the same run had just declared dead.
     *
     * @return array{expired: int, warned: int}
     */
    public function scan(): array
    {
        return [
            'expired' => $this->runPass(
                $this->expirableIds(),
                fn (int $id) => $this->attemptExpire($id),
            ),
            'warned' => $this->runPass(
                $this->warnableIds(),
                fn (int $id) => $this->attemptWarn($id),
            ),
        ];
    }

    /**
     * The ids whose date has already passed, selected by this service's own
     * rules so that a second run selects none of them.
     *
     * @return iterable<int>
     */
    abstract protected function expirableIds(): iterable;

    /**
     * The ids inside their warning window that have not been reported yet.
     *
     * @return iterable<int>
     */
    abstract protected function warnableIds(): iterable;

    /**
     * Lapse one row, under a lock, re-checking every condition from the row
     * itself. True when this run is the one that changed it.
     */
    abstract protected function attemptExpire(int $id): bool;

    /**
     * Raise one warning, under a lock, re-checking the marker. True when
     * this run is the one that reported it.
     */
    abstract protected function attemptWarn(int $id): bool;

    /**
     * @param  iterable<int>  $ids
     */
    private function runPass(iterable $ids, Closure $attempt): int
    {
        $count = 0;

        foreach ($ids as $id) {
            if ($attempt((int) $id)) {
                $count++;
            }
        }

        return $count;
    }
}
