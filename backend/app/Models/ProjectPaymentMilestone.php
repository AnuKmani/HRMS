<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A billing point: when the project reaches trigger_percent, Sales can
 * raise the payment request. pending -> reached -> requested -> paid.
 */
class ProjectPaymentMilestone extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REACHED = 'reached';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'project_id', 'name', 'trigger_percent', 'amount_percent', 'amount',
        'status', 'reached_at', 'requested_at', 'paid_at', 'notes', 'sort_order',
    ];

    protected $casts = [
        'trigger_percent' => 'decimal:2',
        'amount_percent' => 'decimal:2',
        'amount' => 'decimal:2',
        'reached_at' => 'datetime',
        'requested_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
