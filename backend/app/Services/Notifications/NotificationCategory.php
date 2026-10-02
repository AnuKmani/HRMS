<?php

namespace App\Services\Notifications;

/**
 * The notification catalogue: which categories exist, and which of them a
 * person is not allowed to switch off.
 *
 * Two levels, deliberately.
 *
 * `type` is the *thing that happened* — `leave.approved`,
 * `document.expiring`. The inbox lists them, the client switches on them,
 * and they are stable strings because the inbox has to survive whatever
 * code next writes to it.
 *
 * `category` is the *setting* — `leave`, `document_expiry`. A person has
 * one switch per category, not per type: twelve unrelated types behind
 * twelve switches is a settings screen nobody reads.
 *
 * **Mandatory categories cannot be turned off.** `security` carries the
 * "your password changed" / "a session was revoked" messages — a user who
 * could mute those could be signed out of every device without ever being
 * told. The API refuses the write rather than storing a row that says no.
 */
final class NotificationCategory
{
    public const ATTENDANCE = 'attendance';

    public const LEAVE = 'leave';

    public const PAYROLL = 'payroll';

    public const EXPENSE = 'expense';

    public const OVERTIME = 'overtime';

    public const DOCUMENT_EXPIRY = 'document_expiry';

    public const TRAINING_EXPIRY = 'training_expiry';

    public const SITE = 'site';

    public const ASSET = 'asset';

    public const SECURITY = 'security';

    public const SYSTEM = 'system';

    /**
     * Every category a person may see on the preferences screen, in the
     * order the screen draws them.
     *
     * @var array<int, string>
     */
    public const ALL = [
        self::ATTENDANCE,
        self::LEAVE,
        self::PAYROLL,
        self::EXPENSE,
        self::OVERTIME,
        self::DOCUMENT_EXPIRY,
        self::TRAINING_EXPIRY,
        self::SITE,
        self::ASSET,
        self::SECURITY,
        self::SYSTEM,
    ];

    /**
     * Categories nobody may mute. `security` is the one that matters —
     * it is how you find out a session of yours was revoked — and `system`
     * is kept alongside it so a future maintenance message cannot be
     * silenced either.
     *
     * @var array<int, string>
     */
    public const MANDATORY = [
        self::SECURITY,
        self::SYSTEM,
    ];

    /**
     * `type` -> `category`. Every type this application can raise must
     * appear here: an unmapped type falls back to `system`, which is
     * mandatory, so a missing row fails *closed* (you cannot be talked out
     * of hearing it) rather than open.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'attendance.reminder' => self::ATTENDANCE,

        'leave.submitted' => self::LEAVE,
        'leave.approved' => self::LEAVE,
        'leave.rejected' => self::LEAVE,
        'leave.lop_converted' => self::LEAVE,
        'leave.sick_certificate_reminder' => self::LEAVE,

        'payroll.slip_available' => self::PAYROLL,
        'payroll.certificate_status' => self::PAYROLL,

        'expense.approved' => self::EXPENSE,
        'expense.rejected' => self::EXPENSE,

        'overtime.approved' => self::OVERTIME,
        'overtime.rejected' => self::OVERTIME,

        'document.expiring' => self::DOCUMENT_EXPIRY,
        'document.expired' => self::DOCUMENT_EXPIRY,

        'training.expiring' => self::TRAINING_EXPIRY,
        'training.expired' => self::TRAINING_EXPIRY,

        'site.assigned' => self::SITE,

        'asset.assigned' => self::ASSET,
        'asset.returned' => self::ASSET,

        'security.password_changed' => self::SECURITY,
        'security.session_revoked' => self::SECURITY,
    ];

    /**
     * The category a type belongs to. An unknown type lands on `system`,
     * which is mandatory — see TYPES for why the fallback is the strict
     * one rather than the permissive one.
     */
    public static function forType(string $type): string
    {
        return self::TYPES[$type] ?? self::SYSTEM;
    }

    public static function exists(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }

    public static function isMandatory(string $category): bool
    {
        return in_array($category, self::MANDATORY, true);
    }
}
