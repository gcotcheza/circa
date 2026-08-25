<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\ConnectionInterface;

/**
 * The mechanical half of the three ingest upserts: parameter tuples,
 * flattened bindings, `excluded.*` boilerplate, and reading `returning
 * (xmax = 0)` back as counts.
 *
 * Only the parts that carry no meaning live here. Every writer still holds
 * its own statement in one readable piece, because the conflict target, the
 * DO UPDATE action and the WHERE that decides what may overwrite what ARE
 * the subject of those classes — SQL assembled from helpers is SQL nobody
 * can review, and these three statements each defend a different lie the
 * exports can tell.
 *
 * The string builders below interpolate straight into SQL and escape
 * nothing: they take IDENTIFIERS — the writers' own column and table name
 * constants — and must never be handed anything that came from a payload.
 *
 * `write()` deliberately stays with each writer despite reading alike in two
 * of them: it is three lines wrapped around that writer's own CHUNK (and its
 * note on the number, where one exists), and the metric writer splits by
 * upsert direction before chunking at all — so a shared default would need
 * an abstract hook HealthMetricWriter could only implement as dead code.
 */
abstract class ChunkedUpsert
{
    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /** `(?, ?), (?, ?)` — one parenthesised tuple of $columns placeholders per row. */
    final protected static function placeholders(int $columns, int $rows): string
    {
        $tuple = '('.implode(', ', array_fill(0, $columns, '?')).')';

        return implode(', ', array_fill(0, $rows, $tuple));
    }

    /**
     * Every row's values end to end, in the writer's column order.
     *
     * @param  list<UpsertRow>  $rows
     * @return list<string|int|bool|null>
     */
    final protected static function bindings(array $rows, CarbonImmutable $ingestedAt): array
    {
        $bindings = [];

        foreach ($rows as $row) {
            $bindings = [...$bindings, ...$row->bindings($ingestedAt)];
        }

        return $bindings;
    }

    /**
     * `col = excluded.col, …` — the DO UPDATE assignment list.
     *
     * @param  list<string>  $columns
     */
    final protected static function assignFromExcluded(array $columns): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => sprintf('%s = excluded.%s', $column, $column),
            $columns
        ));
    }

    /**
     * `t.a, t.b` — one side of a row-wise comparison, qualified by table name
     * or by `excluded`.
     *
     * @param  list<string>  $columns
     */
    final protected static function qualify(string $table, array $columns): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => $table.'.'.$column,
            $columns
        ));
    }

    /**
     * Run one upsert ending in `returning (xmax = 0) as inserted`, and read
     * the three counts off what comes back.
     *
     * This is what makes "unchanged" observable rather than assumed: a row
     * the statement's WHERE refuses writes no tuple version, so RETURNING
     * emits nothing for it and a replay of an already-parsed payload reports
     * inserted=0 updated=0 as a provable no-op. `xmax` is 0 on a fresh tuple
     * and the locking transaction on an updated one — verified against this
     * Postgres 18 instance.
     *
     * useReadPdo: false — an INSERT that happens to RETURNING. On the default
     * it would route to a read replica the day one exists, and inside a test
     * transaction it would be a different connection entirely.
     *
     * @param  list<string|int|bool|null>  $bindings
     */
    final protected function runReturning(string $sql, array $bindings, int $rowCount): UpsertStats
    {
        $returned = $this->connection()->select($sql, $bindings, false);

        $inserted = 0;

        foreach ($returned as $result) {
            if ((bool) $result->inserted) {
                $inserted++;
            }
        }

        return new UpsertStats(
            inserted: $inserted,
            updated: count($returned) - $inserted,
            unchanged: $rowCount - count($returned),
        );
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
