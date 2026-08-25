<?php

declare(strict_types=1);

namespace Tests\Support;

use PDOException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Services\Ingest\HealthMetricWriter;
use Illuminate\Database\PostgresConnection;

/**
 * A connection that fails the metric upsert, and only for a marked payload.
 *
 * A payload-level failure used to be easy to provoke: any over-long string or
 * unstorable number reached Postgres and failed the transaction. That was the
 * bug this branch fixes, and the fixture went with it — per-datapoint faults are
 * now skipped, so the only thing left that can fail a whole run is the database
 * saying no.
 *
 * Which still has to be tested: the run marked failed, the raw bytes surviving,
 * the run row not rolled back with the write, and `ingest_runs.error` recording
 * the fault WITHOUT the bindings. That last needs a real QueryException over the
 * real upsert's real bindings, so this decorates the live connection rather than
 * inventing a statement.
 *
 * Marked rather than blanket, because `ingest:replay` has to be shown carrying
 * on past a bad payload to the good ones either side of it.
 */
final class FailingMetricConnection extends PostgresConnection
{
    /**
     * SQLSTATE 22003 — numeric value out of range. An int because
     * `Exception::__construct` types `$code` as one under strict_types; a real
     * driver hands back the same five characters as a string, and IngestErrorText
     * renders either.
     */
    private const SQL_STATE = 22003;

    private string $marker = '';

    /** A writer refusing any upsert bound to `$marker` — any payload with that metric. */
    public static function writerRefusing(string $marker): HealthMetricWriter
    {
        $real = DB::connection();

        $connection = new self(
            $real->getPdo(),
            $real->getDatabaseName(),
            $real->getTablePrefix(),
            (array) $real->getConfig()
        );

        $connection->marker = $marker;

        return new HealthMetricWriter($connection);
    }

    /**
     * {@inheritdoc}
     *
     * @param  string  $query
     * @param  array<int, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<int, mixed>  $fetchUsing
     * @return array<int, mixed>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (in_array($this->marker, $bindings, true)) {
            throw new QueryException(
                'pgsql',
                $query,
                $bindings,
                new PDOException('SQLSTATE['.self::SQL_STATE.']: Numeric value out of range', self::SQL_STATE)
            );
        }

        return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
    }
}
