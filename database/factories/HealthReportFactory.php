<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use App\Models\HealthReport;
use App\Enums\HealthReportKind;
use App\Enums\HealthReportStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A generated report. Default is FINISHED — the state almost every test
 * wants: a list, a rendered page, a provenance line. The three
 * unfinished states get named modifiers so a test that wants one says
 * so out loud.
 *
 * @extends Factory<HealthReport>
 */
final class HealthReportFactory extends Factory
{
    protected $model = HealthReport::class;

    public function definition(): array
    {
        $end = CarbonImmutable::now((string) config('health.timezone'))->startOfDay()->subDay();
        $start = $end->subDays(6);

        return [
            'range_start'     => $start->toDateString(),
            'range_end'       => $end->toDateString(),
            'kind'            => HealthReportKind::Manual,
            'status'          => HealthReportStatus::Ready,
            'idempotency_key' => 'manual:'.$start->toDateString().':'.$end->toDateString().':'.$this->faker->unique()->uuid(),
            'input_snapshot'  => ['schema_version' => 1, 'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()]],
            'output'          => self::document(),
            'model'           => 'claude-opus-5',
            'prompt_version'  => 'v2-report',
            'input_tokens'    => 14_200,
            'output_tokens'   => 3_100,
            'cost_usd'        => 0.148500,
            'latency_ms'      => 48_000,
            'generated_at'    => CarbonImmutable::now(),
        ];
    }

    /**
     * A canned answer in exactly the shape the report schema defines.
     * Serves v1 and v2 alike (PromptV2Report reuses it), so a shape
     * change breaks every version at once — and is shared by the
     * factory and the fake writer, so a schema change breaks both
     * rather than leaving one quietly testing a shape the app no
     * longer produces.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return [
            'headline' => 'A steady week, with two short nights doing most of the damage.',
            'summary'  => 'Seven days, six of them with sleep recorded and four with food logged. '
                .'Nothing in the week is alarming. The two shortest nights are the two lowest '
                .'stress scores, which is the clearest pattern here.',
            'energy_balance' => 'Only two days had both a complete food log and full watch coverage, '
                .'so the balance for this week cannot carry much weight.',
            'food_quality' => 'Four logged days, dominated by rice and chicken. Repetitive rather '
                .'than unbalanced, and too few days to say much about variety.',
            'micronutrients' => [
                [
                    'nutrient'            => 'Vitamine B12 (als cyanocobalamine)',
                    'supplement_exact'    => '500 µg per day, taken on 6 of 7 days.',
                    'food_estimate_range' => null,
                    'food_coverage_pct'   => null,
                    'combined_comment'    => 'This app does not estimate B12 from food, so the food '
                        .'contribution is unknown rather than zero.',
                ],
            ],
            'cardiovascular' => 'Resting heart rate sat one beat above the year median. HRV against '
                .'the same week last year could not be compared: there were too few readings then.',
            'sleep'           => 'Six nights recorded, median 7.1 hours, with two under six.',
            'stress_patterns' => 'Five days scored. The two lowest both followed nights under six hours.',
            'observations'    => [
                [
                    'observation' => 'Your two roughest days both landed the morning after a short night.',
                    'evidence'    => 'The two lowest scores each followed a night under six hours.',
                ],
            ],
            'suggestions' => [
                [
                    'suggestion' => 'Log dinner on a couple more days next week.',
                    'rationale'  => 'Only four of seven days had food logged, which is what stopped the '
                        .'energy section from saying anything.',
                ],
            ],
            'data_gaps' => [
                'Three days had no food logged at all.',
                'Only one weigh-in, so there is no weight trend for this week.',
            ],
        ];
    }

    /** Claimed, nothing sent, nothing paid for. */
    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status'         => HealthReportStatus::Pending,
            'input_snapshot' => null,
            'output'         => null,
            'model'          => null,
            'prompt_version' => null,
            'input_tokens'   => null,
            'output_tokens'  => null,
            'cost_usd'       => null,
            'latency_ms'     => null,
            'generated_at'   => null,
        ]);
    }

    /** In flight. What the page polls on. */
    public function generating(): self
    {
        return $this->pending()->state(fn (): array => [
            'status'         => HealthReportStatus::Generating,
            'model'          => 'claude-opus-5',
            'prompt_version' => 'v2-report',
        ]);
    }

    public function failed(string $error = 'The report came back empty.'): self
    {
        return $this->state(fn (): array => [
            'status'       => HealthReportStatus::Failed,
            'output'       => null,
            'generated_at' => null,
            'error'        => $error,
        ]);
    }

    public function weekly(): self
    {
        return $this->state(function (array $attributes): array {
            $start = (string) $attributes['range_start'];
            $end = (string) $attributes['range_end'];

            return [
                'kind'            => HealthReportKind::Weekly,
                'idempotency_key' => "weekly:{$start}:{$end}",
            ];
        });
    }

    /**
     * A specific window — takes dates rather than a ReportRange so a test
     * can pin a range illegal to construct (the far past, a length past
     * the ceiling) when that's the thing under test.
     */
    public function covering(string $start, string $end): self
    {
        return $this->state(fn (): array => [
            'range_start' => $start,
            'range_end'   => $end,
        ]);
    }
}
