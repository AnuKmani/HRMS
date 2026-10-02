<?php

namespace App\Services\Audit;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeOnboarding;
use App\Models\EmployeeSiteAssignment;
use App\Models\EmployeeTraining;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The one place an `audit_logs` row is ever created.
 *
 * It is not called from controllers. It hangs off Eloquent's
 * `created` / `updated` / `deleted` events for a fixed list of fifteen
 * models — registered once in `AppServiceProvider::registerAuditLogging()`
 * — so "employee, credential, attendance, leave, overtime, payroll, loan,
 * expense, document, onboarding, training, asset" is audited because those
 * models changed, not because fifteen endpoints remembered to say so. A
 * sixteenth module becomes auditable by adding one line to `MODULES` and
 * one to `AUDITED`, which is the whole reason this architecture was chosen
 * over scattered `Audit::write()` calls.
 *
 * **How to read a row.** `module` and `action` are two halves of one name:
 * the page an auditor is looking at is `module`.`action`. `leave` +
 * `approval` is "who approved this leave", `payroll` + `lock` is "who
 * froze this run", `credential` + `password_change` is "whose password
 * changed and who changed it". Keeping them as two columns is what makes
 * `GET /audit-logs?module=payroll&action=lock` and `?action=approval`
 * (every approval in the system, whatever it was of) both possible from
 * the same index.
 *
 * The names are not free-form: {@see self::MODULES} decides the left half
 * and {@see self::actionFor} decides the right, so two rows written a year
 * apart by different code paths still agree.
 *
 * **Redaction happens here and only here.** `old_values` and `new_values`
 * arrive from the model already filtered for bookkeeping columns and are
 * passed through `redact()` before they touch the row, so the table never
 * holds a password, a token, a file path or a bank detail. Doing it at the
 * single write point means there is no second writer to forget.
 *
 * The actor is `auth()->id()` — the session that caused the change. A
 * scheduled job has no session, and its rows honestly carry a null
 * `user_id` rather than being attributed to whatever account the worker
 * happens to hold.
 */
class AuditLogger
{
    /**
     * Model class -> module label. A model absent from this list falls back
     * to its table name, so forgetting an entry costs a label rather than
     * an audit trail.
     *
     * Labels are singular and match the *left half* of the names in the
     * audit spec: `module` + `.` + `action` reads back as one dotted name
     * (`leave` + `approval` -> "leave.approval", `credential` +
     * `password_change` -> "credential.password_change"). Two models may
     * share a label when they are the same subject seen from two angles —
     * `Asset` and `AssetAssignment` are both `asset`, because "the laptop"
     * and "who has the laptop" are one story.
     *
     * @var array<class-string, string>
     */
    public const MODULES = [
        Employee::class => 'employee',
        EmployeeSiteAssignment::class => 'site',
        LeaveRequest::class => 'leave',
        OvertimeRequest::class => 'overtime',
        Payroll::class => 'payroll',
        PayrollAdjustment::class => 'payroll',
        Loan::class => 'loan',
        Expense::class => 'expense',
        EmployeeDocument::class => 'document',
        EmployeeOnboarding::class => 'onboarding',
        EmployeeTraining::class => 'training',
        Asset::class => 'asset',
        AssetAssignment::class => 'asset',
        User::class => 'credential',
        Attendance::class => 'attendance',
    ];

    /**
     * The models whose writes are audited, in the order the list is read.
     *
     * This is the whole coverage list. Adding a sixteenth module means one
     * entry here and one in `MODULES` — no controller to find, no service to
     * remember, no `Audit::write()` to paste at the right line. The
     * listeners themselves are registered once, in
     * `AppServiceProvider::registerAuditLogging()`.
     *
     * @var array<int, class-string>
     */
    public const AUDITED = [
        Employee::class,
        EmployeeSiteAssignment::class,
        LeaveRequest::class,
        OvertimeRequest::class,
        Payroll::class,
        PayrollAdjustment::class,
        Loan::class,
        Expense::class,
        EmployeeDocument::class,
        EmployeeOnboarding::class,
        EmployeeTraining::class,
        Asset::class,
        AssetAssignment::class,
        User::class,
        Attendance::class,
    ];

