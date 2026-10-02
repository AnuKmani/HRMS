<?php

namespace App\Services\Notifications\Fcm;

/**
 * Firebase told us this handset is gone.
 *
 * Distinct from `DeliveryException` on purpose: a stale token must never
 * be retried — retrying is exactly how a bug turns into a queue full of
 * messages for a handset that was recycled last week — so the job
 * deactivates the row and moves on. Everything else is worth three goes.
 */
class StaleTokenException extends \RuntimeException {}
