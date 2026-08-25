<?php

declare(strict_types=1);

namespace App\Services\Fitage;

/**
 * What one metric in one file amounts to, split the way the upsert splits it.
 *
 * Same three-way split UpsertStats makes, for the same reason: `duplicate`
 * is the number that proves idempotence — re-running an import over a
 * directory that gained one file must report every other file's rows as
 * duplicate, a claim "the row counts came out the same" can't make.
 *
 * `changed` is kept separate because it should be rare and is worth
 * noticing: the same instant, same source, a DIFFERENT value than last
 * time — the user re-exported a window the scale had since revised.
 */
final class MetricTally
{
    public function __construct(
        /** Values present in the file. */
        public int $found = 0,
        /** Not in the database yet. */
        public int $new = 0,
        /** Present, identical — the upsert will not write a tuple. */
        public int $duplicate = 0,
        /** Present with a different value — the upsert will replace it. */
        public int $changed = 0,
    ) {}

    public function add(self $other): void
    {
        $this->found += $other->found;
        $this->new += $other->new;
        $this->duplicate += $other->duplicate;
        $this->changed += $other->changed;
    }
}
