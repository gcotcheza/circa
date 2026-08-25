<?php

declare(strict_types=1);

namespace App\Services\Fitage;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * What a Fitage column means, and how to read a Fitage cell.
 *
 * The export format changed between the 2025 and 2026 windows — 18 columns
 * grew to 28, with the new ones (Fat mass, Muscle Mass Percentage, Protein
 * Mass, Body Water Mass, Bone Mass Percentage, Health Score, four "control"
 * columns) INTERLEAVED, not appended, so "BMI" is column D in one schema and
 * E in the other. A reader keyed on position would import Body fat as BMI
 * for half the user's history and never fail loudly, so mapping is by
 * HEADER TEXT: an unmapped header is ignored rather than guessed at, which
 * also makes the next schema change a no-op for columns that didn't move.
 * The ten columns we care about are spelled identically in both schemas, so
 * this map needs no per-version branch.
 *
 * "No reading" has two spellings: the older export writes "- -", the newer
 * one an empty cell. Both must import as NOTHING — a zero here would be a
 * 0% body fat sample sitting in the history forever, indistinguishable from
 * a real reading.
 */
final class FitageColumns
{
    /** The one column that is not a metric: the measurement instant. */
    public const TIMESTAMP = 'time of measurement';

    /** `DD/MM/YYYY HH:MM:SS`, on the phone's local clock. */
    public const TIMESTAMP_FORMAT = 'd/m/Y H:i:s';

    /**
     * Normalised header => [metric, canonical unit].
     *
     * Deliberately a SUBSET: excluded columns are the scale's own arithmetic,
     * not measurements — "Fat Control(kg)" is distance from an app-invented
     * target, "Standard weight(kg)" a height formula, "Health Score" a
     * marketing number that reads 0 on a real row, "Metabolic Age"
     * unfalsifiable, MAC address a device id we already have. Importing them
     * costs nothing to store and everything to explain later.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const METRICS = [
        // --- already in the catalog; HealthKit carries these too -------------
        'weight(kg)'               => ['weight_body_mass', 'kg'],
        'body fat(%)'              => ['body_fat_percentage', '%'],
        'bmi'                      => ['body_mass_index', 'count'],
        'fat-free body weight(kg)' => ['lean_body_mass', 'kg'],

        // --- new; the scale measured these and HealthKit never carried them --
        'muscle mass(kg)' => ['muscle_mass', 'kg'],
        'visceral fat'    => ['visceral_fat', 'count'],
        'body water(%)'   => ['body_water_percentage', '%'],
        'bone mass(kg)'   => ['bone_mass', 'kg'],
        'bmr(kcal)'       => ['basal_metabolic_rate', 'kcal'],
    ];

    /**
     * Cell texts that mean "no reading", case-insensitively.
     *
     * @var list<string>
     */
    private const ABSENT = ['', '-', '--', '- -', 'n/a', 'na', '--:--'];

    /**
     * Fold a header for lookup: trim, collapse inner whitespace, lowercase.
     *
     * "Body fat(%)" and "Body Fat (%)" are the same column — a future export
     * adding a space before the bracket shouldn't silently stop importing
     * body fat.
     */
    public static function normalise(string $header): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $header)) ?? $header;

        return mb_strtolower(trim($collapsed));
    }

    /**
     * The metric and unit this header maps to, or null if we ignore it.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function metricFor(string $header): ?array
    {
        return self::METRICS[self::normalise($header)] ?? null;
    }

    /**
     * The canonical unit an imported metric is stored in.
     *
     * Reads the same map the header lookup does, so a column's mapped unit
     * and its rows' written unit cannot drift apart.
     *
     * @return array<string, string> metric => unit
     */
    public static function units(): array
    {
        $units = [];

        foreach (self::METRICS as [$metric, $unit]) {
            $units[$metric] = $unit;
        }

        return $units;
    }

    /** True when the scale recorded nothing in this cell. */
    public static function isAbsent(string $raw): bool
    {
        $trimmed = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw));

        if (in_array($trimmed, self::ABSENT, true)) {
            return true;
        }

        // No digit at all means prose, not a measurement — "Normal" in Body
        // Type, a future "not measured" — cheaper than enumerating every
        // sentinel the app might invent.
        return preg_match('/\d/', $trimmed) !== 1;
    }

    /**
     * A numeric cell, or null when the text is not a plain decimal.
     *
     * Strict on purpose, and only called on a cell isAbsent() has already
     * cleared. A null here is a format change — thousands separator, comma
     * decimal, unit suffix — reported rather than coerced, since
     * (float) "1,234" is 1.0 and would be banked as a plausible-looking lie.
     */
    public static function number(string $raw): ?float
    {
        $trimmed = trim($raw);

        return preg_match('/^[+-]?\d+(?:\.\d+)?$/', $trimmed) === 1 ? (float) $trimmed : null;
    }

    /**
     * `DD/MM/YYYY HH:MM:SS` on the reporting timezone's wall clock.
     *
     * The export carries no offset — unlike HAE, which always does — so the
     * zone comes from configuration; safe here since this is a file exported
     * from a phone that lives in one country, not a stream that might arrive
     * from a plane.
     *
     * The round-trip check makes a DST gap loud: 29/03/2026 02:30:00 doesn't
     * exist in Amsterdam, and Carbon quietly returns 03:30 for it — a
     * fabricated timestamp. Re-formatting and comparing catches that and any
     * other silent normalisation, and the caller skips the row.
     */
    public static function timestamp(string $raw, string $timezone): ?CarbonImmutable
    {
        $trimmed = trim($raw);

        try {
            $parsed = CarbonImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $trimmed, $timezone);
        } catch (InvalidFormatException) {
            return null;
        }

        // Carbon returns null instead of throwing when strict mode is off.
        if ($parsed === null) {
            return null;
        }

        return $parsed->format(self::TIMESTAMP_FORMAT) === $trimmed ? $parsed : null;
    }
}
