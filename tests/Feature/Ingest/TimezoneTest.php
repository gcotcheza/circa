<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\HealthMetric;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Offsets, day boundaries, and the 25-hour day.
 *
 * `local_date` is a Postgres STORED GENERATED column using
 * `AT TIME ZONE 'Europe/Amsterdam'`: one day boundary, decided by the database
 * for every writer. Exercised through the parser because that is where the
 * interesting failure lives — a datapoint parsed without its offset lands on a
 * plausible wrong day and nothing downstream can tell.
 */
final class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private int $session = 0;

    private function send(PayloadBuilder $builder): void
    {
        app(RawPayloadProcessor::class)->process(
            $builder->sessionId('SESSION-'.++$this->session)->store()
        );
    }

    public function test_summer_offsets_land_on_the_right_utc_instant(): void
    {
        $this->send(
            PayloadBuilder::make()
                ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 100)
        );

        $row = HealthMetric::query()->sole();

        self::assertSame('2026-08-07 08:00:00', $row->started_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07 09:00:00', $row->ended_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame(120, $row->device_utc_offset_minutes);
        self::assertSame('2026-08-07', $row->local_date->toDateString());
    }

    public function test_winter_offsets_land_on_the_right_utc_instant(): void
    {
        $this->send(
            PayloadBuilder::make()
                ->quantity('step_count', 'count', '2026-01-15 10:00:00 +0100', 100)
        );

        $row = HealthMetric::query()->sole();

        self::assertSame('2026-01-15 09:00:00', $row->started_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame(60, $row->device_utc_offset_minutes);
        self::assertSame('2026-01-15', $row->local_date->toDateString());
    }

    /**
     * The edge `local_date` exists for: 23:30 UTC in summer is already tomorrow
     * in Amsterdam, and a PHP-side date() on the UTC timestamp would quietly
     * move an hour of activity between days.
     */
    public function test_a_late_utc_evening_belongs_to_the_next_amsterdam_day(): void
    {
        $this->send(
            PayloadBuilder::make()
                ->quantity('step_count', 'count', '2026-08-07 01:30:00 +0200', 42)
        );

        $row = HealthMetric::query()->sole();

        self::assertSame('2026-08-06 23:30:00', $row->started_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07', $row->local_date->toDateString());
    }

    public function test_an_early_utc_morning_still_belongs_to_the_same_amsterdam_day(): void
    {
        $this->send(
            PayloadBuilder::make()
                ->quantity('step_count', 'count', '2026-08-07 00:30:00 +0200', 42)
        );

        self::assertSame(
            '2026-08-07',
            HealthMetric::query()->sole()->local_date->toDateString()
        );
    }

    /**
     * 2026-10-25 is 25 hours in Amsterdam: clocks go back at 03:00 CEST, so
     * 02:00 happens twice, at +0200 and +0100. Both must survive as distinct
     * buckets — a naive "parse local, assume app timezone" parser collapses
     * them into a unique-key collision that looks like a harmless duplicate.
     */
    public function test_a_dst_fall_back_day_produces_twenty_five_distinct_buckets(): void
    {
        $builder = PayloadBuilder::make();
        $data = [];

        // 00:00 and 01:00 CEST, then 02:00 CEST, then 02:00 CET, then 03:00..23:00 CET.
        foreach ([['00', '+0200'], ['01', '+0200'], ['02', '+0200'], ['02', '+0100']] as $i => [$hour, $offset]) {
            $data[] = [
                'date'   => "2026-10-25 {$hour}:00:00 {$offset}",
                'qty'    => 100 + $i,
                'source' => PayloadBuilder::WATCH,
            ];
        }

        for ($hour = 3; $hour <= 23; $hour++) {
            $data[] = [
                'date'   => sprintf('2026-10-25 %02d:00:00 +0100', $hour),
                'qty'    => 200 + $hour,
                'source' => PayloadBuilder::WATCH,
            ];
        }

        $this->send($builder->metric('step_count', 'count', $data));

        $rows = HealthMetric::query()->orderBy('started_at')->get();

        self::assertCount(25, $rows, 'The 25-hour day lost or merged a bucket.');
        self::assertSame(25, $rows->pluck('started_at')->unique()->count());

        // Every one of them is the same local day.
        self::assertSame(['2026-10-25'], $rows->pluck('local_date')
            ->map(fn ($d): string => $d->toDateString())->unique()->values()->all());

        // The two 02:00s are one UTC hour apart and carry different offsets.
        $repeated = $rows->filter(
            fn (HealthMetric $m): bool => in_array($m->started_at->utc()->format('H:i'), ['00:00', '01:00'], true)
        )->values();

        self::assertCount(2, $repeated);
        self::assertSame([120, 60], $repeated->pluck('device_utc_offset_minutes')->all());

        [$first, $second] = [$repeated->first(), $repeated->last()];
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->value, $second->value);
    }

    /** 23 hours: 02:00 CET never happens, but the buckets must still tile in UTC. */
    public function test_a_dst_spring_forward_day_tiles_without_gaps(): void
    {
        $data = [
            ['date' => '2026-03-29 01:00:00 +0100', 'qty' => 1, 'source' => PayloadBuilder::WATCH],
            ['date' => '2026-03-29 03:00:00 +0200', 'qty' => 2, 'source' => PayloadBuilder::WATCH],
        ];

        $this->send(PayloadBuilder::make()->metric('step_count', 'count', $data));

        $rows = HealthMetric::query()->orderBy('started_at')->get();

        self::assertCount(2, $rows);

        [$row0, $row1] = [$rows->first(), $rows->last()];
        self::assertNotNull($row0);
        self::assertNotNull($row1);

        self::assertSame('2026-03-29 00:00:00', $row0->started_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-29 01:00:00', $row0->ended_at->utc()->format('Y-m-d H:i:s'));
        // The next bucket starts exactly where the previous one ended.
        self::assertSame('2026-03-29 01:00:00', $row1->started_at->utc()->format('Y-m-d H:i:s'));
        self::assertSame(['2026-03-29'], $rows->pluck('local_date')
            ->map(fn ($d): string => $d->toDateString())->unique()->values()->all());
    }

    /** The offset is preserved so a bucket stays reconstructable later. */
    public function test_the_device_offset_is_kept_alongside_the_absolute_instant(): void
    {
        $this->send(
            PayloadBuilder::make()
                ->quantity('step_count', 'count', '2026-10-25 02:00:00 +0100', 5)
        );

        self::assertSame(60, HealthMetric::query()->sole()->device_utc_offset_minutes);
    }
}
