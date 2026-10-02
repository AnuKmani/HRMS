<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's switch for one notification category.
 *
 * The row only exists when it has been turned **off** — see the migration.
 * `NotificationPreferences::enabledFor()` therefore answers "enabled" for
 * a category nobody has ever written a row for, which is what makes a new
 * category ship opted-in rather than silently muted for every account that
 * has not visited a settings screen.
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /* ----------------------------------------------------------- relations */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
