<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One private photograph attached to a daily site report.
 *
 * Same rules as `SiteActivityReportPhoto`: private path, never serialized,
 * reachable only through the report's own policy-checked photo route.
 */
class DailySiteReportPhoto extends Model
{
    protected $fillable = [
        'daily_site_report_id',
        'path',
        'mime_type',
        'size_bytes',
        'caption',
        'sort_order',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'sort_order' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailySiteReport::class, 'daily_site_report_id');
    }
}
