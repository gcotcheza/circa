<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * What one batch of upserts actually did.
 *
 * The three-way split is the whole idempotence proof. A row that conflicts and
 * would not change anything is `unchanged` — the writer's ON CONFLICT clause
 * carries a WHERE that skips the update entirely, so Postgres does not even
 * write a new tuple version. Replaying a payload therefore reports
 * inserted=0 updated=0 and touches nothing, which is a stronger statement than
 * "the row counts came out the same".
 */
final class UpsertStats
{
    public function __construct(
        public int $inserted = 0,
        public int $updated = 0,
        public int $unchanged = 0,
    ) {}

    public function add(self $other): void
    {
        $this->inserted += $other->inserted;
        $this->updated += $other->updated;
        $this->unchanged += $other->unchanged;
    }

    /** Rows this batch is responsible for, changed or not. */
    public function total(): int
    {
        return $this->inserted + $this->updated + $this->unchanged;
    }

    public function touched(): int
    {
        return $this->inserted + $this->updated;
    }

    /** @return array{inserted: int, updated: int, unchanged: int} */
    public function toArray(): array
    {
        return [
            'inserted'  => $this->inserted,
            'updated'   => $this->updated,
            'unchanged' => $this->unchanged,
        ];
    }
}
