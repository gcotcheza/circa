<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * One `data.workouts[]` entry -> a WorkoutRow. Writes nothing.
 *
 * `id` (idempotency key) and `start`/`end` (needed to place the row on a
 * day) are load-bearing; their absence skips the record. Everything else
 * is optional, including `name` — losing a session over a missing string
 * would be the parser deciding tidiness outranks the record. Unlabelled
 * sessions are stored as "Workout".
 *
 * A malformed record is SKIPPED, counted and summarised onto
 * `ingest_runs`, like a malformed datapoint: one bad workout must not cost
 * the other one in the same POST. A unit with no conversion is skipped the
 * same way — UnknownUnitConversion, out of MetricCatalog — because energy
 * in anything but kJ means the export format moved, and writing it anyway
 * would put a kJ figure in a kcal column, silently and permanently.
 *
 * UnknownUnitConversion used to escape the per-record catch and fail the
 * whole payload, dropping ~250 unrelated readings on every export until
 * noticed. It's a MalformedDatapoint now: same refusal, one record.
 * Recovery is unchanged — teach MetricCatalog the conversion and replay.
 */
final class WorkoutReader
{
    /** The label an unnamed session gets. */
    private const UNNAMED = 'Workout';

    /** `workouts.apple_uuid` is varchar(64) — an identity, so never folded. */
    private const MAX_UUID = 64;

    /** `workouts.type` is varchar(64) — a label, so folded rather than lost. */
    private const MAX_TYPE = 64;

    /**
     * Keys that become columns, and are therefore not repeated into `extras`.
     *
     * `heartRate` and `totalEnergy` are here too though neither is a column:
     * both are folded into `extras` in a derived form (`min_hr`, `total_kcal`)
     * because their raw shapes either duplicate a column (heartRate.avg IS
     * avgHeartRate on every captured workout) or carry the kJ this app
     * doesn't store.
     *
     * @var list<string>
     */
    private const NOT_IN_EXTRAS = [
        'id',
        'name',
        'start',
        'end',
        'duration',
        'distance',
        'activeEnergyBurned',
        'avgHeartRate',
        'maxHeartRate',
        'elevationUp',
        'isIndoor',
        'intensity',
        'heartRate',
        'totalEnergy',
    ];

    /**
     * @param  array<string, mixed>  $workout  one entry of `data.workouts[]`
     *
     * @throws MalformedDatapoint
     */
    public function read(array $workout): WorkoutRow
    {
        $uuid = $workout['id'] ?? null;

        if (! is_string($uuid) || trim($uuid) === '') {
            throw MalformedDatapoint::workoutWithoutId($workout);
        }

        $uuid = trim($uuid);

        // `workouts.apple_uuid` is varchar(64) — 36 observed, room to grow.
        // Over-long is SKIPPED, not folded like the name below: this is the
        // idempotency key, so truncating two distinct sessions to the same
        // 64 characters would collapse them into one row and lose a
        // workout silently — the same fault workoutWithoutId() describes.
        if (mb_strlen($uuid) > self::MAX_UUID) {
            throw MalformedDatapoint::workoutWithoutId($workout);
        }

        // `workouts.type` is varchar(64), a label not a key — folded, since
        // losing an hour of training over the spelling of "Outdoor Run"
        // would be tidiness outranking the record.
        $type = is_string($workout['name'] ?? null) && trim((string) $workout['name']) !== ''
            ? mb_substr(trim((string) $workout['name']), 0, self::MAX_TYPE)
            : self::UNNAMED;

        $startedAt = $this->timestamp($workout, 'start', $type);
        $endedAt = $this->timestamp($workout, 'end', $type);

        /*
         * The export's own `duration`, in seconds, not `end - start`: the
         * two agree to within a second on all 100 captured workouts (the
         * 3-hour run: 11 377.54 s vs an 11 377 s span), and where they
         * disagree the Watch's figure knows about a paused timer. The span
         * is only the fallback for an export that omits `duration`.
         */
        $duration = $this->optionalNumber($workout['duration'] ?? null, 'duration', $type)
            ?? (float) ($endedAt->getTimestamp() - $startedAt->getTimestamp());

        $heartRate = is_array($workout['heartRate'] ?? null) ? $workout['heartRate'] : [];

        return new WorkoutRow(
            appleUuid: $uuid,
            type: $type,
            startedAt: $startedAt->utc(),
            endedAt: $endedAt->utc(),
            durationS: $this->decimal($duration, 3),
            distanceKm: $this->decimalOrNull($this->measure($workout, 'distance', 'km', $type), 4),
            // kJ -> kcal, through the metrics catalog. See the class docblock.
            activeKcal: $this->decimalOrNull(
                $this->energyKcal($workout, 'activeEnergyBurned', $type),
                3
            ),
            avgHr: $this->intOrNull($this->measure($workout, 'avgHeartRate', null, $type)),
            maxHr: $this->intOrNull($this->measure($workout, 'maxHeartRate', null, $type)),
            stepCount: $this->intOrNull($this->sumSeries($workout['stepCount'] ?? null)),
            elevationUpM: $this->decimalOrNull($this->measure($workout, 'elevationUp', 'm', $type), 2),
            isIndoor: is_bool($workout['isIndoor'] ?? null) ? $workout['isIndoor'] : null,
            intensity: $this->decimalOrNull($this->measure($workout, 'intensity', null, $type), 4),
            isImplausible: $this->isImplausible($duration),
            deviceUtcOffsetMinutes: HaeDate::offsetMinutes($startedAt),
            extras: $this->extras($workout, $heartRate, $type),
        );
    }

