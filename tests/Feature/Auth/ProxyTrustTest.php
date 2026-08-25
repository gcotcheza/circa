<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * Who is allowed to tell this app who the client is, and where it lives.
 *
 * WHAT WENT WRONG. `trustProxies(at: '*')` plus a host vhost forwarding
 * `X-Forwarded-For $proxy_add_x_forwarded_for` — which APPENDS to whatever the
 * client sent — meant Symfony answered `$request->ip()` with a value the client
 * wrote, because it reads the leftmost entry. The login throttle is keyed
 * `email|ip`, so a guesser who changed the header every attempt was never in the
 * same bucket twice and the limiter counted nothing. The same over-trust let
 * `X-Forwarded-Host` decide `getHost()` — the origin of every URL this app
 * generates — with no `trustHosts()` allowlist to stop it landing in one.
 *
 * WHAT THESE TESTS CAN SEE. The fix has a half in nginx (reset the forwarding
 * headers rather than append to them) and a half in bootstrap/app.php (only a
 * private hop may speak for somebody else). No nginx runs here, so this file
 * proves the SECOND half plus the property that makes the first safe to rely on:
 * a request whose immediate peer is not on the trusted list cannot dictate its
 * own IP, scheme or host, and a host off the allowlist never reaches a generated
 * URL at all.
 *
 * The configuration is read off the real middleware rather than restated here —
 * a copy of the list would keep passing after somebody changed
 * bootstrap/app.php, which is the one thing these tests exist to notice.
 */
final class ProxyTrustTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    /**
     * The address php-fpm actually sees: the sidecar's FastCGI requests arrive
     * from the compose bridge gateway, not loopback and not the sidecar's own
     * container address.
     */
    private const SIDECAR = '172.22.0.1';

    protected function tearDown(): void
    {
        // Symfony keeps both in statics on Request; a test that does not put
        // them back leaks into whatever runs next in the same process.
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /** The finding itself: five failures wearing five addresses are still five failures. */
    public function test_a_forged_x_forwarded_for_does_not_buy_a_fresh_throttle_bucket(): void
    {
        $user = $this->user();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email'    => $user->email,
                'password' => 'wrong',
            ], ['X-Forwarded-For' => '203.0.113.'.$attempt])->assertStatus(302);
        }

        // Under `at: '*'` each header was its own `email|ip` bucket, and this
        // was the first attempt of a sixth one, forever.
        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong',
        ], ['X-Forwarded-For' => '198.51.100.42'])->assertStatus(429);
    }

    /**
     * The scheme still comes from the proxy — what trusting one is for, and what
     * secure cookies depend on — but only from a proxy we know about.
     */
    public function test_only_a_private_hop_may_speak_for_the_client(): void
    {
        $fromSidecar = $this->forwarded(self::SIDECAR);

        self::assertSame('203.0.113.9', $fromSidecar->ip());
        self::assertTrue(
            $fromSidecar->isSecure(),
            'https must survive the narrowing, or every secure cookie is dropped.',
        );

        // A mistakenly published port: the same headers from an address that is
        // not one of ours. Nothing in them is believed.
        $fromInternet = $this->forwarded('198.51.100.7');

        self::assertSame('198.51.100.7', $fromInternet->ip());
        self::assertFalse($fromInternet->isSecure());
    }

    public function test_a_forged_x_forwarded_host_never_reaches_a_generated_url(): void
    {
        // TrustHosts is inert under the test runner and `local` — Laravel's own
        // guard, and what lets every other feature test reach `localhost`. So
        // the allowlist is read off the real middleware and applied by hand.
        $patterns = array_filter($this->app->make(TrustHosts::class)->hosts());

        self::assertNotSame([], $patterns, 'bootstrap/app.php registers no trustHosts allowlist.');

        Request::setTrustedHosts($patterns);

        // The two names this app answers to still resolve, from a trusted hop.
        self::assertSame(
            'health.example.com',
            $this->forwarded(self::SIDECAR, 'health.example.com')->getHost(),
        );
        self::assertSame(
            'health-staging.example.com',
            $this->forwarded(self::SIDECAR, 'health-staging.example.com')->getHost(),
        );

        $forged = [
            'attacker.example',
            // Symfony preg_matches each allowlist entry as `{...}i`, so a bare
            // hostname is an UNANCHORED pattern whose dots match any character.
            // These two sail through such a list, so rewriting the allowlist
            // the obvious way fails loudly.
            'health.example.com.attacker.example',
            'healthxexample.com',
        ];

        foreach ($forged as $host) {
            try {
                $accepted = $this->forwarded(self::SIDECAR, $host)->getHost();

                self::fail("A forged X-Forwarded-Host reached getHost(): {$accepted}");
            } catch (SuspiciousOperationException) {
                // Correct: Symfony refuses the host outright rather than
                // returning it, so it cannot become the origin of a link, a
                // redirect or a cached page.
            }
        }
    }

    /**
     * A request as php-fpm receives it, carrying the headers a client would have
     * to forge, run through the REAL TrustProxies middleware with the REAL
     * configuration from bootstrap/app.php.
     */
    private function forwarded(string $remoteAddr, string $forwardedHost = 'health.example.com'): Request
    {
        $request = Request::create('http://health.example.com/login', 'POST', server: [
            'REMOTE_ADDR' => $remoteAddr,
        ]);

        $request->headers->set('X-Forwarded-For', '203.0.113.9');
        $request->headers->set('X-Forwarded-Host', $forwardedHost);
        $request->headers->set('X-Forwarded-Proto', 'https');

        $this->app->make(TrustProxies::class)->handle($request, fn (Request $passed): Request => $passed);

        return $request;
    }

    private function user(): User
    {
        return User::factory()->create([
            'email'    => 'user@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }
}
