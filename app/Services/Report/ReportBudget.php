<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * Roughly how big the question is, checked before it is asked.
 *
 * WHY A GUESS IS BETTER THAN NOTHING HERE. ReportFocus's range ceilings are
 * the real defence, bounding rows before a single query runs. This is the
 * second one, because ceilings bound ROWS while the model is billed for
 * TOKENS, and the two only track each other while rows stay today's size. A
 * year of complete supplement logging, a fortnight photographed item by
 * item, a growing `training` field — none of those move the day count, all
 * of them move the document. Without this check the failure is expensive: a
 * 400 after the whole snapshot is assembled, or worse, an accepted request
 * paid for at input rates that comes back truncated for lack of room. A
 * report that refuses politely costs nothing.
 *
 * CHARS / 4 IS THE ESTIMATE, deliberately not `count_tokens` — that's
 * another network call, on the path of a job whose whole point is that the
 * network call is the expensive part, so a guard against a failing request
 * would itself be a request that can fail. The ratio doesn't need to be
 * exact: this is a fuse against a document grown by an order of magnitude,
 * not a meter, and the ceiling has enough headroom that a 30% miscount
 * changes nothing.
 *
 * The JSON measured is the same JSON the writer sends — same flags, same
 * pretty-printing — so the estimate is of the actual payload, not a tidier
 * one.
 */
final class ReportBudget
{
    /**
     * The flags AnthropicReportWriter encodes with, kept identical on
     * purpose: pretty-printing is a few hundred tokens of whitespace, and an
     * estimate off the compact form would under-count the document it's
     * guarding.
     */
    public const ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * A rough input-token count for one assembled snapshot.
     *
     * Returns null when the document can't be encoded at all — not a budget
     * problem, the writer reports that as its own with a better message.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function estimateTokens(array $snapshot): ?int
    {
        $json = json_encode($snapshot, self::ENCODE_FLAGS);

        if ($json === false) {
            return null;
        }

        return (int) ceil(strlen($json) / 4);
    }

    public static function ceiling(): int
    {
        return max(1, (int) config('health.report.max_input_tokens', 150_000));
    }

    /**
     * The sentence a refused report carries, or null when it fits.
     *
     * A SENTENCE RATHER THAN A BOOLEAN: the only useful thing to do with this
     * answer is put it on the row the user will read, and the number they
     * need to act on — the range was too wide — is knowable only here.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function refusal(array $snapshot, ReportFocus $focus): ?string
    {
        $estimate = self::estimateTokens($snapshot);

        if ($estimate === null || $estimate <= self::ceiling()) {
            return null;
        }

        $advice = $focus->isEverything()
            ? 'Try a shorter range, or focus the report on one or two areas.'
            : 'Try a shorter range.';

        return sprintf(
            'This range assembled into about %s tokens of facts, past the %s this app will send in one report. %s',
            number_format($estimate),
            number_format(self::ceiling()),
            $advice,
        );
    }
}
