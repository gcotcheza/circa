<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * What one report cost, in dollars, at the prices in force when it was
 * written.
 *
 * Stored rather than derived on read: deriving it would be one
 * multiplication that goes wrong the first time Anthropic changes a
 * price — every historical report would silently re-price against today's
 * table, and "what has this feature actually cost me?" would answer
 * differently every quarter. Same reasoning as `health_reports.model`: a
 * row must keep saying what happened, not what would happen now. Rates
 * live in config since they're a fact about a vendor's price list, not
 * this code, and the one thing here likely to change without a code change.
 */
final class ReportCost
{
    private const PER_MILLION = 1_000_000;

    /**
     * Null when either count is missing — an unknown token count is an
     * unknown cost, not a zero, which would be indistinguishable from a
     * genuinely free call (rejected before any tokens were read).
     */
    public static function usd(?int $inputTokens, ?int $outputTokens): ?float
    {
        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        /** @var array<string, mixed> $prices */
        $prices = config('health.report.price_per_mtok', []);

        $in = (float) ($prices['input'] ?? 0.0);
        $out = (float) ($prices['output'] ?? 0.0);

        $cost = ($inputTokens ?? 0) / self::PER_MILLION * $in
            + ($outputTokens ?? 0) / self::PER_MILLION * $out;

        // Six places, matching the column — a report is single-digit
        // cents, and rounding to two would flatten them to one number.
        return round($cost, 6);
    }
}
