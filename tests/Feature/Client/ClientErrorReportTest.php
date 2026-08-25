<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Tests\TestCase;
use App\Models\User;
use App\Models\ClientError;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `POST /api/client-errors` — the only channel by which a failure on the phone
 * becomes a fact on the server. It is called from inside `window.onerror` on a
 * device already misbehaving, so the cases that matter are not the happy path:
 * a 40 KB minified stack, a crash loop firing sixty times a second, a session
 * that expired while the app was in somebody's pocket.
 */
final class ClientErrorReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/client-errors';

    protected function setUp(): void
    {
        parent::setUp();

        // Otherwise the throttle test's exhausted bucket leaks into the next.
        RateLimiter::clear('client-errors');
    }

    public function test_a_guest_is_refused_with_json_rather_than_a_login_page(): void
    {
        /*
         * 401 WITH A BODY, not a 302: `fetch` follows redirects, so an expired
         * session serving the login page as a 200 would read as a successful
         * report, silencing the reporter exactly when something is wrong. That
         * is why this route is under /api.
         */
        $this->postJson(self::URL, ['message' => 'boom'])->assertUnauthorized();

        $this->assertSame(0, ClientError::query()->count());
    }

    public function test_a_report_is_stored_with_the_build_and_the_user_agent(): void
    {
        $this->actingAs(User::factory()->create());

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X)'])
            ->postJson(self::URL, [
                'kind'    => 'vue',
                'message' => "Cannot read properties of undefined (reading 'kcal')",
                'source'  => 'render function',
                'line'    => 41,
                'col'     => 9,
                'stack'   => "at Proxy.render\nat renderComponentRoot",
                'url'     => 'https://health.example.com/?date=2026-08-09',
                'build'   => 'a1b2c3d4e5f6',
            ])
            ->assertNoContent();

        $error = ClientError::query()->sole();

        $this->assertSame('vue', $error->kind);
        $this->assertSame("Cannot read properties of undefined (reading 'kcal')", $error->message);
        $this->assertSame('render function', $error->source);
        $this->assertSame(41, $error->line);
        $this->assertSame(9, $error->col);
        $this->assertStringContainsString('renderComponentRoot', (string) $error->stack);
        $this->assertSame('https://health.example.com/?date=2026-08-09', $error->url);

        // The build separates a live bug from a tab open across three deploys;
        // without it nobody can act on the report.
        $this->assertSame('a1b2c3d4e5f6', $error->build);

        // From the request header, never the body: the header made the request.
        $this->assertStringContainsString('iPhone', (string) $error->user_agent);
    }

    public function test_the_user_agent_is_taken_from_the_header_and_not_from_the_body(): void
    {
        $this->actingAs(User::factory()->create());

        $this->withHeaders(['User-Agent' => 'RealAgent/1.0'])
            ->postJson(self::URL, [
                'message'    => 'boom',
                'user_agent' => 'ClaimedAgent/9.9',
            ])
            ->assertNoContent();

        $this->assertSame('RealAgent/1.0', ClientError::query()->sole()->user_agent);
    }

    public function test_an_enormous_stack_is_truncated_rather_than_refused(): void
    {
        $this->actingAs(User::factory()->create());

        /*
         * A minified stack out of a 338 KB bundle is routinely tens of
         * kilobytes and a `message` can carry a data: URL; a 422 would discard
         * the only copy of the evidence to enforce a limit nobody reads past.
         */
        $this->postJson(self::URL, [
            'message' => str_repeat('m', 5_000),
            'stack'   => str_repeat('s', 60_000),
            'url'     => 'https://health.example.com/'.str_repeat('q', 4_000),
            'source'  => str_repeat('f', 4_000),
            'build'   => str_repeat('b', 200),
        ])->assertNoContent();

        $error = ClientError::query()->sole();

        $this->assertSame(1_000, mb_strlen($error->message));
        $this->assertSame(4_000, mb_strlen((string) $error->stack));
        $this->assertSame(2_000, mb_strlen((string) $error->url));
        $this->assertSame(1_000, mb_strlen((string) $error->source));
        $this->assertSame(64, mb_strlen((string) $error->build));
    }

    public function test_a_report_with_nothing_but_a_message_is_accepted(): void
    {
        $this->actingAs(User::factory()->create());

        /*
         * What an `unhandledrejection` looks like: no source, line or column,
         * and often no stack because the rejected value was not an Error — the
         * majority of what this endpoint receives, so not the awkward case.
         */
        $this->postJson(self::URL, [
            'message' => 'Failed to fetch dynamically imported module',
            'kind'    => 'rejection',
            'source'  => null,
            'line'    => null,
            'col'     => null,
            'stack'   => null,
        ])->assertNoContent();

        $error = ClientError::query()->sole();

        $this->assertSame('rejection', $error->kind);
        $this->assertNull($error->line);
        $this->assertNull($error->stack);
    }

    public function test_a_report_with_no_message_is_not_a_report(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(self::URL, ['kind' => 'error'])
            ->assertStatus(422);

        $this->assertSame(0, ClientError::query()->count());
    }

    public function test_an_unknown_kind_is_filed_as_an_error_rather_than_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(self::URL, ['message' => 'boom', 'kind' => 'something-new'])
            ->assertNoContent();

        $this->assertSame('error', ClientError::query()->sole()->kind);
    }

    /**
     * The report the three-identical-rows morning could not produce: those rows
     * said `undefined is not an object (evaluating 'e.toString')` and nothing
     * else — no source, no line, a minified stack, a `url` that was the page
     * being LEFT. What identified it on sight (a 200, `application/json`, zero
     * bytes) was known in the browser and had nowhere to go; these two columns
     * are that somewhere.
     */
    public function test_an_inertia_failure_records_which_screen_and_what_came_back(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(self::URL, [
            'kind'      => 'inertia',
            'message'   => 'MalformedInertiaResponse: Inertia response was not a page: empty body',
            'component' => 'Day',
            'context'   => '{"at":"inertia-response","fault":"empty body","status":200,'
                .'"type":"application/json","bytes":0,"method":"get","retried":true,"value":"Error"}',
        ])->assertNoContent();

        $error = ClientError::query()->sole();

        // `inertia` is its own kind, not `error`: the only one that arrives with
        // a context — the difference between a report and a shrug.
        $this->assertSame('inertia', $error->kind);
        $this->assertSame('Day', $error->component);

        $this->assertSame(200, json_decode((string) $error->context, true)['status']);
        $this->assertSame('empty body', json_decode((string) $error->context, true)['fault']);
    }

    public function test_a_report_from_before_these_columns_existed_is_still_a_report(): void
    {
        $this->actingAs(User::factory()->create());

        // A page open across the deploy still runs the old bundle and sends
        // neither field. It must not 422.
        $this->postJson(self::URL, ['message' => 'boom', 'kind' => 'rejection'])
            ->assertNoContent();

        $error = ClientError::query()->sole();

        $this->assertNull($error->component);
        $this->assertNull($error->context);
    }

    /**
     * The context is truncated on both sides, so what lands can be JSON with
     * its tail cut off. That is why the column is text and not `json`: Postgres
     * answers invalid JSON with an exception, thrown by the INSERT inside the
     * one endpoint whose whole contract is that it cannot fail. A missing brace
     * is a nuisance; a 500 in the crash reporter hides crashes.
     */
    public function test_an_oversized_context_is_truncated_rather_than_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(self::URL, [
            'message'   => 'boom',
            'kind'      => 'inertia',
            'component' => str_repeat('Component/', 40),
            'context'   => '{"head":"'.str_repeat('x', 5000).'"}',
        ])->assertNoContent();

        $error = ClientError::query()->sole();

        $this->assertSame(64, mb_strlen((string) $error->component));
        $this->assertSame(1000, mb_strlen((string) $error->context));
    }

    public function test_a_crash_loop_costs_ten_rows_a_minute_and_no_more(): void
    {
        $this->actingAs(User::factory()->create());

        /*
         * A component that throws on every render, on a phone nobody is
         * watching. The client caps itself at five per page load and de-dupes
         * messages, but the client is the broken thing, so this is the ceiling
         * that matters.
         */
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::URL, ['message' => "boom {$i}"])->assertNoContent();
        }

        $this->postJson(self::URL, ['message' => 'boom 11'])->assertStatus(429);

        $this->assertSame(10, ClientError::query()->count());
    }

    public function test_the_health_endpoint_counts_what_came_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::URL, [
                'message' => 'Failed to load script',
                'kind'    => 'error',
                'url'     => 'https://health.example.com/',
                'build'   => 'deadbeefcafe',
            ])
            ->assertNoContent();

        /*
         * A count on /api/health is what makes this table something anybody
         * looks at: `ingest` answers "is the phone still sending?", this answers
         * "can the phone still draw anything?", and both fail silently.
         *
         * The COUNT is also ALL of it, because that endpoint is unauthenticated:
         * a crash message quotes the undefined property and the url names the
         * page somebody was reading, so those stay behind the app's own auth in
         * `select * from client_errors order by id desc limit 20`. See
         * HealthStatusController.
         */
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('client.errors.total', 1)
            ->assertJsonPath('client.errors.last_24h', 1)
            ->assertJsonPath('client.errors.latest.kind', 'error')
            ->assertJsonMissingPath('client.errors.latest.message')
            ->assertJsonMissingPath('client.errors.latest.build');
    }

    public function test_health_reports_no_client_errors_when_there_are_none(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('client.errors.total', 0)
            ->assertJsonPath('client.errors.latest', null);
    }
}
