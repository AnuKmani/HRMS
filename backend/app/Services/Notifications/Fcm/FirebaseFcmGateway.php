<?php

namespace App\Services\Notifications\Fcm;

use App\Models\DeviceToken;
use App\Models\InAppNotification;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * FCM **HTTP v1** delivery, credentials read from the environment.
 *
 * The service-account JSON never touches the repository. It is read from
 * one of two places, both outside git:
 *
 *   `FIREBASE_CREDENTIALS`          a filesystem path to the JSON, or
 *   `FIREBASE_CREDENTIALS_BASE64`   the JSON itself, base64-encoded
 *
 * — the second exists for hosts where writing a secret file is more
 * awkward than setting one variable (container platforms, some PaaS).
 * `.env.example` carries neither value, only the two empty keys.
 *
 * Why not `kreait/firebase-php`: this is one endpoint and one token
 * exchange. Pulling an SDK that itself pulls Google's auth stack to post
 * a single JSON body would add a dependency surface larger than the code
 * it wraps, and `firebase/php-jwt` (RS256 signing, nothing else) is the
 * only part of that stack this actually needs.
 *
 * The access token is minted once and cached for 45 minutes — Google's
 * are valid for an hour — so a thousand-message run costs one token
 * exchange, not a thousand.
 */
class FirebaseFcmGateway implements FcmGateway
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const MESSAGING_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array<string, mixed>|null */
    private ?array $credentials = null;

    private bool $resolved = false;

    public function available(): bool
    {
        return $this->credentials() !== null;
    }

    public function send(DeviceToken $device, InAppNotification $notification): void
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            // The container picks this gateway only when credentials are
            // present, so reaching here means they vanished between the
            // check and the call. Skip honestly rather than fabricate.
            return;
        }

        $response = Http::withToken($this->accessToken($credentials))
            ->timeout((int) config('hrms.notifications.fcm_timeout_seconds', 10))
            ->post(sprintf(self::MESSAGING_ENDPOINT, $this->projectId($credentials)), [
                'message' => [
                    'token' => $device->fcm_token,
                    'notification' => [
                        'title' => $notification->title,
                        'body' => $notification->body,
                    ],
                    'data' => $this->data($notification),
                    // A push is a nudge to come and read the inbox, so the
                    // OS should not light the screen on its own for one.
                    'android' => [
                        'priority' => 'normal',
                        'notification' => ['channel_id' => 'hrms'],
                    ],
                    'apns' => [
                        'headers' => ['apns-priority' => '10'],
                        'payload' => ['aps' => ['sound' => 'default']],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        $this->throwFor($response->status(), $response->json());
    }

    /* -------------------------------------------------------------- token */

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        // 45 minutes rather than 60: Google's tokens live an hour, and a
        // cached one that expires *during* a multicast would fail the
        // messages at the tail. A 15-minute margin costs one extra
        // exchange per hour of sending.
        return Cache::remember(
            'firebase.access_token.'.$this->projectId($credentials),
            now()->addMinutes(45),
            function () use ($credentials) {
                $now = time();

                $jwt = JWT::encode([
                    'iss' => $credentials['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => self::TOKEN_ENDPOINT,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ], $credentials['private_key'], 'RS256');

                $response = Http::asForm()->timeout(15)->post(self::TOKEN_ENDPOINT, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                if (! $response->successful() || ! is_string($response->json('access_token'))) {
                    throw new DeliveryException(
                        'Firebase refused to mint an access token: HTTP '.$response->status()
                    );
                }

                return $response->json('access_token');
            },
        );
    }

    /* ---------------------------------------------------------- credentials */

    /**
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        if ($this->resolved) {
            return $this->credentials;
        }

        $this->resolved = true;
        $this->credentials = $this->parse();

        return $this->credentials;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(): ?array
    {
        $json = null;

        $path = config('services.firebase.credentials');
        if (is_string($path) && $path !== '') {
            $json = is_file($path) ? file_get_contents($path) : false;
        }

        if (! is_string($json) || $json === '') {
            $encoded = config('services.firebase.credentials_base64');
            if (is_string($encoded) && $encoded !== '') {
                $json = base64_decode($encoded, true) ?: false;
            }
        }

        if (! is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)
            || ! isset($decoded['project_id'], $decoded['client_email'], $decoded['private_key'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function projectId(array $credentials): string
    {
        return (string) (config('services.firebase.project_id') ?: $credentials['project_id']);
    }

    /* --------------------------------------------------------------- data */

    /**
     * FCM data values must all be strings. Everything in `data` is an id,
     * a route or a catalogue key, so a cast is all that is needed — and
     * the payload never carries a fact worth keeping private, because the
     * phone re-fetches whatever it navigates to.
     *
     * @return array<string, string>
     */
    private function data(InAppNotification $notification): array
    {
        $data = [
            'type' => $notification->type,
            'notification_id' => (string) $notification->id,
        ];

        foreach ((array) ($notification->data ?? []) as $key => $value) {
            $data[(string) $key] = $value === null ? '' : (string) $value;
        }

        return $data;
    }

    /* ----------------------------------------------------------- failures */

    /**
     * Turn an FCM error into one of our two exceptions, so the job never
     * has to know Google's error vocabulary.
     *
     * @param  array<string, mixed>|null  $body
     *
     * @throws StaleTokenException|DeliveryException
     */
    private function throwFor(int $status, ?array $body): void
    {
        $errorCode = is_array($body) ? (string) data_get($body, 'error.status', '') : '';
        $details = is_array($body) ? (string) data_get($body, 'error.details.0.errorInfo.typename', '') : '';
        $message = is_array($body) ? (string) data_get($body, 'error.message', '') : '';

        $stale = $errorCode === 'NOT_FOUND'
            || $errorCode === 'UNREGISTERED'
            || $details === 'UNREGISTERED'
            || str_contains($message, 'UNREGISTERED')
            || str_contains($message, 'NotRegistered')
            || str_contains($message, 'registration-token-not-registered');

        if ($stale) {
            throw new StaleTokenException('Firebase reports this registration token no longer exists.');
        }

        // 429 and 5xx are worth retrying; 4xx that is not "gone" usually
        // is not, but a malformed-message bug is cheaper to surface as a
        // failed job than to swallow.
        throw new DeliveryException("Firebase rejected the message (HTTP {$status}): {$message}");
    }
}
