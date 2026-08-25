<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Illuminate\Database\PostgresConnection;

/**
 * A connection that answers every `select()` with no rows and remembers what
 * it was asked.
 *
 * For assertions about the SQL a writer BUILDS rather than what the database
 * does with it. A real connection can't answer that: the statements here end
 * in `returning (xmax = 0)`, so running one tells you which rows survived,
 * not whether the text that decided it still says what it used to.
 *
 * The PDO is a closure that throws, so a query reaching the driver is a test
 * bug rather than a silent round trip. Decorating the live connection — what
 * FailingMetricConnection does — would be the wrong shape here: this fixture
 * exists precisely so no database is involved.
 */
final class RecordingConnection extends PostgresConnection
{
    /** @var list<array{sql: string, bindings: array<int, mixed>, useReadPdo: bool}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(
            static fn (): never => throw new RuntimeException('RecordingConnection must never reach a driver.'),
            'recording',
        );
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
        $this->calls[] = ['sql' => $query, 'bindings' => $bindings, 'useReadPdo' => $useReadPdo];

        return [];
    }
}
