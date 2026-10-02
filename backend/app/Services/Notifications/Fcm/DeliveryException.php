<?php

namespace App\Services\Notifications\Fcm;

/**
 * The send failed for a reason that may go away — a timeout, a 5xx from
 * Google, a rate limit. The job retries these (three attempts, with
 * backoff) and only then lets the queue's failed-job table keep the record.
 */
class DeliveryException extends \RuntimeException {}
