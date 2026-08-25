<?php

declare(strict_types=1);

namespace Tests\Unit\Ingest;

use PDOException;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Illuminate\Database\QueryException;
use App\Services\Ingest\IngestErrorText;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * What a failed ingest may say about itself. The leak is in the CONSTRUCTOR:
 * `QueryException::__construct` calls `formatMessage()`
 * (`Str::replaceArray('?', $bindings, $sql)`), and for the metric upsert those
 * bindings are the readings — stored on `ingest_runs.error` and served to any
 * unauthenticated caller of /api/health.
 */
final class IngestErrorTextTest extends TestCase
{
    private function queryException(): QueryException
    {
        return new QueryException(
            'pgsql',
            'insert into health_metrics (metric, value, started_at) values (?, ?, ?)',
            ['heart_rate', '178.400000', '2026-08-07 10:00:00+02'],
            new PDOException('SQLSTATE[22003]: Numeric value out of range', 22003)
        );
    }

    /** The premise. Untrue, and the rest is unnecessary. */
    public function test_the_framework_message_really_does_carry_the_bindings(): void
    {
        $message = $this->queryException()->getMessage();

        self::assertStringContainsString('heart_rate', $message);
        self::assertStringContainsString('178.400000', $message);
    }

    public function test_a_database_error_is_reduced_to_its_class_and_sqlstate(): void
    {
        $described = IngestErrorText::describe($this->queryException());

        self::assertStringContainsString(QueryException::class, $described);
        self::assertStringContainsString('SQLSTATE[22003]', $described);

        // Not the value, metric, timestamp, or statement.
        self::assertStringNotContainsString('heart_rate', $described);
        self::assertStringNotContainsString('178.400000', $described);
        self::assertStringNotContainsString('2026-08-07', $described);
        self::assertStringNotContainsString('insert into', $described);
    }

    /** No state code, still readable. */
    public function test_a_database_error_without_a_state_code_still_describes_itself(): void
    {
        $described = IngestErrorText::describe(new QueryException(
            'pgsql',
            'select 1',
            [],
            new PDOException('gone')
        ));

        self::assertStringContainsString('SQLSTATE[unknown]', $described);
    }

    /** Our own sentences are kept: they name a metric and a shape, never a reading. */
    public function test_our_own_exceptions_keep_their_message(): void
    {
        $described = IngestErrorText::describe(
            MalformedDatapoint::outOfRange('step_count', 'qty')
        );

        self::assertStringContainsString(MalformedDatapoint::class, $described);
        self::assertStringContainsString('outside numeric(16,6)', $described);
    }

    public function test_a_long_message_is_capped(): void
    {
        $described = IngestErrorText::describe(new RuntimeException(str_repeat('x', 9000)));

        self::assertSame(4000, mb_strlen($described));
    }
}
