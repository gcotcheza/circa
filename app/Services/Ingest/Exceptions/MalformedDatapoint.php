<?php

declare(strict_types=1);

namespace App\Services\Ingest\Exceptions;

use RuntimeException;

/**
 * One datapoint could not be turned into a row.
 *
 * Caught per datapoint, never per payload: a single unparseable sample must
 * not cost the other 250 in the same POST, nor stop the phone's next
 * hourly export from landing. The parser counts and logs these, recording
 * a summary on the `ingest_runs` row — a run that skipped everything ends
 * with zero rows and status `empty`, the gap signal the spec already asks
 * to alert on. Not `final`, deliberately: UnknownUnitConversion extends
 * this, since "the unit moved under us" is a fact about ONE datapoint and
 * must reach the same per-datapoint handler — it used to extend
 * RuntimeException directly, so one unrecognised `units` string threw
 * clear of the loop and cost the other ~250 readings in the same POST.
 */
class MalformedDatapoint extends RuntimeException
{
    public static function unparseableDate(string $metric, string $raw): self
    {
        return new self(sprintf(
            'Metric [%s]: date [%s] is not "Y-m-d H:i:s O" and carries no usable UTC offset.',
            $metric,
            $raw
        ));
    }

    public static function missingDate(string $metric): self
    {
        return new self(sprintf('Metric [%s]: datapoint has no `date`.', $metric));
    }

    /**
     * A number the `health_metrics.value` column cannot hold.
     *
     * The value itself is NOT in the message: this text goes to
     * `ingest_runs.error` and the log, and a reading is the one thing
     * neither should carry — the magnitude is the fault, and the raw
     * payload is what it's for.
     */
    public static function outOfRange(string $metric, string $field): self
    {
        return new self(sprintf(
            'Metric [%s]: field [%s] is non-finite or outside numeric(16,6).',
            $metric,
            $field
        ));
    }

    public static function nonNumeric(string $metric, string $field, mixed $value): self
    {
        return new self(sprintf(
            'Metric [%s]: field [%s] is not numeric (%s).',
            $metric,
            $field,
            get_debug_type($value)
        ));
    }

    /**
     * A workout with no HealthKit UUID. Skipped rather than given a
     * synthesised identity: the uuid IS the idempotency key, and a row
     * keyed on anything else would insert a fresh copy of the same session
     * on every re-export of the same history.
     *
     * @param  array<int|string, mixed>  $workout
     */
    public static function workoutWithoutId(array $workout): self
    {
        return new self(sprintf(
            'Workout [%s]: no `id`, so it has no stable identity. Keys [%s].',
            is_string($workout['name'] ?? null) ? $workout['name'] : 'unnamed',
            implode(', ', array_map(strval(...), array_keys($workout)))
        ));
    }

    public static function workoutWithoutTimes(string $type, string $field): self
    {
        return new self(sprintf(
            'Workout [%s]: `%s` is missing or is not a date string.',
            $type,
            $field
        ));
    }

    /** @param  array<int|string, mixed>  $datapoint */
    public static function unrecognisedShape(string $metric, array $datapoint): self
    {
        return new self(sprintf(
            'Metric [%s]: unrecognised datapoint shape, keys [%s].',
            $metric,
            implode(', ', array_map(strval(...), array_keys($datapoint)))
        ));
    }
}
