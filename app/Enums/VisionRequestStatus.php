<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one call to the vision model.
 *
 * `vision_requests` is an audit table, keyed on the client-generated
 * Idempotency-Key: a double-tap must not double-pay, and an offline retry must
 * land on the same row. Because prompt_version and raw_response are kept, a
 * prompt change stays evaluable against history.
 */
enum VisionRequestStatus: string
{
    /** Row claimed by the idempotency key; request not yet sent. */
    case Pending = 'pending';

    case Sent = 'sent';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
