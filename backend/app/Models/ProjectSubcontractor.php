<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A subcontractor on a project. Removed, never deleted: the history stays. */
class ProjectSubcontractor extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'project_id', 'project_stage_id', 'name', 'trade', 'contact_name', 'contact_phone',
        'contract_value', 'billed_amount', 'start_date', 'end_date', 'status',
        'removed_on', 'removed_reason', 'removed_by',
    ];

    protected $casts = [
        'contract_value' => 'decimal:2',
        'billed_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'removed_on' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The system (stage) this subcontractor installs. */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'project_stage_id');
    }
}
