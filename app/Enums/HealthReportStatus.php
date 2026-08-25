<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one generated health report.
 *
 * Deliberately the same four-state shape as VisionRequestStatus — same
 * problem: a row claimed by an idempotency key before anything is
 * sent, a paid call in flight, two terminal states. Named differently
 * (`draft`/`ready` rather than `pending`/`succeeded`) because a user
 * READS these rows: "ready" is a word about a document, "succeeded"
 * is a word about a request.
 *
 * The claim is what makes an at-least-once queue safe: a job finding
 * its row in any state but `pending` has already been delivered once
 * and returns.
 */
enum HealthReportStatus: string
{
    /** Row claimed by the idempotency key; nothing sent, nothing paid for. */
    case Pending = 'pending';

    /** The call is in flight. The UI polls on exactly this. */
    case Generating = 'generating';

    case Ready = 'ready';

    case Failed = 'failed';

    /**
     * Is this report still moving?
     *
     * The UI polls while true and stops when false, and the "one at a
     * time" guard on the generate form asks the same question of the
     * newest row, so the two can't drift apart by one state.
     */
    public function isPending(): bool
    {
        return $this === self::Pending || $this === self::Generating;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
