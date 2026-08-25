<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * The client-generated key a request claims a paid analysis with;
 * `vision_requests` is unique on it. What that buys, and how it differs from
 * the other client-generated ids beside it, is said on each request.
 *
 * The shape is the part worth stating once: long enough for a uuid and
 * bounded by the column, but NOT `uuid` — the client may key a request
 * however it likes as long as it is stable across retries.
 */
trait ValidatesIdempotencyKey
{
    /** @return list<string> */
    protected function idempotencyKeyRules(): array
    {
        return ['required', 'string', 'min:8', 'max:128'];
    }
}
