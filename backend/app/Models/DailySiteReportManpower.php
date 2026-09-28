<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One workforce category in a daily site report.
 *
 * `category` is free text on purpose — see the migration for the full
 * argument — so this model is a counted label and nothing more. It has no
 * enum, no `status` and no `scopeStatuses()`: there is no state machine on
 * a head-count.
 */
class DailySiteReportManpower extends Model
{
    /**
     * The table is `daily_site_report_manpower`, singular, where Eloquent
     * would have guessed `daily_site_report_manpowers`.
     *
     * The migration named it after the *concept* — one report's set of
     * categories, not a pile of independent manpowers — and guessing that
     * from the model class is precisely what pluralisation is for. Naming
     * it here is what makes the guess unnecessary; without this line every
     * read and write fails against a table MySQL has never heard of, which
     * is a confusing way to learn about a naming decision.
     */
    protected $table = 'daily_site_report_manpower';

    protected $fillable = [
        'daily_site_report_id',
        'category',
        'count',
        'sort_order',
    ];

    protected $casts = [
        'count' => 'integer',
        'sort_order' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailySiteReport::class, 'daily_site_report_id');
    }
}