    /**
     * A status that was just written -> the mutation an operator reads.
     *
     * Without this, every approval in the system would appear as `update`
     * and "show me everything that was approved last week" would be a LIKE
     * query over a column nobody agreed on. The map is deliberately small:
     * it covers the statuses this schema actually uses, and an unknown one
     * falls back to `update`, which is true even when it is unhelpful.
     *
     * The noun forms (`approval`, not `approve`) are the spec's: the right
     * half of a row's name is meant to be read as `module`.`action`, and
     * "leave.approval" is a thing that happened, "leave.approve" is a
     * button. `lock` and `calculate` stay verbs because the spec spells
     * them that way — `payroll.lock`, `payroll.calculate`.
     *
     * @var array<string, string>
     */
    public const STATUS_ACTIONS = [
        'approved' => 'approval',
        'rejected' => 'reject',
        'locked' => 'lock',
        'processed' => 'process',
        'reviewed' => 'review',
        'calculated' => 'calculate',
        'completed' => 'complete',
        'verified' => 'verify',
        'archived' => 'archive',
        'cancelled' => 'cancel',
        'canceled' => 'cancel',
        'lop' => 'convert_to_lop',
        'assigned' => 'assignment',
        'returned' => 'returns',
        'retired' => 'retire',
        'maintenance' => 'send_to_maintenance',
        'damaged' => 'mark_damaged',
        'lost' => 'mark_lost',
        'resigned' => 'resign',
        'terminated' => 'terminate',
        'inactive' => 'deactivation',
        'active' => 'activate',
        'closed' => 'close',
        'ended' => 'close',
        'manually_adjusted' => 'manual_adjustment',
    ];

    /**
     * Columns that are bookkeeping rather than a change anybody acted on.
     *
     * `expiry_notified_at` is the important one: the daily scans stamp it
     * once per row forever, and without this list every scan would file an
     * `update` against every expiring document in the company.
     *
     * @var array<int, string>
     */
    public const IGNORED = [
        'updated_at',
        'expiry_notified_at',
        'certificate_checked_at',
        'current_approval_step',
        'remember_token',
        'deleted_at',
    ];

