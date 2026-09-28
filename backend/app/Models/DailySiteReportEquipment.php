<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One equipment line item on a daily site report.
 *
 * Not an asset: no tag, no owner, no maintenance plan. `operating_hours` is
 * a meter reading taken today, not a running total the system maintains.
 */
class DailySiteReportEquipment extends Model
{
    protected $fillable = [
        'daily_site_report_id',
        'equipment_name',
        'quantity',
        'operating_hours',
        'condition',
        'remarks',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'operating_hours' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailySiteReport::class, 'daily_site_report_id');
    }
}
