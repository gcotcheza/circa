<?php

declare(strict_types=1);

namespace App\Services\Report;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use App\Enums\HealthReportKind;
use App\Services\Support\LocalDates;

/**
 * The window a report covers: two local dates, inclusive at both ends.
 *
 * A type rather than two strings because four callers build a range — the
 * controller from a form, the artisan command from flags, the weekly cron
 * from a clock, and every test — and all four must agree on the same four
 * rules:
 *
 *   1. INCLUSIVE BOTH ENDS. "The last 7 days" ending today is today and the
 *      six before it, not seven before it. Off by one here is off by one in
 *      every figure in the report.
 *   2. THE FUTURE IS NOT IN RANGE. A range clamps at today, because
 *      tomorrow's row would read as a day the user logged nothing.
 *   3. ORDER IS NORMALISED. from > to is a typo, not a request for a
 *      backwards report.
 *   4. THERE IS A CEILING, AND IT DEPENDS ON THE FOCUS. The food ledger
 *      keeps the 92-day quarter it always had (one entry per item eaten,
 *      not one row per day); a report focused on stress, sleep, training or
 *      weight is linear in days and may run to a year. The number lives on
 *      ReportFocus, not here (config('health.report.focus.max_range_days'))
 *      — and so does the rejection message, because "at most 92 days" in
 *      front of somebody who just tapped a 6-month chip is unresolvable
 *      from the screen.
 *
 * Duplicating those in four places is how three end up agreeing and one
 * quietly doesn't.
 *
 * Dates are plain YYYY-MM-DD strings in the app's reporting timezone — the
 * space `daily_summaries.local_date`, `stress_daily.local_date` and
 * `meals.local_date` already live in. Nothing here converts a timezone,
 * because nothing downstream has to.
 */
final readonly class ReportRange
{
    private function __construct(
        public string $start,
        public string $end,
        public int $days,
    ) {}

    /**
     * A range from two date strings, clamped and normalised.
     *
     * `$focus` decides the ceiling and the message; omitted, it's
     * `ReportFocus::everything()` — the full assembly and 92-day quarter,
     * what every caller meant before focused reports existed.
     *
     * @throws InvalidArgumentException when either date is unparseable or the
     *                                  span exceeds the focus's ceiling
     */
    public static function between(string $start, string $end, ?CarbonImmutable $today = null, ?ReportFocus $focus = null): self
    {
        $focus ??= ReportFocus::everything();

        $tz = (string) config('health.timezone');
        $today ??= CarbonImmutable::now($tz)->startOfDay();

        $from = self::parse($start, $tz);
        $to = self::parse($end, $tz);

        // Rule 3, before rule 2 — clamping a backwards range would otherwise
        // pin the wrong end.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Rule 2. A window into the future ends today instead. The START is
        // deliberately NOT clamped: a range entirely in the future is a
        // mistake worth reporting, not one worth silently rewriting into
        // "today".
        if ($to->greaterThan($today)) {
            $to = $today;
        }

        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('A report cannot cover a range that has not happened yet.');
        }

        $days = (int) $from->diffInDays($to) + 1;

        if ($days > $focus->maxRangeDays()) {
            throw new InvalidArgumentException($focus->ceilingMessage());
        }

        return new self($from->toDateString(), $to->toDateString(), $days);
    }

    /**
     * The last N days, ending today — what every preset chip means.
     *
     * N counts the end day, so `lastDays(7)` is today and the six before it.
     */
    public static function lastDays(int $days, ?CarbonImmutable $today = null, ?ReportFocus $focus = null): self
    {
        $tz = (string) config('health.timezone');
        $today ??= CarbonImmutable::now($tz)->startOfDay();

        $days = max(1, $days);

        return self::between(
            $today->subDays($days - 1)->toDateString(),
            $today->toDateString(),
            $today,
            $focus,
        );
    }

    /**
     * The complete week BEFORE the one $today falls in — Monday to Sunday.
     *
     * The cron's range, and why it runs Monday morning: a week you're still
     * living in isn't one you can report on, and the honest thing to
     * summarise at 04:10 is the seven days that finished six hours ago.
     */
    public static function previousWeek(?CarbonImmutable $today = null): self
    {
        $tz = (string) config('health.timezone');
        $today ??= CarbonImmutable::now($tz)->startOfDay();

        $monday = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeek();

        return self::between(
            $monday->toDateString(),
            $monday->addDays(6)->toDateString(),
            $today,
        );
    }

    /**
     * Every date in the range, chronological.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        return LocalDates::inclusive($this->start, $this->end);
    }

    /**
     * The same calendar window $offsetDays earlier — the drift block's
     * year-ago comparison.
     *
     * Shifting BOTH ends by the same amount keeps the two windows the same
     * length, so their medians are comparable. It does not try to land on
     * the same weekday: 365 isn't a multiple of 7, and pulling it to 364 to
     * make it one would drift the season a day a year — the thing the year
     * offset exists to hold still.
     */
    public function shifted(int $offsetDays): self
    {
        $tz = (string) config('health.timezone');

        $from = CarbonImmutable::parse($this->start, $tz)->subDays($offsetDays);
        $to = CarbonImmutable::parse($this->end, $tz)->subDays($offsetDays);

        // Constructed directly rather than through between(): a historical
        // window must not be clamped against today, and is already known
        // to be well-ordered and of legal length.
        return new self($from->toDateString(), $to->toDateString(), $this->days);
    }

    /**
     * The idempotency key for one report.
     *
     * Deterministic for the weekly cron, random for everything else — the
     * whole point. A cron firing twice after a restart must land on the row
     * it already made (the job's pending-status claim makes the second
     * delivery free); a user asking for the same days again wants another
     * look, so collapsing onto the existing row would silently do nothing.
     *
     * The focus rides in the manual key but changes nothing about
     * collisions — the random suffix already guarantees distinct rows
     * regardless. What the fingerprint buys is a key that SAYS what the row
     * is: `manual:2026-02-10:2026-08-09:stress-sleep:…` is legible in psql,
     * unlike an audit column only readable by someone who already knows the
     * answer.
     *
     * The weekly key omits it: the cron never sets a focus, and a future
     * focused weekly would need to collide with the plain key for the same
     * week, since "the report for last week" is one report however asked for.
     */
    public function idempotencyKey(HealthReportKind $kind, ?ReportFocus $focus = null): string
    {
        if ($kind === HealthReportKind::Weekly) {
            return "weekly:{$this->start}:{$this->end}";
        }

        $fingerprint = ($focus ?? ReportFocus::everything())->fingerprint();

        return 'manual:'.$this->start.':'.$this->end.':'.$fingerprint.':'.bin2hex(random_bytes(8));
    }

    private static function parse(string $date, string $tz): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("'{$date}' is not a date in YYYY-MM-DD form.");
        }

        try {
            return CarbonImmutable::parse($date, $tz)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("'{$date}' is not a date this app can read.");
        }
    }
}
