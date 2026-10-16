<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * An employee's sign-in, issued and re-roled by HR.
 *
 *   GET  /employees/{employee}/account   who this person signs in as
 *   POST /employees/{employee}/account   create the login with one role
 *   PUT  /employees/{employee}/account   change that role
 *
 * The role is the whole of what the person may do: a new hire made a
 * Project Manager here holds `sites.manage` because the role does, and an
 * Employee does not. Nothing on this controller grants a permission
 * directly.
 *
 * Behind `users.manage`. One extra rule on top: only a Super Admin may hand
 * out Super Admin, so HR cannot promote anyone — themselves included —
 * above HR.
 *
 * The password is generated here, returned exactly once, and stored only
 * as a hash. HR passes it on; the person changes it from the app.
 */
class EmployeeAccountController extends Controller
{
    private const SUPER_ADMIN = 'Super Admin';

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $user = $employee->user;

        if ($user === null) {
            return ApiResponse::success('Employee account.', null);
        }

        $body = $this->describe($user);

        // The password HR handed out, while it is still the one in use —
        // only to someone who may manage logins, and every reading is
        // recorded. Once the employee chooses their own it is gone.
        if ($request->user()->can('users.manage') && $user->must_change_password && $user->temporary_password !== null) {
            $body['temporary_password'] = $user->temporary_password;
            $this->audit($user, 'user.temporary_password_viewed', [], []);
        }

        return ApiResponse::success('Employee account.', $body);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        if ($employee->user_id !== null) {
            abort(409, 'This employee already has a login. Change the role instead.');
        }

        $data = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'email' => [
                'nullable', 'string', 'email:rfc', 'max:190',
                Rule::unique('users', 'email'),
            ],
            // HR may choose the first password to hand over (e.g. on paper).
            // It is temporary either way: the employee must replace it with
            // their own at first sign-in, so it only has to be typeable.
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
        ]);

        $this->guardSuperAdmin($request, $data['role']);

        $email = strtolower(trim((string) ($data['email'] ?? $employee->email)));
        if ($email === '' || User::query()->where('email', $email)->exists()) {
            abort(422, 'That email address is already used by another login.');
        }

        $password = filled($data['password'] ?? null)
            ? (string) $data['password']
            : Str::password(12, symbols: false);

        $user = DB::transaction(function () use ($employee, $email, $password, $data) {
            $user = User::query()->create([
                'name' => $employee->full_name,
                'email' => $email,
                'password' => Hash::make($password),
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
                'temporary_password' => $this->readable($password),
            ]);
            $user->syncRoles([$data['role']]);

            $employee->user_id = $user->id;
            $employee->save();

            $this->audit($user, 'user.account_created', [], ['role' => $data['role'], 'email' => $email]);

            return $user;
        });

        return ApiResponse::created('Login created.', $this->describe($user) + [
            // Shown once. Never stored in plain text, never returned again.
            'temporary_password' => $password,
        ]);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $user = $employee->user;

        if ($user === null) {
            abort(404, 'This employee has no login yet. Create one first.');
        }

        $data = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);

        $this->guardSuperAdmin($request, $data['role']);

        if ($user->hasRole(self::SUPER_ADMIN) && ! $request->user()->hasRole(self::SUPER_ADMIN)) {
            abort(403, 'Only a Super Admin can change a Super Admin\'s role.');
        }

        $before = $user->getRoleNames()->values()->all();

        DB::transaction(function () use ($user, $data, $before) {
            $user->syncRoles([$data['role']]);
            // Old tokens carried the old permissions in the app's cache of
            // them; the server re-reads roles on every request anyway, so
            // nothing is revoked here.
            $this->audit($user, 'user.role_changed', ['roles' => $before], ['roles' => [$data['role']]]);
        });

        return ApiResponse::success('Role updated.', $this->describe($user->refresh()));
    }

    /**
     * POST /employees/{employee}/account/reset-password — HR hands out a new
     * temporary password (theirs, or a generated one) for a forgotten one.
     * Every session on the old password ends; the employee must pick their
     * own again at the next sign-in.
     */
    public function resetPassword(Request $request, Employee $employee): JsonResponse
    {
        $user = $employee->user;

        if ($user === null) {
            abort(404, 'This employee has no login yet. Create one first.');
        }

        if ($user->hasRole(self::SUPER_ADMIN) && ! $request->user()->hasRole(self::SUPER_ADMIN)) {
            abort(403, 'Only a Super Admin can reset a Super Admin\'s password.');
        }

        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
        ]);

        $password = filled($data['password'] ?? null)
            ? (string) $data['password']
            : Str::password(12, symbols: false);

        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => true,
                'temporary_password' => $this->readable($password),
            ])->save();
            $user->tokens()->delete();
            $this->audit($user, 'user.password_reset_by_hr', [], []);

            // Their "forgot my password" alert is answered now: clear it from
            // every HR bell, not only the bell of whoever did the reset.
            InAppNotification::query()
                ->where('type', 'security.password_help')
                ->where('dedupe_key', 'like', 'password-help:'.$user->id.':%')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        });

        return ApiResponse::success('Password reset.', $this->describe($user->refresh()) + [
            'temporary_password' => $password,
        ]);
    }

    /**
     * The handed-out password to keep for HR to read back, or null on a
     * server with no APP_KEY (nothing can be encrypted there, so nothing is
     * kept — the login itself still works, and Reset password still does).
     */
    private function readable(string $password): ?string
    {
        return filled(config('app.key')) ? $password : null;
    }

    private function guardSuperAdmin(Request $request, string $role): void
    {
        if ($role === self::SUPER_ADMIN && ! $request->user()->hasRole(self::SUPER_ADMIN)) {
            abort(403, 'Only a Super Admin can make someone a Super Admin.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(User $user): array
    {
        return [
            'user_id' => $user->id,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            // true while the HR-issued password is still the one in use.
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(User $user, string $action, array $old, array $new): void
    {
        AuditLog::query()->create([
            'user_id' => request()->user()?->id,
            'action' => $action,
            'module' => 'users',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->id,
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 400),
            'created_at' => Carbon::now(),
        ]);
    }
}
