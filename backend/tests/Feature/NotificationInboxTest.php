<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationMessage;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The in-app inbox and the preferences behind it: GET /notifications,
 * /unread-count, /{id}/read, /read-all, and both preference routes.
 *
 * Two properties carry the weight here and everything else follows from
 * them:
 *
 *   - **Addressing is the whole authorisation.** No permission gate exists
 *     on any of these routes, so the only thing standing between one
 *     account's inbox and another's is `user_id`. Another person's id must
 *     therefore answer 404 — never 403, because "that id exists and is not
 *     yours" is the one answer a stranger should never get.
 *
 *   - **The push is queued, not sent.** `send()` writes the row and hands
 *     the id to `SendPushNotification`; if a test can observe Firebase
 *     being reached on the request thread, something has gone wrong with
 *     the architecture rather than with the assertion.
 */
class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------- helpers */

    private function becomeUser(): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Say something. Recipients are user ids; a duplicate id in the list
     * collapses, because "notify them twice" is one message.
     *
     * @param  array<int, int>  $recipients
     * @param  array<string, mixed>  $overrides
     * @return array<int, InAppNotification>
     */
    private function say(array $recipients, array $overrides = []): array
    {
        Queue::fake();

        return app(NotificationService::class)->send(new NotificationMessage(
            type: $overrides['type'] ?? 'leave.approved',
            title: $overrides['title'] ?? 'Leave approved',
            body: $overrides['body'] ?? 'Your leave was approved.',
            recipients: $recipients,
            data: $overrides['data'] ?? ['route' => '/leave/1'],
            dedupeKey: $overrides['dedupeKey'] ?? null,
        ));
    }

    /* ------------------------------------------------------ addressing */

    public function test_an_empty_inbox_is_an_empty_page_not_a_404(): void
    {
        $this->becomeUser();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items', []);
    }

    public function test_the_inbox_only_ever_shows_rows_addressed_to_the_caller(): void
    {
        $mine = $this->becomeUser();
        $other = User::factory()->create();

        $this->say([(int) $mine->id]);
        $this->say([(int) $other->id], ['title' => 'Somebody else’s news']);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('Leave approved', $response->json('data.items.0.title'));
    }

    public function test_marking_somebody_elses_notification_read_is_a_404_not_a_403(): void
    {
        $mine = $this->becomeUser();
        $other = User::factory()->create();

        [$theirs] = $this->say([(int) $other->id]);

        // 403 would confirm the id is real and merely guarded; 404 makes a
        // guessed id and a nonsense one indistinguishable from outside.
        $this->postJson("/api/v1/notifications/{$theirs->id}/read")->assertNotFound();

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_the_unread_count_is_the_callers_alone(): void
    {
        $mine = $this->becomeUser();
        $other = User::factory()->create();

        $this->say([(int) $mine->id]);
        $this->say([(int) $mine->id]);
        $this->say([(int) $other->id]);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 2);
    }

    /* ------------------------------------------------------------- read */

    public function test_marking_one_read_updates_the_row_and_the_badge(): void
    {
        $user = $this->becomeUser();

        [$one] = $this->say([(int) $user->id]);
        $this->say([(int) $user->id], ['title' => 'Second']);

        $this->postJson("/api/v1/notifications/{$one->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.unread', 1);

        $this->assertNotNull($one->fresh()->read_at);
    }

    public function test_read_all_touches_every_unread_row_and_nothing_of_someone_elses(): void
    {
        $mine = $this->becomeUser();
        $other = User::factory()->create();

        $this->say([(int) $mine->id]);
        $this->say([(int) $mine->id]);
        [$theirs] = $this->say([(int) $other->id]);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.unread', 0);

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_marking_an_unknown_id_read_is_a_404(): void
    {
        $this->becomeUser();

        $this->postJson('/api/v1/notifications/999999/read')->assertNotFound();
    }

    /* ---------------------------------------------------------- filters */

    public function test_unread_and_type_narrow_the_list(): void
    {
        $user = $this->becomeUser();

        [$first] = $this->say([(int) $user->id], ['type' => 'leave.approved']);
        $this->say([(int) $user->id], ['type' => 'expense.approved']);
        $this->say([(int) $user->id], ['type' => 'leave.rejected']);

        $this->postJson("/api/v1/notifications/{$first->id}/read")->assertOk();

        $byType = $this->getJson('/api/v1/notifications?type=leave.approved')->assertOk();
        $this->assertCount(1, $byType->json('data.items'));

        $unread = $this->getJson('/api/v1/notifications?unread=1')->assertOk();
        $this->assertCount(2, $unread->json('data.items'));
    }

    /* --------------------------------------------------------- delivery */

    public function test_the_push_is_handed_to_a_queued_job_rather_than_sent_inline(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->say([(int) $user->id]);

        // One job per written row, carrying only the row's id — the job
        // re-reads the token itself, so a queued message never travels
        // with an FCM token attached to it.
        Queue::assertPushed(SendPushNotification::class, 1);
    }

    public function test_a_dedupe_key_says_a_thing_once(): void
    {
        $user = User::factory()->create();

        $first = $this->say([(int) $user->id], ['dedupeKey' => 'sick_cert:7']);
        $second = $this->say([(int) $user->id], ['dedupeKey' => 'sick_cert:7']);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertSame(1, InAppNotification::query()->where('user_id', $user->id)->count());
    }

    /* ------------------------------------------------------- preferences */

    public function test_switching_a_category_off_stops_its_messages_but_never_a_mandatory_one(): void
    {
        $user = $this->becomeUser();

        $this->putJson('/api/v1/notification-preferences', [
            'category' => 'leave',
            'enabled' => false,
        ])->assertOk();

        $this->assertCount(0, $this->say([(int) $user->id], ['type' => 'leave.approved']));

        $this->putJson('/api/v1/notification-preferences', [
            'category' => 'security',
            'enabled' => false,
        ])->assertStatus(409);

        $this->assertCount(1, $this->say([(int) $user->id], ['type' => 'security.password_changed']));
    }

    public function test_switching_a_category_back_on_deletes_the_opt_out_row(): void
    {
        $user = $this->becomeUser();

        $this->putJson('/api/v1/notification-preferences', [
            'category' => 'leave',
            'enabled' => false,
        ])->assertOk();

        $this->assertCount(0, $this->say([(int) $user->id]));

        $this->putJson('/api/v1/notification-preferences', [
            'category' => 'leave',
            'enabled' => true,
        ])->assertOk();

        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'leave',
        ]);

        $this->assertCount(1, $this->say([(int) $user->id]));
    }

    public function test_the_preferences_screen_reports_the_whole_catalogue_every_time(): void
    {
        $this->becomeUser();

        $items = $this->getJson('/api/v1/notification-preferences')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(count(NotificationCategory::ALL), $items);

        $mandatory = array_values(array_filter($items, fn (array $row): bool => $row['mandatory']));

        $this->assertSame(['security', 'system'], array_column($mandatory, 'category'));

        // "Absent means enabled" has to be *visible* as an enabled switch,
        // not as a missing line — otherwise the screen reads as "off".
        foreach ($items as $row) {
            $this->assertTrue($row['enabled']);
        }
    }

    public function test_an_unknown_category_is_a_422_and_a_mandatory_one_is_a_409(): void
    {
        $this->becomeUser();

        $this->putJson('/api/v1/notification-preferences', [
            'category' => 'nonsense',
            'enabled' => false,
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->putJson('/api/v1/notification-preferences', [
            'category' => 'security',
            'enabled' => false,
        ])->assertStatus(409)->assertJsonPath('success', false);
    }
}
