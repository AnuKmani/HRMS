<?php

namespace App\Services\Notifications;

/**
 * What somebody is about to be told, and by whom.
 *
 * Built by the listeners in `NotificationServiceProvider` and consumed by
 * `NotificationService`. It is a plain value object on purpose: nothing in
 * here knows how an inbox row is written, where a device token comes from,
 * or what FCM is, so the catalogue of *messages* can be read in one file
 * without following any of the machinery.
 *
 * `data` is a bag of ids and a route. It is never a payload of facts —
 * see the `notifications` migration for why. `recipients` are user ids,
 * not models, so a message can be built once and delivered to a whole
 * approval chain without loading it.
 *
 * `dedupeKey` is for the messages a *clock* raises rather than an event:
 * pass `sick_cert:7` and the second attempt to say the same thing to the
 * same person is a no-op, enforced by the table's unique index rather than
 * by a marker column on whichever table the clock reads. Leave it null —
 * the normal case — and nothing is checked.
 */
final class NotificationMessage
{
    /**
     * @param  array<int, int>  $recipients
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array $recipients,
        public readonly array $data = [],
        public readonly bool $mandatory = false,
        public readonly ?string $dedupeKey = null,
    ) {}

    public function category(): string
    {
        return NotificationCategory::forType($this->type);
    }

    /**
     * The messages a person cannot switch off, whichever way they were
     * built: a caller may set `mandatory`, and a `security.*` / `system.*`
     * type is mandatory regardless of what the caller asked for.
     */
    public function isMandatory(): bool
    {
        if ($this->mandatory) {
            return true;
        }

        return NotificationCategory::isMandatory($this->category());
    }

    /**
     * Recipients, cleaned: nulls dropped, duplicates collapsed, order
     * kept. The first entry wins on a duplicate so "notify HR twice"
     * becomes one inbox row rather than two identical ones.
     *
     * @return array<int, int>
     */
    public function recipients(): array
    {
        $clean = [];

        foreach ($this->recipients as $id) {
            if ($id === null) {
                continue;
            }

            $id = (int) $id;

            if (! isset($clean[$id])) {
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
    }
}
