<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use App\Models\User;
use Inertia\Inertia;
use Illuminate\Http\Request;
use App\Http\Middleware\NoStoreHtmlResponses;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The document that boots the app is never stored.
 *
 * The HTML does one thing — name a content-hashed bundle — so a stored copy
 * outliving a deploy is a script tag pointing at a file the build replaced: a
 * blank white screen, no error, no server-side symptom.
 *
 * Production already served `no-cache, private`, but by ACCIDENT — Symfony's
 * default for a response nobody set a policy on, which holds only until the
 * first controller or middleware sets its own. These tests make it a decision.
 */
final class DocumentCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_document_is_never_stored(): void
    {
        $cacheControl = (string) $this->get('/login')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        // `max-age=0` only: a positive one is a window in which a phone can boot
        // a build that has been deleted.
        $this->assertDoesNotMatchRegularExpression('/max-age=[1-9]/', $cacheControl);
    }

    public function test_the_signed_in_document_is_never_stored(): void
    {
        $this->actingAs(User::factory()->create());

        $cacheControl = (string) $this->get('/')->assertOk()->headers->get('Cache-Control');

        /*
         * Directive by directive, not string equality: Symfony alphabetises the
         * header, so a literal match would test its sort order. What matters is
         * that every directive survives.
         */
        foreach (explode(', ', NoStoreHtmlResponses::POLICY) as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
    }

    public function test_an_inertia_partial_is_never_stored_either(): void
    {
        $this->actingAs(User::factory()->create());

        /*
         * The XHR a day-swipe fires. It carries the day's calories as props, so
         * a cached copy is the stale-numbers bug in its purest form.
         */
        /*
         * One ordinary visit first, purely so Inertia's asset version resolves:
         * the middleware sets it during a request, and an XHR announcing the
         * wrong version gets a 409 — correct, but not the response under test.
         */
        $this->get('/')->assertOk();

        $response = $this->withHeaders([
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) Inertia::getVersion(),
        ])->get('/');

        $response->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_offline_page_is_still_cacheable(): void
    {
        /*
         * The one HTML response ALLOWED to be stored: it carries no data, so a
         * month-old copy says what a fresh one says, and the service worker
         * precaches it — breaking that means refetching on every install.
         *
         * It sits outside the `web` group (routes/pwa.php), so the middleware
         * never reaches it; this test notices if that stops being true.
         */
        $cacheControl = (string) $this->get('/offline')->headers->get('Cache-Control');

        $this->assertStringContainsString('max-age=86400', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
    }

    public function test_the_worker_and_the_manifest_keep_their_own_policies(): void
    {
        // /sw.js must revalidate yet stay storable, or the update check stops
        // being a cheap 304. The manifest is read at install time: an hour.
        $this->assertStringNotContainsString('no-store', (string) $this->get('/sw.js')->headers->get('Cache-Control'));

        $this->assertStringContainsString(
            'max-age=3600',
            (string) $this->get('/manifest.webmanifest')->headers->get('Cache-Control'),
        );
    }

    public function test_photo_bytes_are_not_swept_up_by_the_document_policy(): void
    {
        /*
         * The `web` group also carries the photo-streaming routes, cached for a
         * year deliberately: those bytes are written once and never rewritten.
         * The guard is that the middleware tests CONTENT TYPE, not route, so
         * non-HTML non-Inertia responses pass untouched. Asserted against the
         * middleware directly with a JPEG-shaped response — a 404 from the real
         * route would prove nothing.
         */
        $middleware = new NoStoreHtmlResponses;

        $response = $middleware->handle(
            Request::create('/api/meals/x/photos/y/thumb'),
            fn () => response('jpeg-bytes', 200, [
                'Content-Type'  => 'image/jpeg',
                'Cache-Control' => 'private, max-age=31536000, immutable',
            ]),
        );

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
    }
}