    /**
     * A session so long it is an artifact rather than a session.
     *
     * Two ways in: past the configured ceiling (the four-day hike whose timer
     * was never stopped), or a non-positive duration. See config/health.php
     * 'workouts'.
     */
    private function isImplausible(float $durationSeconds): bool
    {
        $maxHours = (float) config('health.workouts.max_plausible_hours', 12);

        return $durationSeconds <= 0.0 || $durationSeconds > $maxHours * 3600;
    }

    /**
     * @param  array<string, mixed>  $workout
     *
     * @throws MalformedDatapoint
     */
    private function timestamp(array $workout, string $key, string $type): CarbonImmutable
    {
        $raw = $workout[$key] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            throw MalformedDatapoint::workoutWithoutTimes($type, $key);
        }

        return HaeDate::parse($raw, 'workout:'.$type);
    }

    /**
     * A `{qty, units}` field, converted into `$expected` if one is named.
     *
     * A null `$expected` means "take the number as delivered": heart rate
     * arrives as `bpm` and intensity as `kcal/hr·kg`, neither of which this
     * app converts, and both already the unit the column is documented in.
     *
     * @param  array<string, mixed>  $workout
     */
    private function measure(array $workout, string $key, ?string $expected, string $type): ?float
    {
        $measure = $this->qtyAndUnits($workout, $key, $type);

        if ($measure === null) {
            return null;
        }

        [$qty, $units] = $measure;

        if ($expected === null) {
            return $qty;
        }

        // Same unit in and out is a no-op; anything else converts or throws,
        // and throwing is the point. See the class docblock.
        return MetricCatalog::convert($qty, $units ?? $expected, $expected, 'workout:'.$type.'.'.$key);
    }

    /**
     * The `{qty, units}` pair a workout field is delivered as, or null when
     * the field is absent, malformed or carries no number.
     *
     * `units` stays null rather than defaulting here: what an unlabelled
     * quantity should be read as is the caller's question — kJ for energy,
     * the expected unit for everything else — and a default chosen in this
     * method would be one nobody could see at the point it mattered.
     *
     * @param  array<string, mixed>  $workout
     * @return array{0: float, 1: string|null}|null
     */
    private function qtyAndUnits(array $workout, string $key, string $type): ?array
    {
        $field = $workout[$key] ?? null;

        if (! is_array($field)) {
            return null;
        }

        $qty = $this->optionalNumber($field['qty'] ?? null, $key, $type);

        if ($qty === null) {
            return null;
        }

        $units = $field['units'] ?? null;

        return [$qty, is_string($units) ? $units : null];
    }

    /**
     * A `{qty, units}` energy field in kcal.
     *
     * Routed through `MetricCatalog::toCanonical('active_energy', ...)`
     * rather than a local 1/4.184 so there's exactly ONE statement in this
     * codebase of what an energy unit is worth — a workout's active energy IS
     * active energy, the same quantity the metrics pipeline already converts.
     *
     * @param  array<string, mixed>  $workout
     */
    private function energyKcal(array $workout, string $key, string $type): ?float
    {
        $measure = $this->qtyAndUnits($workout, $key, $type);

        if ($measure === null) {
            return null;
        }

        [$qty, $units] = $measure;

        [$kcal] = MetricCatalog::toCanonical('active_energy', $qty, $units ?? 'kJ');

        return $kcal;
    }

    /**
     * The total of a per-sample series.
     *
     * The ONLY series this parser reduces, and only because steps reduce: a
     * workout's `stepCount` is one entry per minute and their sum is the
     * workout's step count. Everything else — heart-rate windows, GPS fixes,
     * per-minute energy — is a shape whose total means nothing, and stays in
     * the raw payload. See the migration.
     */
    private function sumSeries(mixed $series): ?float
    {
        if (! is_array($series) || $series === []) {
            return null;
        }

        $total = 0.0;
        $seen = false;

        foreach ($series as $sample) {
            if (! is_array($sample) || ! is_numeric($sample['qty'] ?? null)) {
                continue;
            }

            $total += (float) $sample['qty'];
            $seen = true;
        }

        return $seen ? $total : null;
    }

    /**
     * The long tail, verbatim.
     *
     * Pass-through rather than an allow-list, so a scalar HAE adds next
     * year is banked without a migration.
     *
     * Sample series are excluded BY SHAPE, not by name: every series HAE
     * sends is a JSON list (`route`, `heartRateData`, `activeEnergy`,
     * `basalEnergy`, `stepCount`, `heartRateRecovery`, the two distance
     * breakdowns) and every scalar is a plain value or a `{qty, units}`
     * object, so dropping lists keeps 11 383 GPS fixes out of this column
     * permanently.
     *
     * @param  array<string, mixed>  $workout
     * @param  array<string, mixed>  $heartRate
     * @return array<string, mixed>
     */
    private function extras(array $workout, array $heartRate, string $type): array
    {
        $extras = [];

        foreach ($workout as $key => $value) {
            if (in_array($key, self::NOT_IN_EXTRAS, true)) {
                continue;
            }

            // A JSON list is a sample series. An empty object decodes to an
            // empty array, which is a list too — `metadata` is empty on every
            // captured workout, so dropping it is right as well as free.
            if (is_array($value) && array_is_list($value)) {
                continue;
            }

            $extras[Str::snake($key)] = $value;
        }

        // The one field of `heartRate` not already a column. Apple reports
        // it and nothing else in this app does.
        $min = $this->optionalNumber(
            is_array($heartRate['min'] ?? null) ? ($heartRate['min']['qty'] ?? null) : null,
            'heartRate.min',
            $type,
        );

        if ($min !== null) {
            $extras['min_hr'] = (int) round($min);
        }

        // Active + basal for the session, converted like everything else
        // here. Kept because it's the figure the Watch shows on the summary
        // screen, and quoting the active figure at a user looking at the
        // total one would be right and unrecognisable.
        $total = $this->energyKcal($workout, 'totalEnergy', $type);

        if ($total !== null) {
            $extras['total_kcal'] = round($total, 3);
        }

        ksort($extras);

        return $extras;
    }

    /**
     * @throws MalformedDatapoint
     */
    private function optionalNumber(mixed $value, string $field, string $type): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw MalformedDatapoint::nonNumeric('workout:'.$type, $field, $value);
        }

        return (float) $value;
    }

    /** numeric(n,$scale), rendered locale-independently. */
    private function decimal(float $value, int $scale): string
    {
        return sprintf('%.'.$scale.'F', $value);
    }

    private function decimalOrNull(?float $value, int $scale): ?string
    {
        return $value === null ? null : $this->decimal($value, $scale);
    }

    private function intOrNull(?float $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }
}
