<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A site engineer or site supervisor on a project's team. */
class ProjectMember extends Model
{
    public const ROLE_SITE_ENGINEER = 'site_engineer';

    public const ROLE_SITE_SUPERVISOR = 'site_supervisor';

    protected $fillable = ['project_id', 'employee_id', 'role'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
