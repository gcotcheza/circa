<?php

declare(strict_types=1);

namespace App\Services\Ingest\Exceptions;

/**
 * A metric arrived in a unit we have no conversion for.
 *
 * Thrown rather than swallowed: silently writing a kJ number into a kcal
 * column stays invisible until a TDEE estimate comes out 4x wrong.
 * Recovery is "teach MetricCatalog the conversion, then `artisan
 * ingest:replay`" — the raw payload is already banked.
 *
 * Extends MalformedDatapoint, not bare RuntimeException: the latter once
 * let this escape PayloadParser's catch, so a renamed unit (HAE may rename
 * one on any release) failed the whole transaction and dropped every other
 * reading in the POST. Skipping just the datapoint costs one sample, not
 * the payload.
 */
final class UnknownUnitConversion extends MalformedDatapoint
{
    public static function between(string $from, string $to, ?string $metric = null): self
    {
        return new self(sprintf(
            'No conversion from [%s] to [%s]%s.',
            $from,
            $to,
            $metric === null ? '' : sprintf(' for metric [%s]', $metric)
        ));
    }
}
