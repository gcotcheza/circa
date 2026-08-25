<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Throwable;
use Illuminate\Database\QueryException;

/**
 * What a failed ingest is allowed to say about itself.
 *
 * `QueryException::getMessage()` isn't the driver's message — Laravel
 * builds it in `formatMessage()` as "(Connection: pgsql, SQL: <statement
 * with every ? replaced by its binding>)", and the bindings of a failing
 * ingest statement ARE the measurements: metric names, values, timestamps
 * straight off the wrist. Storing that verbatim on `ingest_runs.error` put
 * a readable slice of someone's health record into a column served to any
 * unauthenticated caller of /api/health.
 *
 * So a QueryException is reduced to two facts about the FAULT, not the
 * data: the exception class and the driver's SQLSTATE — enough to tell a
 * numeric overflow (22003) from a length violation (22001) from a lost
 * connection, which is all this column is for. `getRawSql()` is avoided
 * too, since it interpolates the same bindings by design.
 *
 * THE RECOVERY IS UNCHANGED: `raw_ingest_payloads` still holds every byte,
 * the log line still names the payload and run, and `ingest:replay`
 * re-runs everything once the parser is fixed.
 */
final class IngestErrorText
{
    /**
     * `ingest_runs.error` is text, so this cap is for whoever reads it rather
     * than for the column. Same figure the run writer has always used.
     */
    private const MAX_LENGTH = 4000;

    /**
     * A one-line description of a failure, safe to store and safe to log.
     */
    public static function describe(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return sprintf('%s: SQLSTATE[%s]', $e::class, self::sqlState($e));
        }

        // Everything else is our own exception with a message this codebase
        // wrote — MalformedDatapoint, UnknownUnitConversion, etc. name the
        // metric and shape, never a reading.
        return mb_substr($e::class.': '.$e->getMessage(), 0, self::MAX_LENGTH);
    }

    /**
     * The driver's five-character state code, read off the PREVIOUS
     * exception (the PDOException) where the driver put it —
     * QueryException copies it onto its own code, which is the fallback,
     * not the first choice.
     */
    private static function sqlState(QueryException $e): string
    {
        foreach ([$e->getPrevious()?->getCode(), $e->getCode()] as $code) {
            $code = trim((string) ($code ?? ''));

            if ($code !== '' && $code !== '0') {
                return $code;
            }
        }

        return 'unknown';
    }
}
