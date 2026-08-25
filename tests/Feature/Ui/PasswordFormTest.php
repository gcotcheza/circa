<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * The change-password section on the profile page, read off the source.
 *
 * Read rather than rendered for ProfileFormTest's reason: no component renderer
 * here, so the claims that must hold get read out of the template. Two matter:
 *
 *   IT NEVER TOUCHES THE OFFLINE QUEUE. A password change is a security action;
 *   the queue (resources/js/lib/queue.js) is opt-in — only submitOrQueue() and
 *   enqueue() callers are queued, deduped or replayed — and a replayed one
 *   would leave no server-side symptom to catch it by.
 *
 *   iOS IS TOLD TO OFFER THE KEYCHAIN. `autocomplete="current-password"` on the
 *   old box and `autocomplete="new-password"` on the two new ones are what make
 *   the phone offer to save the new password; the only client is an iPhone.
 */
final class PasswordFormTest extends TestCase
{
    use ReadsSource;

    private const PAGE = 'resources/js/Pages/Profile.vue';

    public function test_the_profile_page_carries_a_change_password_section(): void
    {
        $page = $this->source(self::PAGE);

        self::assertStringContainsString('Change password', $page);
        self::assertStringContainsString("passwordForm.put('/profile/password'", $page);
    }

    public function test_the_three_password_boxes_are_present_and_typed(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertStringContainsString('v-model="passwordForm.current_password"', $code);
        self::assertStringContainsString('v-model="passwordForm.password"', $code);
        self::assertStringContainsString('v-model="passwordForm.password_confirmation"', $code);

        // Three boxes, and every one of them masked.
        self::assertSame(3, substr_count($code, 'type="password"'));
    }

    /** Without these the phone never offers to save the new password. */
    public function test_the_autocomplete_hints_are_set_for_ios(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertSame(1, substr_count($code, 'autocomplete="current-password"'));
        self::assertSame(2, substr_count($code, 'autocomplete="new-password"'));
    }

    /**
     * THE CLAIM THIS FEATURE TURNS ON: a plain Inertia PUT, with no route to
     * the offline queue at all — neither the module nor either of its two entry
     * points is imported, so nothing here can be replayed an hour later.
     */
    public function test_the_change_never_goes_through_the_offline_queue(): void
    {
        // Comments stripped: what is WIRED to the queue, not what docblocks name.
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertStringContainsString("passwordForm.put('/profile/password'", $code);

        self::assertStringNotContainsString('submitOrQueue', $code);
        self::assertStringNotContainsString('enqueue', $code);
        self::assertStringNotContainsString('lib/queue', $code);
    }

    /**
     * A button with an @click, never a native submit — ProfileFormTest asserts
     * no submit on the whole page; this names the obvious place to add one.
     */
    public function test_the_change_is_driven_by_a_plain_button(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertStringContainsString('@click="changePassword"', $code);
        self::assertStringNotContainsString('type="submit"', $code);
    }
}
