<?php

declare(strict_types=1);

namespace App\Services\Fitage;

use App\Services\Fitage\Exceptions\UnreadableExport;

/**
 * One .xlsx file, read into measurements.
 *
 * Writes nothing and touches no database. Anything wrong with a single row
 * — an unparseable date, a cell that stopped being a plain number — is
 * recorded on `anomalies` and the row (or cell) skipped, in the spirit of
 * PayloadParser: one odd row must not stop the other forty-seven from being
 * recovered, but must not disappear quietly either.
 *
 * A problem with the FILE, by contrast, throws. See UnreadableExport.
 */
final readonly class FitageExport
{
    /**
     * @param  list<Measurement>  $measurements
     * @param  list<string>  $anomalies  human-readable, one per skipped row or cell
     */
    private function __construct(
        public string $path,
        public array $measurements,
        public int $dataRows,
        public array $anomalies,
    ) {}

    /**
     * @throws UnreadableExport
     */
    public static function read(string $path, string $timezone): self
    {
        $rows = XlsxReader::rows($path);

        $header = array_shift($rows);

        if ($header === null) {
            throw UnreadableExport::noHeaderRow($path);
        }

        [$timestampColumn, $metricColumns] = self::columns($header, $path);

        $measurements = [];
        $anomalies = [];
        $dataRows = 0;

        foreach ($rows as $number => $row) {
            // +2: $number is 0-based over rows after the header, and
            // spreadsheet rows are 1-based — so the first data row is row 2,
            // matching what the user sees in the file.
            $line = $number + 2;

            // A trailing all-empty row is padding, not a lost measurement.
            if (self::isBlank($row)) {
                continue;
            }

            $dataRows++;

            $measuredAt = FitageColumns::timestamp($row[$timestampColumn] ?? '', $timezone);

            if ($measuredAt === null) {
                $anomalies[] = sprintf(
                    'row %d: skipped — "%s" is not a DD/MM/YYYY HH:MM:SS instant in %s',
                    $line,
                    trim($row[$timestampColumn] ?? ''),
                    $timezone,
                );

                continue;
            }

            $values = [];

            foreach ($metricColumns as $column => [$metric, $unit]) {
                $raw = $row[$column] ?? '';

                if (FitageColumns::isAbsent($raw)) {
                    continue;
                }

                $value = FitageColumns::number($raw);

                if ($value === null) {
                    $anomalies[] = sprintf(
                        'row %d, column %s (%s): "%s" is not a plain decimal — cell skipped',
                        $line,
                        $column,
                        $metric,
                        trim($raw),
                    );

                    continue;
                }

                $values[$metric] = $value;
            }

            // A timestamp with no usable number is a weigh-in we can't
            // represent — counted, so "13 rows, 12 measurements" stays
            // answerable.
            if ($values === []) {
                $anomalies[] = sprintf('row %d: skipped — no readable measurement in any mapped column', $line);

                continue;
            }

            $measurements[] = new Measurement($measuredAt, $values);
        }

        return new self($path, $measurements, $dataRows, $anomalies);
    }

    /**
     * Header row => the timestamp column letter and the metric column letters.
     *
     * @param  array<string, string>  $header
     * @return array{0: string, 1: array<string, array{0: string, 1: string}>}
     *
     * @throws UnreadableExport
     */
    private static function columns(array $header, string $path): array
    {
        $timestamp = null;
        $metrics = [];

        foreach ($header as $column => $name) {
            if (FitageColumns::normalise($name) === FitageColumns::TIMESTAMP) {
                $timestamp = $column;

                continue;
            }

            $mapping = FitageColumns::metricFor($name);

            if ($mapping !== null) {
                $metrics[$column] = $mapping;
            }
        }

        if ($timestamp === null) {
            throw UnreadableExport::noHeaderRow($path);
        }

        return [$timestamp, $metrics];
    }

    /** @param array<string, string> $row */
    private static function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Earliest measurement instant, or null for an empty export. */
    public function firstAt(): ?string
    {
        return $this->measurements === []
            ? null
            : min(array_map(static fn (Measurement $m): string => $m->measuredAt->toDateTimeString(), $this->measurements));
    }

    public function lastAt(): ?string
    {
        return $this->measurements === []
            ? null
            : max(array_map(static fn (Measurement $m): string => $m->measuredAt->toDateTimeString(), $this->measurements));
    }

    /**
     * The local dates this export touches.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = array_map(static fn (Measurement $m): string => $m->localDate(), $this->measurements);

        sort($dates);

        return array_values(array_unique($dates));
    }
}
