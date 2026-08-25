<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * The `component` column: which screen a crash report belongs to.
 *
 * `client_errors` row 7 — the picker crash DatePickerNullBoundsTest fences —
 * was filed `component  Day` against `url  https://health.example.com/profile`.
 * One crash, two fields, different screens; `url` was the true one. The field
 * lib/report.js added to make a minified stack readable had said something
 * false.
 *
 * Ordering, not labelling. @inertiajs/core page.ts runs
 * `this.swap({...}).then(() => { if (!replace) fireNavigateEvent(page) })`, and
 * `swap` calls the vue3 adapter's `swapComponent` — async with no awaits, it
 * just assigns a ref. That queues Vue's flush one microtask ahead of
 * `fireNavigateEvent`, so the new page renders, throws and REPORTS before
 * `inertia:navigate` fires and lib/inertia-guard.js writes the new name down.
 * A crash during a page's first render is thus filed under the page before it —
 * exactly the crash the field exists for.
 *
 * `resolve` runs before the swap and is handed the name outright, so app.js
 * sets it there. That also covers what the event cannot: `fireNavigateEvent` is
 * skipped on a replace visit, so such a page kept the previous name on screen.
 *
 * Read off the source rather than rendered, for ProfileFormTest's reason: no
 * component renderer here, and `setPageComponent` itself is already covered in
 * tests/js/report.test.js. What that cannot cover is WHERE it is called from,
 * which is the entire content of the fix.
 */
final class ReportedPageNameTest extends TestCase
{
    use ReadsSource;

    private const ENTRY = 'resources/js/app.js';

    private const GUARD = 'resources/js/lib/inertia-guard.js';

    public function test_the_page_name_is_written_down_before_the_component_is_swapped(): void
    {
        self::assertMatchesRegularExpression(
            '/resolve:\s*\(name\)\s*=>\s*\{\s*setPageComponent\(name\)/',
            $this->sourceWithoutBlockComments(self::ENTRY),
            'the reported page name is no longer set in `resolve`. Set anywhere later than that and it '
                .'lags the screen by one navigation: a crash during a page load is filed under the '
                .'previous page, which is what client_errors row 7 reads like.'
        );
    }

    /**
     * The listener stays as the correction after an abandoned visit: a second
     * navigation starting mid-resolve leaves the name a beat ahead of the screen.
     */
    public function test_the_navigate_event_is_still_listened_to_as_the_backstop(): void
    {
        $guard = $this->sourceWithoutBlockComments(self::GUARD);

        self::assertStringContainsString("addEventListener('inertia:navigate'", $guard);
        self::assertStringContainsString('setPageComponent(', $guard);
    }
}
