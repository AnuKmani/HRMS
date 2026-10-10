<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change to a project's progress, kept for the history chart. */
class ProjectProgressUpdate extends Model
{
    public const SOURCE_DAILY_REPORT = 'daily_report';

    public const SOURCE_MANUAL = 'manual';

    public $timestamps = false;

    protected $fillable = [
        'project_id', 'project_stage_id', 'stage_percent', 'project_percent',
        'source', 'daily_site_report_id', 'user_id', 'note', 'created_at',
    ];

    protected $casts = [
        'stage_percent' => 'decimal:2',
        'project_percent' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'project_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
