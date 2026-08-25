<?php

declare(strict_types=1);

namespace App\Services\Food;

/**
 * How a barcode lookup ended.
 *
 * Three outcomes, not two — the UI does three different things:
 *
 *   Found        show the product card
 *   NotFound     offer manual entry with the barcode pre-noted — the scan
 *                worked, OFF simply has never heard of this package
 *   Unavailable  offer *retry* — OFF is down, rate-limited, or slow; the
 *                product may well exist, nothing about the scan was wrong
 *
 * Collapsing the last two into one error is the failure mode to avoid: it
 * teaches the user to hand-type nutrition for a product the cache would have
 * had thirty seconds later.
 */
enum LookupStatus: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
}
