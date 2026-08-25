<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one processed POST body.
 *
 * `ingest_runs` is an append-only log, never a rejection gate — HAE's
 * `session-id` semantics are ambiguous (batched requests may share one),
 * so a duplicate key means "already logged," not "reject." Row-level
 * idempotent upsert into `health_metrics` is the sole dedup mechanism.
 */
enum IngestRunStatus: string
{
    /** Persisted, queued, not yet parsed. */
    case Pending = 'pending';

    case Processing = 'processing';

    case Completed = 'completed';

    case Failed = 'failed';

    /**
     * Parsed fine and produced zero rows. Distinct from Completed on purpose:
     * this is the server-side gap signal the spec asks to alert on, and it beats
     * trusting the phone to report that it sent nothing.
     */
    case Empty = 'empty';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
