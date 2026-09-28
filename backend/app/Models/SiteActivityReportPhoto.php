<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One private photograph attached to a site activity report.
 *
 * The `path` is a name under a private disk directory and is never
 * serialized: `SiteActivityReportPhotoResource` exposes only the id, the
 * caption and an `index`, and the bytes are reachable through
 * GET .../{report}/photos/{photo} alone, which asks the report's policy
 * first. There is no URL column because a URL would be the answer to
 * "where is this stored?", and that question should only ever be answered
 * by the server deciding whether you may have the file.
 */
class SiteActivityReportPhoto extends Model
{
    protected $fillable = [
        'site_activity_report_id',
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
        return $this->belongsTo(SiteActivityReport::class, 'site_activity_report_id');
    }
}
