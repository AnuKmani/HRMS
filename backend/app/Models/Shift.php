<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shift definition.
 *
 * Overnight/night shifts are handled with `crosses_midnight`: when
 * end_time < start_time the shift rolls past 00:00 and duration maths must add
 * 24 hours. The flag is derived automatically so it can be indexed/queried.
 */
class Shift extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'name',
        'code',
        'start_time',
        'end_time',
        'crosses_midnight',
        'break_duration',
        'grace_period',
        'minimum_working_hours',
        'overtime_threshold',
        'status',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'break_duration' => 'integer',
        'grace_period' => 'integer',
        'minimum_working_hours' => 'float',
        'overtime_threshold' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (Shift $shift): void {
            $shift->crosses_midnight = $shift->crossesMidnight();
        });
    }

    /**
     * True when the shift runs past midnight (e.g. 22:00 -> 06:00).
     */
    public function crossesMidnight(): bool
    {
        if (! $this->start_time || ! $this->end_time) {
            return false;
        }

        return $this->seconds($this->end_time) <= $this->seconds($this->start_time);
    }

    /**
     * Total paid duration in minutes, excluding break, honouring the
     * overnight rollover.
     */
    public function durationMinutes(): int
    {
        if (! $this->start_time || ! $this->end_time) {
            return 0;
        }

        $start = $this->seconds($this->start_time);
        $end = $this->seconds($this->end_time);

        if ($end <= $start) {
            $end += 86400;   // roll past midnight
        }

        return (int) max(0, (($end - $start) / 60) - (int) $this->break_duration);
    }

    /**
     * @param  string  $time  HH:MM:SS or HH:MM
     */
    private function seconds(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return ((int) $h) * 3600 + ((int) $m) * 60;
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
