<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Audit\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton: one cached read-through of `settings` per request.
        $this->app->singleton(SettingsService::class, fn () => new SettingsService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Any settings write invalidates the cached snapshot so the next read
        // sees the new value (including across queue workers / other tabs).
        Setting::saved(fn () => $this->app->make(SettingsService::class)->refresh());
        Setting::deleted(fn () => $this->app->make(SettingsService::class)->refresh());

        $this->registerRateLimiters();
        $this->registerAuditLogging();
        $this->registerPasswordResetMail();
    }

    /**
     * Give the password broker this application's URL and this
     * application's words.
     *
     * Two callbacks, because out of the box the broker's mail builds a
     * URL from a **named route called `password.reset`** — and this
     * application has no such route, because it has no web UI to serve.
     * `url(route(...))` against a name that does not exist throws, which
     * means the stock notification cannot be sent here at all. This is
     * not a cosmetic override; without it forgot-password 500s the first
     * time it is asked to do its job.
     *
     * The URL points at `GET /reset-password` in routes/web.php: a single
     * self-contained page that posts the token back to the API. It is the
     * only server-rendered surface in the application, and it exists
     * because an email action button that 404s is worse than no email.
     * The query string carries `token` and `email` because that is what
     * `POST /auth/reset-password` validates, and the page simply passes
     * them through — no session, no state, no second store.
     *
     * The mail body is built here rather than in a Blade view so the copy
     * lives beside the code that chooses the URL. Nothing in it is a claim
     * about delivery: it says where to click, not whether the inbox on the
     * other end is empty.
     */
    private function registerPasswordResetMail(): void
    {
        // One builder, used for the action button and for the plain-text
        // fallback beneath it. Email clients that strip buttons — and
        // there are still plenty of corporate ones — leave the link as
        // text a person can select, which is the difference between a
        // working reset and a support ticket.
        $url = fn ($notifiable, string $token): string => rtrim((string) config('app.url'), '/').'/reset-password?'
            .'token='.urlencode($token)
            .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());

        ResetPassword::createUrlUsing($url);

        ResetPassword::toMailUsing(function ($notifiable, string $token) use ($url): MailMessage {
            $link = $url($notifiable, $token);

            return (new MailMessage)
                ->subject('Reset your '.config('app.name').' password')
                ->greeting('Hello,')
                ->line('Somebody asked to reset the password on the '.config('app.name').' account for this address.')
                ->line('If it was you, use the button below. If it was not, you can ignore this — nothing has been changed, and the link expires on its own.')
                ->action('Choose a new password', $link)
                ->line('The link expires in 60 minutes and can only be used once.')
                ->line('If the button does not work, paste this into your browser:')
                ->line($link);
        });
    }

    /**
     * Hang audit logging off Eloquent for a fixed list of models.
     *
     * Model events rather than controller calls, for the reason spelled out
     * at the top of {@see AuditLogger}: "which operations are audited" then
     * has one answer — `AuditLogger::AUDITED` — instead of being scattered
     * across however many endpoints happen to remember. It is the same shape
     * as the two `Setting::saved` / `Setting::deleted` hooks directly above,
     * which is deliberate: this is what `boot()` is for.
     *
     * The `updated` listener passes `getChanges()` against `getOriginal()`
     * rather than the whole row: at the moment `updated` fires the model has
     * not yet been re-synced, so those two are still the honest before and
     * after. `created` and `deleted` have no "before", so they carry the row
     * as it stands.
     *
     * The logger filters, redacts and decides whether the pair is worth a
     * row at all — nothing here touches the database directly.
     */
    private function registerAuditLogging(): void
    {
        foreach (AuditLogger::AUDITED as $class) {
            $class::created(function (Model $model) {
                $this->app->make(AuditLogger::class)->record(
                    $model,
                    'created',
                    [],
                    $model->getAttributes(),
                );
            });

            $class::updated(function (Model $model) {
                $changes = $model->getChanges();

                if ($changes === []) {
                    return;
                }

                $before = [];

                foreach (array_keys($changes) as $key) {
                    $before[$key] = $model->getOriginal($key);
                }

                $this->app->make(AuditLogger::class)->record($model, 'updated', $before, $changes);
            });

            $class::deleted(function (Model $model) {
                $this->app->make(AuditLogger::class)->record(
                    $model,
                    'deleted',
                    [],
                    $model->getAttributes(),
                );
            });
        }
    }

    /**
     * Named rate limiters referenced from routes/api.php as `throttle:{name}`.
     *
     * Every number is read from config/rate_limiting.php (which reads .env),
     * so tightening a limit is a config change and a deploy — never a hunt
     * through route files for a literal. Nothing below hard-codes a figure
     * and neither does any route file.
     *
     * Two keying strategies, and the reason each is used where:
     *
     *  - **By IP** for anything unauthenticated (login, password reset).
     *    Keying login by email would let anyone with a connection flood one
     *    address and lock its real owner out; keying by IP throttles the
     *    machine actually doing the guessing and is immune to that abuse.
     *  - **By user** for anything authenticated (attendance, uploads,
     *    writes, exports, device tokens). A crew on one site shares an
     *    address, and one device retrying on a weak signal must not stop
     *    everyone else clocking in. Unauthenticated traffic falls back to
     *    the IP — it never reaches these routes anyway, since auth:sanctum
     *    runs first.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.login.decay_minutes'),
                (int) config('rate_limiting.login.max_attempts'),
            )->by('login:'.$request->ip());
        });

        RateLimiter::for('password_reset', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.password_reset.decay_minutes'),
                (int) config('rate_limiting.password_reset.max_attempts'),
            )->by('password_reset:'.$request->ip());
        });

        // Attendance writes. Keyed by the authenticated user rather than by
        // IP, because a crew on one site shares an address and one device
        // retrying on a weak signal must not stop everyone else clocking in.
        // Unauthenticated traffic falls back to the IP — it never reaches
        // these routes anyway, since auth:sanctum runs first.
        RateLimiter::for('attendance', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.attendance.decay_minutes'),
                (int) config('rate_limiting.attendance.max_attempts'),
            )->by('attendance:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        /*
        |--------------------------------------------------------------------
        | Phase 12 additions
        |--------------------------------------------------------------------
        |
        | Same two rules as above — numbers in config, keyed by user — so
        | these four read exactly like the three that came before them.
        |
        */

        // Every multipart upload in the application: documents, training
        // certificates, expense receipts, site photos, sick certificates,
        // selfies. One limiter rather than seven, because the thing being
        // protected is the same in all of them — disk and bandwidth — and
        // seven limits would be seven numbers to get out of step.
        RateLimiter::for('upload', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.upload.decay_minutes'),
                (int) config('rate_limiting.upload.max_attempts'),
            )->by('upload:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        // High-risk mutations that are *not* the four already covered:
        // expense submission, training assignment, asset hand-over and
        // return, employee create/edit. Generous enough that a normal day
        // never notices it, tight enough that a runaway client or a scripted
        // abuse cannot rewrite hundreds of rows a minute.
        RateLimiter::for('write', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.write.decay_minutes'),
                (int) config('rate_limiting.write.max_attempts'),
            )->by('write:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        // Registering a device token is cheap to ask for and expensive to
        // be asked for in a loop: each call is an upsert against a unique
        // index and, afterwards, a queue job per notification. Ten a minute
        // is far more than one real handset needs — a token *refresh* is
        // one call — and is what stops a script rotating identities.
        RateLimiter::for('device_token', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.device_token.decay_minutes'),
                (int) config('rate_limiting.device_token.max_attempts'),
            )->by('device_token:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        // Exports, synchronous or queued. A queued export is a database walk
        // and a file on disk; asking for one in a loop is the cheapest way
        // to fill private storage with reports nobody will download.
        RateLimiter::for('export', function (Request $request) {
            return Limit::perMinutes(
                (int) config('rate_limiting.export.decay_minutes'),
                (int) config('rate_limiting.export.max_attempts'),
            )->by('export:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
