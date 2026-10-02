<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sensitive mutation, as the audit screen reads it.
 *
 * The actor's **name** is resolved here rather than left as a bare id:
 * "user 7 approved this" is a fact about a row, "Rania Farouk approved
 * this" is a fact about the company, and the screen has no interest in the
 * number between them. A null user — a scheduler acting on its own — shows
 * as `null` rather than as a fabricated name, because there genuinely was
 * nobody at the keyboard.
 *
 * `old_values` / `new_values` are already redacted at write time and are
 * returned as they stand. `file` is an alias of `auditable_type` shortened
 * to its class basename, so the table can show `LeaveRequest` instead of
 * `App\Models\LeaveRequest` without every caller doing the `basename()`.
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'user' => $log->user === null
                ? null
                : ['id' => $log->user->id, 'name' => $log->user->name],
            'action' => $log->action,
            'module' => $log->module,
            'auditable_type' => $log->auditable_type,
            'record' => [
                'type' => $log->auditable_type,
                'label' => class_basename($log->auditable_type),
                'id' => $log->auditable_id,
            ],
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
