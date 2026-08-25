<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use Tests\TestCase;
use App\Models\User;
use Tests\Concerns\ReadsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The defences against "a completely blank white page — no header, nothing".
 *
 * The whole failure lives in a browser: an out-of-range scroll during load, a
 * mount that throws, a chunk that 404s after a deploy. There is no PHP in it and
 * no headless WebKit here to drive, so — like the tests in tests/Feature/Ui —
 * these read the source and assert that the specific line whose absence caused
 * the bug is still there. Weaker than a browser test, stronger than nothing, and
 * precise about which line and why.
 *
 * The blade assertions ARE real: that document is rendered by this application
 * and the response is the thing under test.
 */
final class BlankScreenDefencesTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const APP_JS = 'resources/js/app.js';

    private const BOOT = 'resources/js/lib/boot.js';

    private const REPORT = 'resources/js/lib/report.js';

    private const SW = 'resources/js/service-worker.js';

    private const SW_CLIENT = 'resources/js/lib/sw.js';

    // ---------------------------------------------------------------- boot

    public function test_the_document_shows_something_before_the_app_does(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        self::assertIsString($html);

        /*
         * The body used to be `<div id="app"></div>`, and an empty root IS a
         * blank white page for as long as 338 KB of JavaScript takes to arrive
         * and run on a phone: invisible when that window is short, and
         * indistinguishable from a crash when it is not.
         */
        $this->assertStringContainsString('id="boot"', $html);

        // Hidden by CSS the moment #app has content, not by a script call that
        // the failure it exists for could skip.
        $this->assertStringContainsString('#app:not(:empty) + #boot', $html);

        // The way out needs no JavaScript: `/` is a fresh document with fresh
        // script tags — the fix when the build this document names is gone.
        $this->assertMatchesRegularExpression('/<a href="\/">/', $html);
    }

    public function test_the_empty_app_root_is_never_a_zero_height_void(): void
    {
        /*
         * A zero-height document has nowhere to restore a scroll offset to; the
         * floor makes an out-of-range restore clamp instead of stranding the
         * viewport on an unpainted region.
         */
        $loginHtml = $this->get('/login')->getContent();
        self::assertIsString($loginHtml);
        $this->assertStringContainsString('#app { min-height: 100dvh; }', $loginHtml);
    }

    public function test_the_page_carries_the_build_it_was_served_by(): void
    {
        $this->actingAs(User::factory()->create());

        // Read by lib/report.js and sent with every crash report; without it a
        // report is indistinguishable from one by a tab open across deploys.
        $homeHtml = $this->get('/')->getContent();
        self::assertIsString($homeHtml);
        $this->assertMatchesRegularExpression(
            '/<meta name="app-build" content="[^"]+">/',
            $homeHtml,
        );
    }

    public function test_a_reload_starts_at_the_top(): void
    {
        $boot = $this->code(self::BOOT);

        /*
         * @inertiajs/core takes scroll restoration off the browser
         * (`history.scrollRestoration = 'manual'`) and does it itself: on a
         * `reload` navigation it calls `window.scrollTo` one animation frame
         * after the component swap, before photographs and charts have laid
         * out. On iOS WebKit an out-of-range programmatic scroll during load
         * leaves an unpainted white viewport — the reported symptom exactly.
         * The fix is to recognise a reload and zero the two history keys
         * Inertia restores from, before `createInertiaApp` reads them.
         */
        $this->assertStringContainsString("=== 'reload'", $boot);
        $this->assertStringContainsString('documentScrollPosition', $boot);
        $this->assertStringContainsString('scrollRegions', $boot);

        $app = $this->code(self::APP_JS);

        // Order is the whole of it: Inertia reads history.state inside
        // createInertiaApp, so a later guard guards nothing.
        $this->assertLessThan(
            mb_strpos($app, 'createInertiaApp({'),
            mb_strpos($app, 'startAtTopOnReload()'),
            'startAtTopOnReload must run before createInertiaApp or Inertia has already restored the scroll.',
        );
    }

    // ------------------------------------------------------- error boundary

    public function test_a_failed_mount_draws_a_message_instead_of_nothing(): void
    {
        $app = $this->code(self::APP_JS);

        /*
         * Nothing sits above the mount — no server-rendered HTML, no retry, no
         * framework boundary — so a throw here leaves #app empty indefinitely.
         */
        $this->assertStringContainsString('renderBootFailure(el)', $app);
        $this->assertStringContainsString('app.config.errorHandler', $app);

        // createInertiaApp is async and can reject BEFORE setup runs: a page
        // that fails to resolve, a chunk that 404s after a deploy.
        $this->assertStringContainsString(
            'renderBootFailure(document.getElementById(\'app\'))',
            $app,
            'a rejection from createInertiaApp itself is the silent blank page this catch exists for.',
        );
    }

    public function test_the_fallback_never_interpolates_the_error_into_the_dom(): void
    {
        $boot = $this->code(self::BOOT);

        // Worse than a blank screen: a blank screen with an injection in it.
        // Built with createElement/textContent, never innerHTML.
        $this->assertStringNotContainsString('innerHTML', $boot);
        $this->assertStringContainsString('createElement', $boot);
    }

    // ------------------------------------------------------------ reporting

    public function test_the_reporter_can_never_become_the_problem(): void
    {
        $report = $this->code(self::REPORT);

        /*
         * Called from inside `window.onerror` and Vue's error handler: a throw
         * there re-enters its own caller, and an awaited fetch turns a render
         * error into an unhandled rejection that fires the reporter again.
         */
        $this->assertStringContainsString('MAX_REPORTS_PER_PAGE', $report);
        $this->assertStringContainsString('keepalive: true', $report);
        $this->assertStringContainsString('.catch(() => {', $report);

        // Both global handlers, the `error` one in the CAPTURE phase: the only
        // way a <script> or dynamic import failing to load is seen at all — the
        // deploy-deleted chunk, which has no message and does not bubble.
        $this->assertMatchesRegularExpression(
            "/window\.addEventListener\(\s*'error',.*?\n\s*true,\n\s*\)/s",
            $report,
            'the error listener lost its capture flag, which is the only way a failed script load is seen at all.',
        );

        $this->assertStringContainsString("addEventListener('unhandledrejection'", $report);

        $this->assertStringContainsString('export function installErrorReporting()', $report);
    }

    public function test_the_reporter_sends_no_more_than_the_page_and_the_build(): void
    {
        $report = $this->code(self::REPORT);

        // The payload is one object literal: anything reading page props, form
        // state or storage would have to appear here.
        $this->assertStringNotContainsString('localStorage', $report);
        $this->assertStringNotContainsString('indexedDB', $report);
        $this->assertStringNotContainsString('usePage', $report);

        // The user agent is the server's to read, not the client's to claim.
        $this->assertStringNotContainsString('navigator.userAgent', $report);
    }

    // ------------------------------------------------------ worker updates

    public function test_a_missing_build_asset_reaches_the_page(): void
    {
        $worker = $this->code(self::SW);

        /*
         * A 404 on a content-hashed URL is never a typo: a document or bundle
         * outlived the deploy that replaced its build, and left alone is a dead
         * button or a blank screen that never recovers.
         */
        $this->assertStringContainsString("type: 'build-missing'", $worker);
        $this->assertStringContainsString('response.status === 404', $worker);

        $client = $this->code(self::SW_CLIENT);

        $this->assertStringContainsString("event.data?.type !== 'build-missing'", $client);
    }

    public function test_an_update_is_taken_by_a_page_nobody_is_looking_at(): void
    {
        $client = $this->code(self::SW_CLIENT);

        /*
         * An ignored prompt — once the only route — means a phone three deploys
         * behind. Hidden pages take the update themselves, and a bfcache restore
         * counts as an arrival.
         */
        $this->assertStringContainsString("document.visibilityState === 'hidden'", $client);
        $this->assertStringContainsString("addEventListener('pageshow'", $client);
        $this->assertStringContainsString('event.persisted', $client);
    }

    public function test_an_open_sheet_is_never_reloaded_out_from_under_the_user(): void
    {
        $client = $this->code(self::SW_CLIENT);

        $this->assertStringContainsString('export function holdUpdate()', $client);
        $this->assertStringContainsString('if (holds > 0) return', $client);

        /*
         * The three surfaces holding typing that exists nowhere else — the
         * promise the original code kept by never auto-reloading, which has to
         * survive auto-reload becoming possible.
         */
        foreach ([
            'resources/js/Components/MealSheet.vue',
            'resources/js/Components/ProposalReview.vue',
            'resources/js/Components/PhotoCapture.vue',
        ] as $component) {
            $this->assertStringContainsString(
                'holdUpdate',
                (string) file_get_contents(base_path($component)),
                $component.' no longer holds the update, so a deploy can reload it mid-edit.',
            );
        }
    }

    public function test_an_automatic_reload_cannot_become_a_loop(): void
    {
        $client = $this->code(self::SW_CLIENT);

        /*
         * An automatic reload is triggered by the condition it fixes. If it
         * does not fix it — the file really is gone, the deploy half-finished —
         * the fresh page hits the same condition and the app becomes a strobe
         * light that never renders long enough to be read.
         */
        $this->assertStringContainsString('mayAutoReload', $client);
        $this->assertStringContainsString('AUTO_RELOAD_COOLDOWN', $client);
    }

    /** The file with its comments removed — JS block and line comments both. */
    private function code(string $path): string
    {
        return $this->sourceWithout(['#/\*.*?\*/#s', '#^\s*//.*$#m'], $path);
    }
}
