<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_ACTIVE,
        self::STATUS_ON_HOLD,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'name',
        'code',
        'client',
        'description',
        'location',
        'project_manager_id',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'primary_project_id');
    }

    public function siteAssignments(): HasMany
    {
        return $this->hasMany(EmployeeSiteAssignment::class);
    }
}
