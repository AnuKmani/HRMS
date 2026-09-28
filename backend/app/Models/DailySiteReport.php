<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The official record of one site-day.
 *
 * `site_id + report_date` is unique in the database, so "is this the daily
 * report for Site 7 on 28 September?" always has exactly one answer. The
 * service translates a collision into a 422 on `report_date`; the index
 * exists so that answer survives two requests arriving at once, which no
 * application-level check does.
 *
 * `created_by` is the author, and it — not an `employee_id` — is what the
 * row-level policy reads: this document is prepared by the person who
 * prepares it, and a supervisor filing a report on behalf of a site is
 * doing a job, not recording their own presence.
 *
 * Manpower, materials, equipment and photographs are children, all of them
 * replaced wholesale on update (delete + re-insert inside the same
 * transaction) rather than diffed. A daily report is small, is edited as a
 * whole document, and a partial-diff that leaves an orphan line item would
 * be far harder to notice than a reorder.
 *
 * `total_manpower` is the derived sum of `daily_site_report_manpower`. It
 * is stored, not joined on read, because it is the headline number in the
 * PDF and because a child row edited by hand should not silently change a
 * document that was already submitted. DailySiteReportService owns it.
 */
class DailySiteReport extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
    ];

    protected $fillable = [
        'created_by',
        'project_id',
        'site_id',
        'report_date',
        'total_manpower',
        'work_planned',
        'work_completed',
        'safety_observations',
        'delays',
        'issues',
        'remarks',
        'status',
        'submitted_at',
        'approved_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'total_manpower' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /* ----------------------------------------------------------- relations */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function manpower(): HasMany
    {
        return $this->hasMany(DailySiteReportManpower::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(DailySiteReportMaterial::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(DailySiteReportEquipment::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DailySiteReportPhoto::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /* ------------------------------------------------------------ helpers */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /**
     * "28 September 2026 / Tanah Rata Site" — the name a PDF, a filename
     * and a 409 message all want to say out loud.
     */
    public function reference(): string
    {
        return ($this->report_date?->format('Y-m-d') ?? 'undated')
            .' / '.($this->site?->name ?? 'unknown site');
    }
}
