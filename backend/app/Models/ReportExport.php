<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued report export waiting to be, or already, built.
 *
 * Owned by the person who asked for it: `GET /report-exports/{id}/file`
 * re-checks `user_id` before streaming a byte, so guessing an id gets you a
 * 404 rather than somebody else's payroll. `path` is never returned in a
 * payload — the download route is the only reader.
 */
class ReportExport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    public const FORMAT_CSV = 'csv';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_PDF = 'pdf';

    public const FORMATS = [
        self::FORMAT_CSV,
        self::FORMAT_XLSX,
        self::FORMAT_PDF,
    ];

    protected $fillable = [
        'user_id',
        'report_key',
        'format',
        'filters',
        'status',
        'path',
        'row_count',
        'error',
        'completed_at',
    ];

    protected $hidden = [
        'path',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------- relations */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------- helpers */

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
