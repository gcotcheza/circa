<?php

declare(strict_types=1);

namespace App\Services\Rollup;

/**
 * Turn a day's worth of overlapping, multi-source buckets into a set that
 * can safely be added up: greedy non-overlapping selection, priority-first
 * (source rank, started_at, longer bucket first, id), refusing any bucket
 * whose implied rate is physically impossible. See SPEC.md "Bucket
 * alignment is NOT always hourly" and docs/rationale-app.md §
 * "BucketSelector: bucket overlap and rate-ceiling refusal" for the full
 * reasoning.
 */
final class BucketSelector
{
    /** @var array<string, float> metric => kcal/h a body cannot exceed */
    private array $ceilings;

    private int $minSeconds;

    /**
     * @param  array<string, mixed>|null  $rateCeiling  config('health.rollup.rate_ceiling')
     */
    public function __construct(
        private readonly SourcePriority $priority = new SourcePriority,
        ?array $rateCeiling = null,
    ) {
        $rateCeiling ??= (array) config('health.rollup.rate_ceiling', []);

        /** @var array<string, float> $ceilings */
        $ceilings = (array) ($rateCeiling['kcal_per_hour'] ?? []);

        $this->ceilings = $ceilings;
        $this->minSeconds = (int) ($rateCeiling['min_seconds'] ?? 60);
    }

    /**
     * @param  list<MetricBucket>  $buckets  candidates for ONE metric
     */
    public function select(string $metric, array $buckets): BucketSelection
    {
        $candidates = $this->ordered($metric, $buckets);

        $accepted = [];
        $rejected = [];

        foreach ($candidates as $bucket) {
            // Before the overlap walk, so an impossible bucket claims no
            // span — the hour stays open for whatever else reported it, or
            // counts as uncovered.
            if ($this->isImpossibleRate($metric, $bucket)) {
                $rejected[] = $bucket;

                continue;
            }

            foreach ($accepted as $kept) {
                if ($bucket->overlaps($kept)) {
                    $rejected[] = $bucket;

                    continue 2;
                }
            }

            $accepted[] = $bucket;
        }

        // Chronological for the caller; selection order was only ever a means.
        usort(
            $accepted,
            static fn (MetricBucket $a, MetricBucket $b): int => $a->startedAt <=> $b->startedAt ?: $a->id <=> $b->id
        );

        return new BucketSelection($accepted, $rejected);
    }

    /**
     * The one sample that represents the day, for instant metrics (weight,
     * body fat) — not a sum, since stepping on the scale twice doesn't make
     * you heavier. Highest-priority source first (scale beats a manual
     * entry), then the latest sample, since a re-weigh is a correction.
     *
     * @param  list<MetricBucket>  $buckets
     */
    public function pickInstant(string $metric, array $buckets): ?MetricBucket
    {
        return $this->ordered($metric, $buckets)[0] ?? null;
    }

    /**
     * A bucket whose implied rate is physically impossible (e.g. a sync
     * catch-up bucket dumping hours of modeled basal into one hour) is
     * refused here rather than in the builder, so its span reads as
     * uncovered rather than being summed as real. See config/health.php
     * `rollup.rate_ceiling` and docs/rationale-app.md § "BucketSelector:
     * bucket overlap and rate-ceiling refusal" for the full reasoning and
     * which UI caveat this trips.
     */
    private function isImpossibleRate(string $metric, MetricBucket $bucket): bool
    {
        $ceiling = (float) ($this->ceilings[$metric] ?? 0.0);

        // `<= 0`, not `isInstant()`: a backwards-stamped bucket has a
        // negative duration (also not a rate), and this keeps the division
        // below safe if `min_seconds` is ever configured to nothing.
        if ($ceiling <= 0.0 || $bucket->durationSeconds() <= 0) {
            return false;
        }

        $seconds = max($bucket->durationSeconds(), $this->minSeconds);

        // Strictly greater: a bucket exactly at the ceiling is the most extreme
        // hour still allowed to be real, and the guard is for what cannot be.
        return $bucket->value / ($seconds / 3600) > $ceiling;
    }

    /**
     * @param  list<MetricBucket>  $buckets
     * @return list<MetricBucket>
     */
    private function ordered(string $metric, array $buckets): array
    {
        $ranked = array_map(
            fn (MetricBucket $b): array => [
                'rank'   => $this->priority->rankFor($metric, $b->deviceKind),
                'bucket' => $b,
            ],
            $buckets
        );

        usort($ranked, static function (array $a, array $b): int {
            /** @var MetricBucket $x */
            $x = $a['bucket'];
            /** @var MetricBucket $y */
            $y = $b['bucket'];

            return $a['rank'] <=> $b['rank']
                // An instant metric wants the LATEST sample first; an interval
                // metric wants the earliest, so the walk is chronological.
                ?: ($x->isInstant() && $y->isInstant()
                    ? $y->startedAt <=> $x->startedAt
                    : $x->startedAt <=> $y->startedAt)
                // Prefer the bucket that explains more of the day when two
                // start together — a 1 h bucket beats a 15 min one.
                ?: $y->durationSeconds() <=> $x->durationSeconds()
                // Total order, so a rebuild is reproducible row for row.
                ?: $x->id <=> $y->id;
        });

        return array_column($ranked, 'bucket');
    }
}
