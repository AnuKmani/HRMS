<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row in somebody's in-app notification inbox.
 *
 * Named `InAppNotification` rather than `Notification` because
 * `Illuminate\Notifications\Notification` is the *class* every Laravel
 * notification extends, and two things called `Notification` in one
 * codebase is a mistake somebody makes at 3am. The table it reads is still
 * `notifications`, because that is what the table is.
 *
 * `data` carries ids and a route and nothing else — see the migration for
 * why a salary figure or a document's contents must never be pasted into
 * `body` or `data`. Tapping a row navigates to a screen that re-fetches
 * under the reader's own permissions; the notification is a pointer, not a
 * copy.
 */
class InAppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'dedupe_key',
        'read_at',
    ];

    protected $hidden = [
        // Nothing sensitive lives here, but an inbox is a list endpoint
        // that is read far more often than it is audited, so the payload
        // stays out of dumps and logs by default.
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------- relations */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* -------------------------------------------------------------- scopes */

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /* ------------------------------------------------------------- helpers */

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function markRead(): bool
    {
        if (! $this->isUnread()) {
            return false;
        }

        return (bool) $this->forceFill(['read_at' => now()])->save();
    }
}
