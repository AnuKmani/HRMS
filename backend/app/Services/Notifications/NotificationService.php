<?php

namespace App\Services\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\InAppNotification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for "the system told somebody something".
 *
 * Everything that raises a notification ends here — the listeners in
 * `NotificationServiceProvider`, the expiry scans, the reminder jobs — so
 * there is exactly one place where a preference is consulted, one place
 * where an inbox row is written, and one place that decides whether a push
 * is queued. A second writer would be a second set of rules, and the two
 * would drift the first time somebody added a category to one of them.
 *
 * **Nothing here talks to FCM.** `send()` writes inbox rows and hands each
 * one to a queued job; the network call happens later, off the request
 * thread, in `SendPushNotification`. That is what keeps a notification
 * from costing an API call a round trip to Google's servers.
 *
 * Order of operations inside `send()`:
 *
 *   1. collapse the recipient list;
 *   2. drop anybody whose account is not active;
 *   3. ask the preference — unless the message is mandatory;
 *   4. insert the inbox row;
 *   5. queue the push.
 *
 * Steps 4 and 5 are separated by design: the row is the durable record
 * and is committed with the caller's transaction, the push is a best
 * effort signal that may be retried, dropped or arrive after the user has
 * already opened the app and read it there.
 */
class NotificationService
{
    /**
     * Deliver a message to every one of its recipients.
     *
     * @return array<int, InAppNotification> the rows written
     */
    public function send(NotificationMessage $message): array
    {
        $recipients = $message->recipients();

        if ($recipients === []) {
            return [];
        }

        $category = $message->category();
        $mandatory = $message->isMandatory();

        $users = User::query()
            ->whereIn('id', $recipients)
            ->where('status', User::STATUS_ACTIVE)
            ->pluck('id')
            ->all();

        if ($users === []) {
            return [];
        }

        $muted = $mandatory
            ? []
            : $this->mutedUserIds($users, $category);

        $written = [];

        foreach ($users as $userId) {
            if (in_array($userId, $muted, true)) {
                continue;
            }

            $attributes = [
                'type' => $message->type,
                'title' => $message->title,
                'body' => $message->body,
                'data' => $message->data === [] ? null : $message->data,
                'read_at' => null,
            ];

            if ($message->dedupeKey !== null) {
                try {
                    $notification = InAppNotification::query()->firstOrCreate(
                        ['user_id' => $userId, 'dedupe_key' => $message->dedupeKey],
                        $attributes,
                    );
                } catch (UniqueConstraintViolationException) {
                    // A concurrent tick said the same thing a millisecond
                    // earlier and its insert won. The row exists, which is
                    // exactly what the key promised — not a failure, and
                    // not a second push.
                    continue;
                }

                if (! $notification->wasRecentlyCreated) {
                    continue;
                }
            } else {
                $notification = InAppNotification::query()->create(
                    $attributes + ['user_id' => $userId],
                );
            }

            $written[] = $notification;

            SendPushNotification::dispatch($notification->id);
        }

        return $written;
    }

    /* --------------------------------------------------------- preferences */

    /**
     * The categories a person has switched off.
     *
     * An absent row means enabled — a preference screen nobody has ever
     * opened must not mute anything — so this reads only rows that exist
     * and say `false`.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function mutedUserIds(array $userIds, string $category): array
    {
        if (NotificationCategory::isMandatory($category)) {
            return [];
        }

        return NotificationPreference::query()
            ->where('category', $category)
            ->whereIn('user_id', $userIds)
            ->where('enabled', false)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Every category with the person's current switch for it.
     *
     * @return array<int, array{category: string, enabled: bool, mandatory: bool}>
     */
    public function preferences(int $userId): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $userId)
            ->pluck('enabled', 'category')
            ->all();

        $rows = [];

        foreach (NotificationCategory::ALL as $category) {
            $mandatory = NotificationCategory::isMandatory($category);

            $rows[] = [
                'category' => $category,
                // Default on, except that a mandatory category is reported
                // as on whether or not somebody once wrote a `false` row —
                // the API refuses that write anyway, but a hand-edited row
                // must not be able to mute `security`.
                'enabled' => $mandatory
                    ? true
                    : (array_key_exists($category, $stored) ? (bool) $stored[$category] : true),
                'mandatory' => $mandatory,
            ];
        }

        return $rows;
    }

    /**
     * Set one category's switch. Refuses a mandatory category outright —
     * a `409` naming the category, not a silent no-op that leaves the
     * caller believing they changed something.
     *
     * @return array{category: string, enabled: bool, mandatory: bool}
     *
     * @throws \RuntimeException when the category must not be changed
     */
    public function setPreference(int $userId, string $category, bool $enabled): array
    {
        if (! NotificationCategory::exists($category)) {
            throw new \InvalidArgumentException("Unknown notification category [{$category}].");
        }

        if (NotificationCategory::isMandatory($category)) {
            throw new \RuntimeException(
                "The {$category} notifications are mandatory and cannot be switched off."
            );
        }

        DB::transaction(function () use ($userId, $category, $enabled) {
            // `updateOrCreate` rather than an insert: turning it back on
            // has to *remove* the opt-out, and leaving a `true` row behind
            // would make "absent means enabled" untrue for that account.
            if ($enabled) {
                NotificationPreference::query()
                    ->where('user_id', $userId)
                    ->where('category', $category)
                    ->delete();

                return;
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'category' => $category],
                ['enabled' => false],
            );
        });

        return [
            'category' => $category,
            'enabled' => $enabled,
            'mandatory' => false,
        ];
    }

    /* --------------------------------------------------------------- inbox */

    public function unreadCount(int $userId): int
    {
        return InAppNotification::query()
            ->forUser($userId)
            ->unread()
            ->count();
    }

    /**
     * Mark one row read. Returns false when it was already read or is not
     * the caller's — the route answers 404 rather than 200 either way, so
     * a wrong id and somebody else's id are indistinguishable from outside.
     */
    public function markRead(int $userId, int $notificationId): bool
    {
        $notification = InAppNotification::query()
            ->forUser($userId)
            ->find($notificationId);

        if ($notification === null) {
            return false;
        }

        $notification->markRead();

        return true;
    }

    public function markAllRead(int $userId): int
    {
        return InAppNotification::query()
            ->forUser($userId)
            ->unread()
            ->update(['read_at' => now()]);
    }
}
