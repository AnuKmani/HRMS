<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A weighted work stage (Foundation 15%, Structure 35%, ...). */
class ProjectStage extends Model
{
    /** A starting point the Project Manager can change. */
    public const TEMPLATE = [
        ['name' => 'Mobilisation & site preparation', 'weight' => 5],
        ['name' => 'Foundation', 'weight' => 15],
        ['name' => 'Structure', 'weight' => 35],
        ['name' => 'MEP (electrical, plumbing, HVAC)', 'weight' => 20],
        ['name' => 'Finishing', 'weight' => 20],
        ['name' => 'Testing & handover', 'weight' => 5],
    ];

    protected $fillable = ['project_id', 'name', 'weight', 'percent_complete', 'sort_order'];

    protected $casts = [
        'weight' => 'decimal:2',
        'percent_complete' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
