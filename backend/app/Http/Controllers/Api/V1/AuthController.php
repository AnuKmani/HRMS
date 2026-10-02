<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST /auth/login, /auth/logout, /auth/change-password, GET /auth/me plus
 * the session endpoints that make device management possible later.
 *
 * Stateless by design: nothing here writes to the `sessions` table or touches
 * the web guard, because the Flutter app authenticates with a bearer token.
 * See docs/SECURITY.md §"Sanctum token flow".
 */
class AuthController extends Controller
{
    /**
     * Exchange credentials for a bearer token.
     *
     * Deliberately does NOT use Auth::attempt(): that logs the user into the
     * session guard and writes a row to `sessions`, which is meaningless for a
     * token API. Hash::check performs the same bcrypt comparison with no side
     * effects.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        $user = User::query()->where('email', $credentials['email'])->first();

        // One identical answer for "no such user" and "wrong password" so the
        // endpoint cannot be used to enumerate registered addresses.
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            Log::warning('auth.login_failed', [
                'email' => (string) $credentials['email'],
                'ip' => $request->ip(),
            ]);

            throw new AuthenticationException('The email or password you entered is incorrect.');
        }

        // Reached only after the password verified, so telling this account
        // apart costs nothing to an attacker who does not already know it.
        if (! $user->canLogIn()) {
            Log::warning('auth.login_deactivated', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            throw new AuthenticationException('This account has been deactivated. Contact your administrator.');
        }

        $device = $this->deviceName($request);

        // One live token per device. Signing in again on the same handset
        // replaces its previous session rather than orphaning a token nobody
        // can see or revoke any more.
        $user->tokens()->where('name', $device)->delete();

        /** @var NewAccessToken $newToken */
        $newToken = $user->createToken($device);

        $user->load(['employee.department', 'employee.designation']);

        // Structured, and deliberately thin: who, when, from where. No
        // password, no token, no abilities — the three things that turn an
        // auth log into the most valuable file on the box. The *failures*
        // below do carry the attempted address, because "somebody is
        // walking a list of emails against us from 41.200.1.9" is the
        // incident this log exists to make visible, and an address that
        // never authenticated is not a credential.
        Log::info('auth.login', [
            'user_id' => $user->id,
            'device' => $device,
            'ip' => $request->ip(),
        ]);

        return ApiResponse::success('Signed in successfully.', [
            'token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Who am I, and what am I allowed to do.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['employee.department', 'employee.designation']);

        return ApiResponse::success('Authenticated user.', new UserResource($user));
    }

    /**
     * Revoke the token this request presented — the current session only.
     * Other devices stay signed in; use sessions() to end those individually.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            Log::info('auth.logout', [
                'user_id' => $request->user()->id,
                'token_id' => $token->id,
                'ip' => $request->ip(),
            ]);

            $token->delete();
        }

        return ApiResponse::success('Signed out successfully.');
    }

    /**
     * Change the password and force every *other* device to sign in again.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        // Only possible when the caller presented a bearer token. If the
        // current token cannot be identified we revoke nothing rather than
        // accidentally ending the session we are talking on.
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $user->tokens()->where('id', '!=', $current->id)->delete();
        }

        Log::info('auth.password_changed', [
            'user_id' => $user->id,
            'revoked_others' => $current instanceof PersonalAccessToken,
            'ip' => $request->ip(),
        ]);

        return ApiResponse::success('Password updated. Other devices have been signed out.');
    }

    /**
     * List this account's live sessions so a user can see where they are
     * signed in. Exposes identifiers and timestamps only — never the stored
     * hash, never abilities beyond "this token exists".
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;

        $sessions = $user->tokens()
            ->select(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'device' => $token->name,
                'is_current' => $token->id === $currentId,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])
            ->values();

        return ApiResponse::success('Active sessions.', $sessions);
    }

    /**
     * Revoke one of this account's sessions.
     *
     * 404 rather than 403 when the id belongs to someone else: a 403 would
     * confirm the token exists on another account.
     */
    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->first();

        if ($token === null) {
            abort(404, 'Session not found.');
        }

        $token->delete();

        return ApiResponse::success('Session revoked.');
    }

    /**
     * Revoke **every** session on this account, including the one that is
     * making this request.
     *
     * The current token is not spared, and that is deliberate: this is
     * the endpoint behind "sign out everywhere", behind a password change
     * performed from a machine the user no longer trusts, and behind the
     * offline-logout catch-up in docs/SECURITY.md — all three of which
     * want the same answer, *no session survives this call*. A "keep the
     * caller alive" flag would make the safety property conditional on a
     * parameter, and a parameter is something a confused deputy can pass.
     *
     * The response is therefore the last thing this token will ever carry.
     * Clients call it, read the 200, and clear their own credentials —
     * which is exactly what the Flutter side does, and what makes the
     * offline case work: clear local state immediately, record that a
     * revocation is owed, and issue this call the moment there is a
     * network again.
     *
     * The remaining limitation is real and is documented rather than
     * papered over: a device that cleared its credentials must be able to
     * authenticate again before it can revoke anything, so a token
     * captured and then lost cannot be killed by its own holder until
     * they sign in once more. `POST /auth/sessions/revoke` covers the
     * case where the plaintext is still to hand.
     */
    public function revokeAllSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $revoked = (int) $user->tokens()->delete();

        Log::info('auth.sessions_revoked', [
            'user_id' => $user->id,
            'revoked' => $revoked,
            'ip' => $request->ip(),
        ]);

        return ApiResponse::success('All sessions have been revoked.', [
            'revoked' => $revoked,
        ]);
    }

    /**
     * Revoke one session by presenting its **plaintext** token in the
     * body.
     *
     * Not "give me a token": this is for the token you already hold —
     * an old device's string sitting in a password manager note, a token
     * copied out of a log before logging was fixed, the credential of a
     * session whose device is gone but whose string is not. The caller
     * must still be signed in as the token's own owner, so the endpoint
     * grants no reach across accounts; what it adds over the id-based
     * route is that you do not need to know *which* row it is, only that
     * it is yours and that you can produce it.
     *
     * The plaintext is hashed and compared — never logged, never echoed,
     * never stored — and a wrong token answers 404 rather than 401,
     * because a 401 would say "this string is a token, it is just not
     * this account's", which is a fact about somebody else's credentials.
     *
     * 422 rather than 404 for a missing body: nothing malformed reached
     * the database, and the caller simply forgot the only argument the
     * endpoint has.
     */
    public function revokeByToken(Request $request): JsonResponse
    {
        $plain = (string) $request->input('token', '');

        if ($plain === '') {
            abort(422, 'A token is required.');
        }

        $user = $request->user();

        $token = $user->tokens()
            ->where('token', hash('sha256', $plain))
            ->first();

        if ($token === null) {
            abort(404, 'Session not found.');
        }

        $id = $token->id;
        $token->delete();

        Log::info('auth.session_revoked', [
            'user_id' => $user->id,
            'token_id' => $id,
            'ip' => $request->ip(),
        ]);

        return ApiResponse::success('Session revoked.');
    }

    /**
     * Device label stored as the token name. Cosmetic but load-bearing: it is
     * what the sessions list shows and what "replace this device's token"
     * matches on.
     */
    private function deviceName(Request $request): string
    {
        $device = Str::squish((string) $request->input('device_name', ''));

        return $device === '' ? 'API client' : Str::limit($device, 190, '');
    }
}