    /**
     * Exact column names that are never written, whatever they hold.
     *
     * @var array<int, string>
     */
    public const SENSITIVE = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'fcm_token',
        'device_token',
        'api_token',
        'secret',
        'private_key',
        'credentials',
        'iban',
        'account_number',
        'bank_name',
        'bank_branch',
        'swift',
        'account_holder',
        'card_number',
        'cvv',
    ];

    /**
     * Column-name patterns that are never written, matched case-insensitively.
     *
     * The `_path` rule is the one worth explaining: a private-storage path
     * is not itself a secret — it grants nothing without the API that
     * serves it — but it is a fact about where a passport scan lives, and an
     * audit trail read by four roles does not need to be a map of the
     * private disk. Redacting the *key's* value keeps the fact that the file
     * was attached, which is what matters.
     *
     * @var array<int, string>
     */
    public const SENSITIVE_PATTERNS = [
        '/password/i',
        '/token/i',
        '/secret/i',
        '/credential/i',
        '/_path$/i',
        '/^bank_/i',
        '/iban/i',
        '/api[_-]?key/i',
        '/authorization/i',
        '/selfie/i',
        '/^signature$/i',
    ];

    /**
     * Write one row.
     *
     * @param  array<string, mixed>  $old  values before the change
     * @param  array<string, mixed>  $new  values after the change
     */
    public function record(Model $model, string $event, array $old, array $new): ?AuditLog
    {
        $old = $this->clean($old);
        $new = $this->clean($new);

        if ($event === 'updated' && $old === [] && $new === []) {
            // Nothing survived the filter: a bookkeeping-only save, or one
            // where both sides redacted to the same thing. Filing it would
            // put a row in front of an auditor that says nothing happened.
            return null;
        }

        $action = $this->actionFor($model, $event, $old, $new);

        if ($action === null) {
            // The model is audited in general but this particular write is
            // not a sensitive mutation — an employee checking out of their
            // own day, for instance. See {@see self::actionFor()}.
            return null;
        }

        return AuditLog::query()->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => $this->moduleFor($model),
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 400),
            'created_at' => Carbon::now(),
        ]);
    }

    /* ------------------------------------------------------------ helpers */

    public function moduleFor(Model $model): string
    {
        return self::MODULES[$model::class]
            ?? $model->getTable();
    }

    /**
     * Name the mutation, or return null when this write is not one.
     *
     * Two jobs in one method, and both belong here rather than at the
     * call site:
     *
     *  1. **Naming.** `module` + `.` + `action` is the name an auditor
     *     reads, so the right half has to be specific enough to filter on.
     *     `create` on a payroll row says less than `calculate`, and
     *     `update` on a salary says nothing at all — hence the branches
     *     below, each one the answer to "what actually happened here?".
     *
     *  2. **Deciding.** Some writes on an audited model are not sensitive
     *     mutations: an employee checking out of their own day is them
     *     recording a fact about themselves, and a `remember_token` refresh
     *     is bookkeeping. Those return null and no row is written, so the
     *     trail stays a list of things somebody did *to* something rather
     *     than a copy of every write in the database.
     *
     * @param  array<string, mixed>  $old  surviving values before the change
     * @param  array<string, mixed>  $new  surviving values after the change
     */
    public function actionFor(Model $model, string $event, array $old, array $new): ?string
    {
        return match (true) {
            $model instanceof User => $this->credentialAction($event, $new),
            $model instanceof Employee => $this->employeeAction($event, $new),
            $model instanceof Attendance => $this->attendanceAction($model, $event),
            $model instanceof Payroll => $this->payrollAction($event, $old, $new),
            $model instanceof AssetAssignment => $this->assignmentAction($event, $new),
            $model instanceof Asset => $this->assetAction($event, $old, $new),
            default => $this->genericAction($event, $old, $new),
        };
    }

    /**
     * `create` / `update` / `delete`, refined by whatever status landed.
     */
    private function genericAction(string $event, array $old, array $new): ?string
    {
        if ($event === 'created') {
            return 'create';
        }

        if ($event === 'deleted') {
            return 'delete';
        }

        foreach (['status', 'employment_status'] as $key) {
            if (! array_key_exists($key, $new)) {
                continue;
            }

            $was = $old[$key] ?? null;
            $is = $new[$key];

            if ($was !== null && $was === $is) {
                continue;
            }

            return self::STATUS_ACTIONS[(string) $is] ?? 'update';
        }

        return 'update';
    }

    /**
     * credential.* — the accounts, as opposed to the people.
     *
     * A password write is detected by the *key*, not the value: `password`
     * survives `clean()` with its contents replaced by `[redacted]`, which
     * is exactly the shape this method needs — proof a password changed,
     * with nothing about what it changed to. It outranks the status check
     * because a reset writes both a new password and (sometimes) a fresh
     * `email_verified_at`, and the password is the event.
     */
    private function credentialAction(string $event, array $new): ?string
    {
        if ($event === 'created') {
            return 'create';
        }

        if ($event === 'deleted') {
            return 'delete';
        }

        if (array_key_exists('password', $new)) {
            return 'password_change';
        }

        return $this->genericAction($event, [], $new);
    }

    /**
     * employee.* — `salary` and `employment_status` outrank a plain update,
     * because those are the two fields that decide what somebody is paid
     * and whether they still work here.
     *
     * Salary is checked first deliberately. A single save can carry both a
     * raise and a promotion, and if only one name fits the row, the one an
     * auditor opens the file for is the money. The other half is still
     * visible in `new_values` — the name is a filter, not a summary.
     */
    private function employeeAction(string $event, array $new): ?string
    {
        if ($event === 'created') {
            return 'create';
        }

        if ($event === 'deleted') {
            return 'delete';
        }

        if (array_key_exists('salary', $new)) {
            return 'salary_change';
        }

        if (array_key_exists('employment_status', $new)) {
            return 'status_change';
        }

        return 'update';
    }

    /**
     * attendance.manual_adjustment — a recorded day being rewritten.
     *
     * The test is *who is writing*, not *which column changed*: check-in
     * creates the row and check-out closes it, and both routes are
     * self-service, so the actor is always the very person the record
     * describes. Anything else — an HR correction, a scheduled job
     * revising a day, the reserved `manually_adjusted` status — is
     * somebody changing a fact about somebody else, and that is the one
     * attendance write an audit trail exists to hold.
     *
     * Enumerating "the columns a check-out touches" was the obvious
     * alternative and it is worse: it goes stale the first time a new
     * metric is computed, and every missed column becomes a flood of
     * noise. A person can be compared; a column list rots.
     *
     * `created` never reaches that comparison — the row does not exist
     * until the save, so there is no owner to compare against yet, and
     * "the employee checked in" is not a mutation made to them.
     */
    private function attendanceAction(Model $model, string $event): ?string
    {
        if ($event === 'created') {
            return null;
        }

        if ($event === 'deleted') {
            return 'delete';
        }

        $ownerId = $model->employee?->user_id;
        $actorId = Auth::id();

        if ($actorId !== null && $ownerId !== null && (int) $actorId === (int) $ownerId) {
            return null;
        }

        return 'manual_adjustment';
    }

    /**
     * payroll.calculate / payroll.lock.
     *
     * A payroll row is born calculated — `process()` writes the whole run —
     * so `create` would name the storage and not the thing. And `locked_at`
     * is checked before the status map because `lock()` writes both, and
     * "this run can never change again" is the fact worth filtering on; a
     * status that happens to read `locked` in some other context would be a
     * false promise if it were the only signal.
     */
    private function payrollAction(string $event, array $old, array $new): ?string
    {
        if ($event === 'created') {
            return 'calculate';
        }

        if ($event === 'deleted') {
            return 'delete';
        }

        if (array_key_exists('locked_at', $new) || ($new['status'] ?? null) === Payroll::STATUS_LOCKED) {
            return 'lock';
        }

        if (array_key_exists('status', $new) && ($old['status'] ?? null) !== $new['status']) {
            return self::STATUS_ACTIONS[(string) $new['status']] ?? 'calculate';
        }

        return 'calculate';
    }

    /**
     * asset.assignment / asset.returns — the two ends of one handover.
     *
     * The assignment row is where the handover is *recorded* (who, when,
     * in what condition), so its creation is named for the handover rather
     * than for the insert. Its return is the other end of the same story.
     */
    private function assignmentAction(string $event, array $new): ?string
    {
        if ($event === 'created') {
            return 'assignment';
        }

        if ($event === 'deleted') {
            return 'delete';
        }

        if (($new['status'] ?? null) === AssetAssignment::STATUS_RETURNED) {
            return 'returns';
        }

        return 'assignment';
    }

    /**
     * The asset half of the same handover.
     *
     * `assigned` -> `available` is a thing leaving and coming back; every
     * other status transition on an asset (maintenance, damaged, lost,
     * retired) describes what happened to the object, not who had it, and
     * the generic status map already names those.
     */
    private function assetAction(string $event, array $old, array $new): ?string
    {
        if ($event === 'created') {
            return 'create';
        }

        if ($event === 'deleted') {
            return 'delete';
        }

        if (($new['status'] ?? null) === Asset::STATUS_AVAILABLE
            && ($old['status'] ?? null) === Asset::STATUS_ASSIGNED) {
            return 'returns';
        }

        return $this->genericAction($event, $old, $new);
    }

    /**
     * Drop bookkeeping columns, then redact what is left.
     *
     * Order matters: filtering first means a redacted *key* never makes it
     * into the values in the first place, and a column that is both
     * bookkeeping and sensitive costs one lookup rather than two rules.
     *
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::IGNORED, true)) {
                continue;
            }

            $clean[$key] = $this->isSensitive($key) ? '[redacted]' : $value;
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        if (in_array(strtolower($key), self::SENSITIVE, true)) {
            return true;
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
