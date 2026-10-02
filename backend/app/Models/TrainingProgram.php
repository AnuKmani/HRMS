<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One configurable training program the company runs.
 *
 * Everything that could have been a constant is a row here: who delivers it,
 * how long it takes, whether a certificate comes out of it, and how long
 * that certificate is good for. A deployment whose first-aid card lasts
 * three years edits `certificate_validity_days` instead of forking a
 * service, which is the whole argument for the table existing.
 *
 * **Programs are not seeded.** The eight `training_types` are a vocabulary
 * and ship with the install; a *program* is an offering somebody chose to
 * run, and inventing a catalogue of them would put rows in a new
 * installation's list that nobody at that company had decided on. HR
 * creates them through `POST /api/v1/training-programs`.
 *
 * `status` is `active` | `retired`. Retiring keeps every enrolment the
 * program already produced readable — the cohorts cannot be un-run — while
 * taking it out of the assign form's picker.
 */
class TrainingProgram extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RETIRED,
    ];

    protected $fillable = [
        'training_type_id',
        'code',
        'name',
        'description',
        'provider',
        'duration_days',
        'certificate_required',
        'certificate_validity_days',
        'status',
    ];

    protected $casts = [
        'training_type_id' => 'integer',
        'duration_days' => 'integer',
        'certificate_required' => 'boolean',
        'certificate_validity_days' => 'integer',
    ];

    /* ----------------------------------------------------------- relations */

    public function trainingType(): BelongsTo
    {
        return $this->belongsTo(TrainingType::class);
    }

    public function employeeTrainings(): HasMany
    {
        return $this->hasMany(EmployeeTraining::class);
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /* ------------------------------------------------------------- helpers */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * How long a certificate issued for this program is good for, or null
     * when it does not expire.
     *
     * Days, always — see the migration for why months were reduced to them
     * rather than carried alongside. Null is a *real* answer meaning "this
     * program's certificate never lapses", which is why it is not folded
     * into `0`: zero would be a certificate that expires the day it is
     * issued.
     */
    public function certificateValidityDays(): ?int
    {
        if (! $this->certificate_required) {
            return null;
        }

        $days = (int) $this->certificate_validity_days;

        return $days > 0 ? $days : null;
    }
}
