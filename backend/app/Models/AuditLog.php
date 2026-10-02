<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sensitive mutation, written once and never again.
 *
 * The model has **no `updated_at`** (`UPDATED_AT = null`): an audit trail
 * that can be edited is an accusation nobody can answer, so the row is
 * inserted inside the same transaction as the change it describes and
 * left alone. There is also no `update()` path in the application — the
 * only writes are `AuditLogger::record()` creating a row.
 *
 * `module` and `action` are short strings on purpose (`leave` / `approve`)
 * rather than enums: the catalogue grows as modules do, and a database
 * enum would need a migration to let a future module be auditable.
 *
 * `old_values` and `new_values` arrive **already redacted** — see
 * `AuditLogger::SENSITIVE`. The model does not redact again, because
 * redaction that happens in two places eventually happens in one.
 */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
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

    public function scopeModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeAuditable($query, string $type)
    {
        return $query->where('auditable_type', $type);
    }

    /**
     * Date range on `created_at`, both ends validated upstream.
     *
     * The end is **inclusive**: a filter of "up to the 5th" has to include
     * the 5th, so it is stored as "< 6th at 00:00" rather than "< 5th at
     * 00:00", which would silently drop an entire day's entries.
     */
    public function scopeBetween($query, ?string $from, ?string $to)
    {
        if ($from !== null && $from !== '') {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to !== null && $to !== '') {
            $query->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay());
        }

        return $query;
    }
}
