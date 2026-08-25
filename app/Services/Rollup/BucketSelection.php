<?php

declare(strict_types=1);

namespace App\Services\Rollup;

/**
 * What BucketSelector decided for one metric on one day.
 *
 * Keeps the rejects, not just the total: "why is Tuesday's burn 300 kcal
 * lower than Monday's?" has three possible answers — buckets never there,
 * lost to a higher-priority source, or refused as an impossible rate
 * (BucketSelector::isImpossibleRate).
 */
final readonly class BucketSelection
{
    /**
     * @param  list<MetricBucket>  $accepted
     * @param  list<MetricBucket>  $rejected
     */
    public function __construct(
        public array $accepted = [],
        public array $rejected = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->accepted === [];
    }

    public function total(): ?float
    {
        if ($this->accepted === []) {
            return null;
        }

        return array_sum(array_map(
            static fn (MetricBucket $b): float => $b->value,
            $this->accepted
        ));
    }

    public function coveredSeconds(): int
    {
        return array_sum(array_map(
            static fn (MetricBucket $b): int => $b->durationSeconds(),
            $this->accepted
        ));
    }

    /**
     * Covered seconds contributed by the given device kinds — what "was the
     * Watch on the wrist?" reduces to, since accepted buckets are already
     * non-overlapping.
     *
     * @param  list<string>  $kinds  DeviceKind values
     */
    public function coveredSecondsBy(array $kinds): int
    {
        $seconds = 0;

        foreach ($this->accepted as $bucket) {
            if (in_array($bucket->deviceKind->value, $kinds, strict: true)) {
                $seconds += $bucket->durationSeconds();
            }
        }

        return $seconds;
    }

    /** The winning bucket, for metrics where "latest sample" is the answer. */
    public function latest(): ?MetricBucket
    {
        $latest = null;

        foreach ($this->accepted as $bucket) {
            if ($latest === null || $bucket->startedAt > $latest->startedAt) {
                $latest = $bucket;
            }
        }

        return $latest;
    }
}
