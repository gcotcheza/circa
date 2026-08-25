<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The three routes "Add to Home Screen" and the service worker depend on.
 *
 * Asserted hard because this is the part of the app that fails INVISIBLY: a
 * manifest served as `application/octet-stream` downloads instead of installing,
 * an icon path that 404s installs with a screenshot of the page as its icon, and
 * neither shows up as an error — you find out by looking at a home screen.
 */
final class InstallabilityTest extends TestCase
{
    /*
     * The head assertion signs in and renders the daily view, which BUILDS a
     * daily_summaries row for today. Without the rollback that row is committed
     * and every later test counts it — its absence broke an idempotence assertion
     * four test classes away, in a file this one does not touch.
     */
    use RefreshDatabase;

    public function test_the_manifest_is_served_with_the_manifest_content_type(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();

        // Why this route exists: nginx's stock mime.types has no .webmanifest
        // entry, and the fallback is a download.
        $this->assertSame(
            'application/manifest+json',
            $response->headers->get('Content-Type'),
        );
    }

    public function test_the_manifest_describes_a_standalone_app_rooted_at_the_origin(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertJsonPath('name', 'Health Tracker')
            ->assertJsonPath('short_name', 'Health')
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('scope', '/')
            // Anything but `standalone` keeps a URL bar: an app or a bookmark.
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('theme_color', config('health.pwa.theme_color'))
            ->assertJsonPath('background_color', config('health.pwa.background_color'));
    }

    public function test_every_icon_the_manifest_names_exists_on_disk(): void
    {
        $icons = $this->get('/manifest.webmanifest')->json('icons');

        $this->assertNotEmpty($icons);

        foreach ($icons as $icon) {
            $this->assertFileExists(
                public_path(ltrim($icon['src'], '/')),
                "The manifest names {$icon['src']}, which is not there. Run `php artisan pwa:icons`.",
            );
        }
    }

    public function test_a_maskable_icon_is_declared_separately_from_the_plain_one(): void
    {
        $iconsJson = $this->get('/manifest.webmanifest')->json('icons');
        self::assertIsArray($iconsJson);
        $icons = collect($iconsJson);

        $maskable = $icons->where('purpose', 'maskable');

        $this->assertGreaterThan(0, $maskable->count(), 'No maskable icon is declared.');

        // One file tagged "any maskable" is the shortcut not taken: the two
        // croppings are different drawings.
        $this->assertTrue(
            $maskable->pluck('src')->intersect($icons->where('purpose', 'any')->pluck('src'))->isEmpty(),
            'The maskable icon is the same file as the plain one; it needs its own, smaller, cropping.',
        );
    }

    public function test_the_apple_touch_icon_is_a_real_opaque_png(): void
    {
        $path = public_path('icons/apple-touch-icon-180.png');

        $this->assertFileExists($path);

        $size = getimagesize($path);
        self::assertIsArray($size);

        $this->assertSame(180, $size[0]);
        $this->assertSame(180, $size[1]);
        $this->assertSame('image/png', $size['mime']);

        // iOS composites a transparent apple-touch-icon onto BLACK, so it must be
        // opaque — asserted rather than assumed of `imagecreatetruecolor`.
        $image = imagecreatefrompng($path);
        self::assertNotFalse($image, "{$path} could not be decoded as a PNG.");
        $corner = imagecolorat($image, 0, 0);

        $this->assertSame(0, ($corner >> 24) & 0x7F, 'The corner pixel is transparent; iOS will render it black.');

        imagedestroy($image);
    }

    public function test_the_offline_page_renders_for_a_guest_and_asks_for_nothing(): void
    {
        $response = $this->get('/offline');

        $response->assertOk();

        $html = $response->getContent();
        self::assertIsString($html);

        // Every asset referenced is one more thing that must already be cached,
        // on the one page that only ever draws when the network is gone.
        $this->assertStringNotContainsString('<script src', $html);
        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringNotContainsString('rel="stylesheet"', $html);

        // And no data: precached for weeks, a number would date from install day.
        $this->assertStringNotContainsString('kcal', $html);
    }

    public function test_the_pwa_routes_are_reachable_without_signing_in(): void
    {
        // The OS reads the manifest at install time, the worker is registered from
        // the login page, and /offline shows when a redirect cannot be followed.
        $this->get('/manifest.webmanifest')->assertOk();
        $this->get('/sw.js')->assertOk();
        $this->get('/offline')->assertOk();
    }

    public function test_the_pwa_routes_start_no_session(): void
    {
        /*
         * SESSION_DRIVER is `database`, and a browser revalidates /sw.js on every
         * navigation. Inside the `web` middleware group each check would write a
         * `sessions` row for a visitor who is not one — a table this app is under
         * instructions never to prune. Hence routes/pwa.php and the empty group
         * in bootstrap/app.php.
         */
        foreach (['/manifest.webmanifest', '/sw.js', '/offline'] as $url) {
            $response = $this->get($url);

            $response->assertOk();

            $this->assertEmpty(
                $response->headers->getCookies(),
                "{$url} started a session. It must be registered outside the web middleware group.",
            );
        }
    }

    public function test_the_head_carries_what_ios_reads(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/')->getContent();
        self::assertIsString($html);

        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
        $this->assertStringContainsString('rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable" content="yes"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-status-bar-style"', $html);

        // viewport-fit=cover makes env(safe-area-inset-*) non-zero, which the
        // bottom nav's padding depends on.
        $this->assertStringContainsString('viewport-fit=cover', $html);
    }
}
