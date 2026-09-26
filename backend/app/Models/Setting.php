<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single configurable business rule.
 *
 * Values live in the database, never in application constants, so HR can tune
 * grace periods / overtime thresholds / notification timing without a deploy.
 */
class Setting extends Model
{
    use HasFactory;

    public const GROUP_GENERAL = 'general';

    /** Value types understood by Setting::castValue(). */
    public const TYPES = ['string', 'integer', 'boolean', 'decimal', 'json', 'date', 'time'];

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_editable',
    ];

    protected $casts = [
        'is_editable' => 'boolean',
    ];

    /**
     * Convert the raw text value to the PHP type declared in `type`.
     */
    public function castValue(string $value): mixed
    {
        return match ($this->type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
