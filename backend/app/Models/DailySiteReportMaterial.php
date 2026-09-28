<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One material line item on a daily site report.
 *
 * A name, a quantity, a unit and a note — and deliberately nothing that
 * would make it inventory: no stock, no supplier, no cost, no link to
 * anything the line could be valued against.
 */
class DailySiteReportMaterial extends Model
{
    protected $fillable = [
        'daily_site_report_id',
        'material_name',
        'quantity',
        'unit',
        'remarks',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'sort_order' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailySiteReport::class, 'daily_site_report_id');
    }
}
