<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SignsInAccounts;
use Tests\TestCase;

/**
 * GET /api/v1/client-settings
 *
 * The endpoint the mobile form reads before it can decide what to put in
 * the currency box. Four questions, in the order they can go wrong:
 *
 *  - is it there at all (a 404 here shows up as a blank field on a phone);
 *  - is it safe to expose (an allow-list, asserted as an allow-list);
 *  - does it follow the setting rather than a constant;
 *  - and does it survive the two settings disagreeing, because an operator
 *    editing one row at 4pm is exactly how they come to disagree.
 *
 * No permission is asserted for the happy path: none is required, and a
 * test that did not say so would leave the next reader to wonder whether
 * the route silently picked one up.
 */
class ClientSettingsTest extends TestCase
{
    use RefreshDatabase, SignsInAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_a_signed_in_session_reads_the_configured_default_currency(): void
    {
        $this->signInAs('Employee');

        $this->getJson('/api/v1/client-settings')
            ->assertOk()
            ->assertJsonPath('data.default_currency', 'AED')
            ->assertJsonPath('data.supported_currencies', ['AED']);
    }

    public function test_nothing_signed_in_is_asked_for_a_right_to_read_its_own_configuration(): void
    {
        // `Employee` holds none of the `settings.*` grants, and does not
        // need one: the payload is the two values every form needs, not the
        // settings screen.
        [, $employee] = $this->signInAs('Employee');

        $this->assertFalse($employee->user->can('settings.view'));
        $this->getJson('/api/v1/client-settings')->assertOk();
    }

    public function test_anonymous_is_refused(): void
    {
        $this->getJson('/api/v1/client-settings')->assertUnauthorized();
    }

    public function test_the_payload_is_an_allow_list_and_nothing_else(): void
    {
        $this->signInAs('Employee');

        $data = $this->getJson('/api/v1/client-settings')
            ->assertOk()
            ->json('data');

        $this->assertSame(['default_currency', 'supported_currencies'], array_keys($data));

        // The denylist nobody can be trusted to keep complete: payroll
        // floors, geofence radii, notification timing and the rest are not
        // filtered out of a dump, they were never candidates for one.
        $encoded = json_encode($data);

        foreach (['minimum_net_salary', 'lop_divisor', 'grace_period', 'company_name', 'geofence'] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }
    }

    public function test_the_default_follows_the_row_rather_than_a_constant(): void
    {
        $this->signInAs('Employee');

        $this->configure('USD', ['USD', 'AED']);

        $this->getJson('/api/v1/client-settings')
            ->assertOk()
            ->assertJsonPath('data.default_currency', 'USD')
            ->assertJsonPath('data.supported_currencies', ['USD', 'AED']);
    }

    public function test_a_default_that_is_not_supported_falls_back_to_one_that_is(): void
    {
        // The two settings are edited separately, so they *can* disagree.
        // Handing the form JPY when the API would refuse JPY is a blank
        // claim and a support ticket; the first supported code is offered
        // instead, and the mismatch stays visible in the admin screen.
        $this->signInAs('Employee');

        $this->configure('JPY', ['AED', 'USD']);

        $this->getJson('/api/v1/client-settings')
            ->assertOk()
            ->assertJsonPath('data.default_currency', 'AED')
            ->assertJsonPath('data.supported_currencies', ['AED', 'USD']);
    }

    public function test_an_unconfigured_list_still_answers_with_the_company_currency(): void
    {
        // An empty list switches the membership rule off on the claim
        // endpoint; it must not produce an empty menu here, or the form
        // would have nothing to file in while the server was perfectly
        // happy to accept one.
        $this->signInAs('Employee');

        $this->configure('AED', []);

        $this->getJson('/api/v1/client-settings')
            ->assertOk()
            ->assertJsonPath('data.default_currency', 'AED')
            ->assertJsonPath('data.supported_currencies', ['AED']);
    }

    public function test_the_codes_are_offered_the_way_the_validator_spells_them(): void
    {
        $this->signInAs('Employee');

        $this->configure('aed', ['aed', 'USD', 'aed']);

        $this->getJson('/api/v1/client-settings')
            ->assertOk()
            ->assertJsonPath('data.default_currency', 'AED')
            ->assertJsonPath('data.supported_currencies', ['AED', 'USD']);
    }

    /**
     * Rewrite both currency settings at once.
     *
     * Through the model, never the query builder: `Setting::saved` is what
     * busts the settings cache, and a builder `update()` fires no events, so
     * the endpoint would go on answering from the rows the seeder wrote.
     *
     * @param  array<int, string>  $supported
     */
    private function configure(string $currency, array $supported): void
    {
        Setting::query()->where('key', 'system.currency')
            ->firstOrFail()
            ->update(['value' => $currency]);

        Setting::query()->where('key', 'system.supported_currencies')
            ->firstOrFail()
            ->update(['value' => json_encode($supported)]);
    }
}
