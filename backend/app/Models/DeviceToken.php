<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One install of the app, and the Firebase token it is currently showing.
 *
 * The pair (`user_id`, `device_identifier`) is what identifies the row;
 * `fcm_token` is the thing that changes underneath it. Registering is
 * therefore an upsert on the pair — same handset signing in again, token
 * rotated by Firebase, last_seen_at refreshed — and never an insert.
 *
 * `active` is the answer to a stale token. When Firebase replies
 * `UNregistered` the push job flips it to false and the row is never used
 * again; it is kept rather than deleted so "this handset stopped receiving
 * on the 3rd" remains answerable.
 *
 * The token itself is deliberately absent from every resource in the app.
 * It is a bearer credential for somebody else's handset: `DeviceToken`
 * hides it, `DeviceTokenResource` never reads it, and no list endpoint
 * returns it.
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'device_identifier',
        'platform',
        'fcm_token',
        'app_version',
        'last_seen_at',
        'active',
    ];

    protected $hidden = [
        'fcm_token',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------- relations */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /* ------------------------------------------------------------- helpers */

    public function deactivate(): bool
    {
        if (! $this->active) {
            return false;
        }

        return (bool) $this->forceFill(['active' => false])->save();
    }
}
