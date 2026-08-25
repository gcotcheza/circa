<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;

/**
 * A row on its way through a `ChunkedUpsert`, already flattened to one value
 * per column.
 *
 * The ORDER is the contract, and the only thing this interface can't state:
 * the writers build anonymous `(?, ?, …)` tuples, so bindings that drift out
 * of step with the writer's `COLUMNS` put the right values in the wrong
 * columns with nothing raised. Each implementation says which writer it is
 * ordered against.
 */
interface UpsertRow
{
    /** @return list<string|int|bool|null> */
    public function bindings(CarbonImmutable $ingestedAt): array;
}
