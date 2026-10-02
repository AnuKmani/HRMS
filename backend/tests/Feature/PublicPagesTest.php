<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two things this application serves over plain HTTP with no token in
 * front of it: GET /health and GET /reset-password.
 *
 * Both are unauthenticated by necessity — a probe that has to sign in and
 * an email link that has to work from a device that has never seen the app
 * — and both are therefore written to give away as little as possible.
 * `/health` answers "is it up" with four booleans and nothing about what
 * it is up *on*; the reset page carries a token back to a form and nothing
 * else about the account it belongs to.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------ health */

    public function test_the_probe_needs_no_credentials(): void
    {
        $response = $this->get('/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok');

        $checks = $response->json('data.checks');

        $this->assertSame(
            ['database', 'cache', 'storage', 'queue'],
            array_keys($checks),
        );

        foreach ($checks as $name => $state) {
            $this->assertSame('ok', $state, "{$name} reported {$state}.");
        }

        $this->assertIsString($response->json('data.time'));
    }

    public function test_a_probe_result_is_never_cacheable(): void
    {
        // A cached answer is a measurement of whenever it was cached, and
        // a monitoring check that reads one would be paging somebody about
        // Tuesday's outage.
        $this->get('/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $this->get('/health')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }

    public function test_the_probe_reports_a_broken_dependency_as_503(): void
    {
        // Point the queue at a connection that does not exist; the probe
        // has to say "down" rather than swallow the exception and report
        // healthy, and an operator has to be able to tell from the status
        // code alone.
        config(['queue.default' => 'not-a-real-connection']);

        $response = $this->get('/health');

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.queue', 'down');

        // The other three are still fine, and the response still says
        // nothing about *why* — the reason is a host name or a password
        // and it belongs in the log, where an operator can see it.
        $this->assertSame('ok', $response->json('data.checks.database'));
    }

    public function test_the_probe_exposes_no_configuration_at_all(): void
    {
        $body = $this->get('/health')->assertOk()->getContent();

        // The reason a health endpoint is worth attacking: it is the one
        // unauthenticated window onto the installation.
        foreach ([
            'APP_KEY',
            'DB_PASSWORD',
            'DB_USERNAME',
            'DB_DATABASE',
            'hrms_laravel',
            'hrms_testing',
            '127.0.0.1',
            '.env',
            'Laravel',
            'MariaDB',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // Three keys and a timestamp: the whole payload.
        $this->assertSame(
            ['status', 'checks', 'time'],
            array_keys($this->get('/health')->json('data')),
        );
    }

    /* ----------------------------------------------------- reset landing */

    public function test_the_reset_page_renders_for_a_link_carrying_a_token(): void
    {
        $response = $this->get('/reset-password?token=abc123&email=someone%40example.com');

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('abc123', $body);
        $this->assertStringContainsString('someone@example.com', $body);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_the_reset_page_renders_even_with_an_empty_query(): void
    {
        // A user who opens the link twice, or strips the query in a
        // privacy-conscious mail client, should get a page that explains
        // itself rather than a stack trace.
        $this->get('/reset-password')
            ->assertOk()
            ->assertSee('Reset your password', false);
    }

    public function test_a_token_carried_in_the_query_is_escaped_into_the_page(): void
    {
        $body = $this->get('/reset-password?token=%3Cscript%3Ealert(1)%3C%2Fscript%3E')
            ->assertOk()
            ->getContent();

        // The token is embedded in a JSON payload inside a script tag,
        // encoded with JSON_HEX_* so < becomes \u003C etc.
        // It should NOT appear as raw HTML.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);

        // The JSON payload should contain the escaped form
        $this->assertStringContainsString('\\u003Cscript\\u003E', $body);
    }

    public function test_the_reset_page_is_not_listed_as_an_api_route(): void
    {
        // It is a page, behind no `auth:sanctum`, and it must not be
        // mistaken for an endpoint a client should poll.
        $this->get('/reset-password')->assertOk();
        $this->postJson('/api/v1/auth/reset-password', [])
            ->assertStatus(422);
    }
}
