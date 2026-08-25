<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * One row destined for `sleep_sessions`.
 *
 * Sleep is interval data and stays whole: stage minutes plus the four
 * boundaries, keyed on HAE's own night date. Deliberately NOT shredded
 * into hourly scalars — a session starting at 23:00 and one starting at
 * 00:39 belong to the same night, and only the exporting app knows which.
 *
 * Stage values arrive in fractional HOURS, stored in MINUTES (the
 * column names are the contract), via the same MetricCatalog
 * conversion that handles kJ -> kcal: one place, or eventually two
 * different answers.
 */
final readonly class SleepRow implements UpsertRow
{
    /**
     * The stage columns are numeric(9,3), topping out just under a
     * million minutes — about two years of one night's sleep. Same
     * guard MetricRow carries, same reason: `(float)` turns "1e400"
     * into INF, which no numeric column can hold, and an unstorable
     * value thrown here would fail the transaction and take every
     * other reading in the POST with it.
     */
    private const MAX_ABS_MINUTES = 1e6;

    /**
     * @param  array<string, string|null>  $minutes  column => decimal string|null
     */
    private function __construct(
        public string $nightDate,
        public int $sourceId,
        public ?CarbonImmutable $inBedStart,
        public ?CarbonImmutable $inBedEnd,
        public ?CarbonImmutable $sleepStart,
        public ?CarbonImmutable $sleepEnd,
        public array $minutes,
        public ?int $deviceUtcOffsetMinutes,
    ) {}

    /**
     * @param  array<string, float|null>  $stageHours  column => hours|null
     *
     * @throws MalformedDatapoint when a stage cannot be stored at all
     */
    public static function make(
        CarbonImmutable $nightDate,
        int $sourceId,
        ?CarbonImmutable $inBedStart,
        ?CarbonImmutable $inBedEnd,
        ?CarbonImmutable $sleepStart,
        ?CarbonImmutable $sleepEnd,
        array $stageHours,
        ?int $deviceUtcOffsetMinutes,
    ): self {
        $minutes = [];

        foreach ($stageHours as $column => $hours) {
            if ($hours === null) {
                $minutes[$column] = null;

                continue;
            }

            $value = MetricCatalog::convert($hours, 'hr', 'min', MetricCatalog::SLEEP);

            if (! is_finite($value) || abs($value) >= self::MAX_ABS_MINUTES) {
                throw MalformedDatapoint::outOfRange(MetricCatalog::SLEEP, $column);
            }

            // numeric(9,3): 5.8720494 hr -> 352.323 min. Three places is
            // sub-tenth-of-a-second on a sleep stage and, like MetricRow's
            // six, makes a replay byte-identical to the first pass.
            $minutes[$column] = sprintf('%.3F', $value);
        }

        return new self(
            nightDate: $nightDate->format('Y-m-d'),
            sourceId: $sourceId,
            inBedStart: $inBedStart?->utc(),
            inBedEnd: $inBedEnd?->utc(),
            sleepStart: $sleepStart?->utc(),
            sleepEnd: $sleepEnd?->utc(),
            minutes: $minutes,
            deviceUtcOffsetMinutes: $deviceUtcOffsetMinutes,
        );
    }

    /** Unique key (night_date, source_id), for in-payload de-duplication. */
    public function identity(): string
    {
        return $this->nightDate.'|'.$this->sourceId;
    }

    /**
     * Is this record at least as complete as `$stored`, and therefore
     * allowed to replace it?
     *
     * The PHP half of SleepSessionWriter's completeness predicate — see
     * the long comment there for why it exists. Kept in step with the
     * SQL so two records for one night inside a SINGLE payload collapse
     * the same way the database would across two payloads:
     *
     *   1. volume   — total_sleep_minutes must not shrink (absent ranks lowest);
     *   2. coverage — the window must not be a strict sub-interval of
     *                 the stored one, what a clipped "Since Last Sync"
     *                 export looks like.
     *
     * Returns true for an identical record: replacing a row with itself
     * is a no-op here and a skipped update in the database.
     */
    public function supersedes(self $stored): bool
    {
        if ($this->totalMinutes() < $stored->totalMinutes()) {
            return false;
        }

        return ! $this->truncates($stored);
    }

    /** total_sleep_minutes as a number, with "not reported" ranked below zero. */
    private function totalMinutes(): float
    {
        $total = $this->minutes['total_sleep_minutes'] ?? null;

        return $total === null ? -1.0 : (float) $total;
    }

    /**
     * Does this record's sleep window sit strictly inside `$stored`'s?
     *
     * A missing boundary makes truncation unprovable, not true — the
     * same choice the SQL makes with `coalesce(..., false)`.
     */
    private function truncates(self $stored): bool
    {
        if ($this->sleepStart === null || $this->sleepEnd === null) {
            return false;
        }

        if ($stored->sleepStart === null || $stored->sleepEnd === null) {
            return false;
        }

        $sameWindow = $this->sleepStart->equalTo($stored->sleepStart)
            && $this->sleepEnd->equalTo($stored->sleepEnd);

        return ! $sameWindow
            && $this->sleepStart >= $stored->sleepStart
            && $this->sleepEnd <= $stored->sleepEnd;
    }

    /**
     * Ordered to match SleepSessionWriter::COLUMNS.
     *
     * @return list<string|int|null>
     */
    public function bindings(CarbonImmutable $ingestedAt): array
    {
        return [
            $this->nightDate,
            $this->sourceId,
            $this->inBedStart?->format('Y-m-d H:i:s.uP'),
            $this->inBedEnd?->format('Y-m-d H:i:s.uP'),
            $this->sleepStart?->format('Y-m-d H:i:s.uP'),
            $this->sleepEnd?->format('Y-m-d H:i:s.uP'),
            $this->minutes['rem_minutes'] ?? null,
            $this->minutes['core_minutes'] ?? null,
            $this->minutes['deep_minutes'] ?? null,
            $this->minutes['awake_minutes'] ?? null,
            $this->minutes['asleep_minutes'] ?? null,
            $this->minutes['in_bed_minutes'] ?? null,
            $this->minutes['total_sleep_minutes'] ?? null,
            $this->deviceUtcOffsetMinutes,
            $ingestedAt->format('Y-m-d H:i:s.uP'),
        ];
    }
}
