<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated milestone on a project: DCD inspection, Civil Defence
 * inspection, handover, or anything else worth a date.
 */
class ProjectEvent extends Model
{
    public const TYPE_DCD_INSPECTION = 'dcd_inspection';

    public const TYPE_CIVIL_DEFENCE = 'civil_defence';

    public const TYPE_HANDOVER = 'handover';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_DCD_INSPECTION,
        self::TYPE_CIVIL_DEFENCE,
        self::TYPE_HANDOVER,
        self::TYPE_OTHER,
    ];

    public const LABELS = [
        self::TYPE_DCD_INSPECTION => 'DCD inspection',
        self::TYPE_CIVIL_DEFENCE => 'Civil Defence inspection',
        self::TYPE_HANDOVER => 'Handover',
        self::TYPE_OTHER => 'Other',
    ];

    protected $fillable = [
        'project_id',
        'type',
        'title',
        'event_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'event_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function label(): string
    {
        return $this->title !== null && $this->title !== ''
            ? $this->title
            : (self::LABELS[$this->type] ?? 'Event');
    }
}
