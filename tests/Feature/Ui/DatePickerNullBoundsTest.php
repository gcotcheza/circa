<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use Tests\Concerns\ReadsSource;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The calendar, on the ends of the range that are not there.
 *
 * A null `minDate`/`maxDate` throws out of the AirDatepicker constructor — the
 * live fault in `client_errors` row 7 (build e3586ad222ff), which left /profile
 * with no calendar behind the box at all. The option values are pinned in
 * tests/js/air-date.test.js; what only PHP can assert is that the component
 * still routes through that module and that the server still sends the null
 * bounds that made this real.
 * See docs/rationale-frontend.md § "The null date-picker bound"
 */
final class DatePickerNullBoundsTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const PICKER = 'resources/js/Components/AirDate.vue';

    private const MODULE = 'resources/js/lib/air-date.js';

    /** Every file that mounts a calendar. A fifth one should fail this test. */
    private const CALL_SITES = [
        'resources/js/Components/DateJump.vue'   => 1,
        'resources/js/Components/ReportForm.vue' => 2,
        'resources/js/Pages/Profile.vue'         => 1,
    ];

    /** None of the four value-derived options is built in the component: a `.vue` value is untestable. */
    public function test_the_picker_options_are_built_by_the_module_that_has_tests(): void
    {
        self::assertFileExists(base_path(self::MODULE));

        $code = $this->sourceWithoutComments(self::PICKER);

        self::assertStringContainsString(
            "from '../lib/air-date'",
            $code,
            'AirDate no longer reads its options from the module that guarantees they are never null.'
        );

        self::assertStringContainsString('...pickerDates(', $code);

        foreach (['minDate:', 'maxDate:', 'parseIsoDate('] as $inline) {
            self::assertStringNotContainsString(
                $inline,
                $code,
                "AirDate builds `{$inline}` itself again. A bound assembled at the call site is a bound "
                    .'that can be null, and a null option throws out of the AirDatepicker constructor — '
                    .'see client_errors row 7.'
            );
        }
    }

    /**
     * The exact triple that crashed: an empty model and both ends open. Half is
     * the server (no date of birth) and half the template (no bounds, because
     * "that is in the future" is worth reading rather than a greyed-out square).
     * Neither half is wrong alone, which is why they are asserted together.
     */
    public function test_the_date_of_birth_is_an_empty_value_between_two_open_ends(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile')
                ->where('profile.dateOfBirth', null)
                ->etc()
            );

        $tag = $this->pickerTags('resources/js/Pages/Profile.vue')[0];

        self::assertStringContainsString('v-model="form.date_of_birth"', $tag);
        self::assertStringNotContainsString(':min', $tag, 'the date of birth grew a floor.');
        self::assertStringNotContainsString(':max', $tag, 'the date of birth grew a ceiling.');
    }

    /**
     * The custom range: a ceiling, no floor. The same crash with one open end
     * instead of two, and nobody opened the disclosure on the broken build.
     */
    public function test_the_report_range_boxes_are_bounded_at_one_end_only(): void
    {
        $tags = $this->pickerTags('resources/js/Components/ReportForm.vue');

        self::assertCount(2, $tags, 'the custom range is not two boxes any more.');

        foreach ($tags as $tag) {
            self::assertStringContainsString(':max="today"', $tag);
            self::assertStringNotContainsString(':min', $tag);
        }
    }

    /**
     * The two headers that WORKED in production, and only because this database
     * has history. On an empty one the server sends a null floor (see HistorySpan
     * and DateJumpTest) — the same crash waiting for a fresh deploy.
     */
    public function test_the_jump_header_passes_the_history_floor_straight_through(): void
    {
        $tag = $this->pickerTags('resources/js/Components/DateJump.vue')[0];

        self::assertStringContainsString(':min="min"', $tag);
        self::assertStringContainsString(':max="max"', $tag);

        // The prop it comes from is nullable by design, and says so.
        self::assertStringContainsString(
            'min: { type: String, default: null }',
            $this->sourceWithoutComments('resources/js/Components/DateJump.vue')
        );

        $this->actingAs(User::factory()->create());

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Day')
                ->where('earliest', null)
                ->etc()
            );
    }

    /** Four call sites, listed. A fifth is welcome once its shape is in air-date.test.js. */
    public function test_every_calendar_in_the_app_is_one_of_the_four_known_ones(): void
    {
        $total = 0;

        foreach (self::CALL_SITES as $path => $expected) {
            $found = substr_count($this->sourceWithoutComments($path), '<AirDate');

            self::assertSame($expected, $found, $path.' mounts a different number of calendars.');

            $total += $found;
        }

        $mounted = 0;

        $components = $this->sourceFiles('resources/js', 'vue');

        self::assertNotEmpty($components, 'no Vue components found at all.');

        foreach ($components as $path) {
            $mounted += substr_count($this->sourceWithoutComments($path), '<AirDate');
        }

        self::assertSame(
            $total,
            $mounted,
            'a calendar has been mounted somewhere this test does not know about. Add it to CALL_SITES '
                .'and add its prop shape to tests/js/air-date.test.js.'
        );
    }

    /**
     * The opening `<AirDate ...>` tags in a file, comments stripped.
     *
     * @return list<string>
     */
    private function pickerTags(string $path): array
    {
        preg_match_all('/<AirDate\b.*?\/>/s', $this->sourceWithoutComments($path), $matches);

        self::assertNotEmpty($matches[0], $path.' mounts no calendar at all.');

        return $matches[0];
    }
}
