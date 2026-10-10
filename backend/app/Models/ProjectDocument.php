<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A drawing, contract, permit or other file kept with a project. */
class ProjectDocument extends Model
{
    public const CATEGORIES = ['drawing', 'contract', 'permit', 'other'];

    protected $fillable = [
        'project_id', 'title', 'category', 'path', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
