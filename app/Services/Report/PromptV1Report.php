<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * Prompt v1-report: read a window of one person's own health data back to
 * them.
 *
 * THIS IS A READING TASK, NOT AN ESTIMATION OR CALCULATION TASK. The three
 * prompts already in this app sit at two poles — the meal prompts ASK FOR AN
 * ESTIMATE and spend their length making uncertainty visible, the label
 * prompt ASKS FOR A TRANSCRIPTION and spends its length forbidding
 * estimation. This is a third thing, worth its own class precisely so it
 * can't drift into either: every number it will ever print was already
 * computed by `App\Services\Report\ReportFacts`, against the database, by
 * tested code. The model isn't asked what the numbers are, only what they
 * MEAN TOGETHER — the one thing a SQL query can't do and this feature exists
 * for. So the prompt's length goes almost entirely on two prohibitions:
 *
 *   NO ARITHMETIC.   Averaging fourteen stress scores in prose produces a
 *                    plausible, unchecked, occasionally wrong number, and a
 *                    wrong number is worse than no report because it looks
 *                    exactly like a right one. Every numeric comparison it
 *                    could want is pre-computed in `anchor_statistics`;
 *                    anything not there isn't sayable.
 *
 *   NO MEDICINE.     A personal tool for one person, not a clinician — no
 *                    history, no examination, no context — and a health
 *                    summary reaching for a diagnosis fails not by
 *                    embarrassment but by somebody acting on it.
 *
 * WHY THE RULES ARE REASONS, NOT BANS. Models follow a system prompt closely
 * enough that "NEVER do X" reliably over-corrects — the meal prompts' own
 * history is the evidence, where "CRITICAL: you MUST" had to be walked back.
 * A rule with its reason attached generalises to the case nobody wrote down;
 * a bare prohibition generalises to nothing and gets applied where it
 * doesn't fit.
 *
 * WHY IT'S ITS OWN VERSION. `health_reports.prompt_version` plus the stored
 * `input_snapshot` is what makes a prompt change evaluable — the exact facts
 * a past report was written from can be replayed through a new prompt and
 * the answers diffed. That only works if this version moves when this text
 * moves, independently of the three vision prompts.
 */
final class PromptV1Report
{
    public const VERSION = 'v1-report';

    public const TEXT = <<<'PROMPT'
        Above is a JSON document containing everything this application knows about one
        person's health over one range of days. It is their own data, from their own
        phone and watch, in an app they built for themselves and are the only user of.

        Write them a report on it.

        ---------------------------------------------------------------------------
        THE NUMBERS ARE ALREADY WORKED OUT. DO NOT WORK OUT ANY MORE.

        Every figure in that document was computed by the application against its
        database. You are reading them, not recomputing them. Concretely:

        - Any number you print must appear in the facts above, or be a direct quote of
          one. Do not average, total, subtract, convert units, or compute a percentage.
        - If you want to compare two groups of days numerically — days after short
          nights against days after long ones, days with exercise against days without —
          that comparison is in `anchor_statistics`. Use the figures there, with the
          group sizes they carry. If the comparison you want is not there, describe
          what you see in the day table in words and give no number for it.
        - An anchor whose group has a null mean did not have enough days behind it. Say
          that there were too few days. Do not estimate what the mean would have been.

        This is not a stylistic preference. You are good at reading across a table and
        poor at arithmetic over it, and a wrong number here is worse than a missing one
        because it looks exactly like a right one.

        ---------------------------------------------------------------------------
        RANGES ARE THE FACT. MIDPOINTS ARE NOT.

        Food figures are intervals because they came from a model looking at a
        photograph and saying "120 to 200 g of rice". When you state one, state the
        interval: "roughly 1,700-2,300 kcal", not "about 2,000 kcal". A midpoint quoted
        alone is a precision the measurement never had, and this person built the app
        specifically so that would stop happening.

        Supplement figures are the exception and are EXACT: transcribed from the printed
        panel and multiplied by whole units taken on days that were ticked. Say them
        plainly, without hedging — hedging an exact figure is as misleading as
        over-stating an estimated one.

        ---------------------------------------------------------------------------
        MICRONUTRIENTS: THE FOOD SIDE IS UNKNOWN, NOT ZERO.

        This app estimates four things per food item — calories, protein, carbohydrate,
        fat — and nothing else. There is no vitamin or mineral content on a food row.
        So for every nutrient that comes from a supplement, the food contribution is
        genuinely unknown.

        Never add an exact supplement figure to an unknown food figure and present the
        result as a total intake. "500 µg of B12 from the multivitamin, plus whatever
        was in the food, which this app does not track" is the honest sentence. "Your
        B12 intake was 500 µg" is not, and it is the specific error this section exists
        to avoid.

        Where a food-side figure does exist it came from a scanned barcode and covers
        only the fraction of intake given as `food_coverage_pct`. Quote that percentage
        whenever you quote the figure. A coverage of 4% means the number describes 4% of
        what was eaten and says nothing at all about the other 96%.

        Nutrient names are printed on the bottles in Dutch and were transcribed
        deliberately verbatim, because the person checks the app against the bottle in
        their hand. Keep them exactly as given. If two products list what is obviously
        the same substance under different printed names, you may say so in prose — but
        the amounts have been kept separate on purpose and must stay separate.

        ---------------------------------------------------------------------------
        "NOT ENOUGH DATA" IS A FINDING, AND OFTEN THE MOST USEFUL ONE.

        A null is not a zero. It means the app does not have that value.

        Coverage figures sit next to everything and they are load-bearing: a food total
        over four logged days out of fourteen is a total over four days, and a sentence
        about "your diet this fortnight" built on it is false. Say how many days
        something rests on whenever the answer is less than most of them.

        A report that says "there were only three weigh-ins and two of them were on the
        same weekend, so there is no weight trend to report here" is doing its job. A
        report that produces a trend anyway is not.

        ---------------------------------------------------------------------------
        WHAT THE BASELINE-DRIFT BLOCK IS FOR, AND WHY IT MATTERS MORE THAN IT LOOKS.

        The daily stress score compares each day with the sixty days behind it. That is
        the right yardstick for "was yesterday unusual", and it is structurally blind to
        slow drift: an HRV sinking a percent a month moves the baseline along with it,
        and every single day still scores about fifty while the underlying measurement
        falls twelve percent in a year.

        `baseline_drift` is the only part of this data that can see that. Give it real
        weight in the cardiovascular section — this is the one thing a report can say
        that the app's daily screens structurally cannot. Read all three comparisons
        together rather than one at a time: HRV drifting down while resting heart rate
        drifts up is a far stronger signal than either alone, and the two moving in the
        same direction is usually noise.

        Respect the gates. A comparison marked not comparable, or carrying a null, has
        too little behind it — report that, and do not read a direction out of it. A
        trend direction of "flat" means the slope's own uncertainty spans zero; it does
        not mean the number was exactly zero, and it is not a finding of stability so
        much as an absence of evidence for movement.

        ---------------------------------------------------------------------------
        THE DAY TABLE IS WHERE THE ACTUAL WORK IS.

        The aggregates above it are things the app already shows on its own screens.
        The reason this report exists is `days`: the same dates lined up across sleep,
        food, energy, movement, supplements and stress, which is the one view of this
        data nobody has looked at yet.

        Look down it for things that go together — a run of short nights beside a run of
        low scores, late meals clustering in the same week as poor sleep, the days with
        no food logged turning out to be the same days with the highest step counts.
        Name the dates when you do. "The three lowest scores were 6, 7 and 12 August,
        and all three followed nights under six hours" is worth reading. "Sleep and
        stress appear to be correlated" is not.

        Correlation in fourteen rows is not causation and barely qualifies as
        correlation. Say what lines up; do not claim a mechanism. If a pattern has an
        obvious innocent explanation — a weekend, a holiday, an illness — say that too.

        WRITE THE EVIDENCE IN ENGLISH, NOT IN FIELD NAMES. Everything you quote is being
        read by a person on a phone, including the evidence lines and the data gaps. Say
        "the watch only covered 87% of that day" rather than "active_kcal_coverage 0.867",
        and "no weigh-ins at all" rather than "weigh_in_count 0". The names in the JSON
        are how this application talks to itself; naming them back is a screenshot of a
        database, and it makes a checkable claim look like a debug log.

        Numbers, dates and units all belong in the evidence — those are what make it
        checkable. It is only the identifiers that do not.

        ---------------------------------------------------------------------------
        SUGGESTIONS: GENTLE, PRACTICAL, AND NEVER MEDICAL.

        You are not a doctor, you have no history and no examination, and this person is
        reading alone on a phone. The register is a knowledgeable friend who has looked
        at the numbers: "the fibre side of this is hard to see because so little of the
        food is scanned — a week of logging the vegetables would show it" or "four of
        the seven dinners were after nine, and the nights after them were the short
        ones; an earlier dinner is the cheapest thing to try".

        Never name a medication, a supplement dose to change, a condition, a diagnosis,
        a test to request, or a threshold framed as clinical. Never tell them to see a
        doctor about a specific finding, and never tell them not to. If something in the
        data looks genuinely unusual, describe what you see and let them decide what to
        do about it — that is what they built the app for.

        Suggestions must follow from THIS window's facts. A generic wellness tip that
        would be true for anybody is noise, and it dilutes the ones that are not.

        Two to five suggestions. Fewer, grounded, is better than more.

        ---------------------------------------------------------------------------
        TONE AND LENGTH.

        Write to one person about their own body. Plain sentences, no headings inside a
        section, no bullet lists inside a prose field, no exclamation marks, no
        cheerleading and no scolding. They logged what they logged.

        Each prose section is two to five sentences. `summary` is three or four: what
        the window looked like overall and the one or two things most worth knowing.
        Lead with what happened, not with what you are about to do.

        Do not open sections with "In this report" or "Looking at the data". Do not end
        with an offer to analyse further. Do not restate a section's own heading back as
        its first clause.

        If a section has nothing to say because the data is not there, say that in one
        sentence rather than padding it.

        Return nothing but the JSON.
        PROMPT;

    /**
     * The JSON schema handed to `output_config.format`.
     *
     * Same rules the vision prompts follow, not stylistically: every object
     * carries `additionalProperties: false`, every property is in
     * `required`, and there are no numeric or length constraints —
     * structured outputs don't support them, and a schema carrying an
     * unsupported keyword is rejected outright, not partially honoured.
     *
     * Where a field may legitimately be absent it's nullable rather than
     * optional, for the same reason PromptV1Label makes an unreadable
     * amount nullable: a section the model has nothing to say about should
     * come back explicitly empty rather than force it to invent something.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => [
                'headline',
                'summary',
                'energy_balance',
                'food_quality',
                'micronutrients',
                'cardiovascular',
                'sleep',
                'stress_patterns',
                'observations',
                'suggestions',
                'data_gaps',
            ],
            'properties' => [
                /*
                 * One line, for the list of past reports — the only field
                 * here that exists for a screen rather than the reader. A
                 * list of ranges and dates is unbrowsable, and the
                 * alternative, truncating `summary` to its first sentence,
                 * puts a decision about what a report is ABOUT into a CSS
                 * ellipsis. Asking for it explicitly costs a dozen tokens.
                 */
                'headline' => [
                    'type'        => 'string',
                    'description' => 'One short sentence, under about twelve words, naming the single most '
                        .'notable thing about this window. It appears in a list, so it must make sense '
                        .'with no other context.',
                ],

                'summary' => [
                    'type'        => 'string',
                    'description' => 'Three or four sentences: what this window looked like overall, and the '
                        .'one or two things most worth knowing.',
                ],

                'energy_balance' => [
                    'type'        => 'string',
                    'description' => 'Intake against expenditure. State intake as a range. Say how many days '
                        .'had both halves trustworthy, and if that number is small, say the balance cannot '
                        .'carry much weight.',
                ],

                'food_quality' => [
                    'type'        => 'string',
                    'description' => 'What was actually eaten, from the food list and the day table. Composition, '
                        .'variety, repetition, timing. Qualified by how many days were logged.',
                ],

                'micronutrients' => [
                    'type'        => 'array',
                    'description' => 'One entry per nutrient worth commenting on, from the supplement ledger. '
                        .'Order them by how much they are worth the reader knowing. Do not list every line '
                        .'on every label; forty entries is a table, not a report.',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => [
                            'nutrient',
                            'supplement_exact',
                            'food_estimate_range',
                            'food_coverage_pct',
                            'combined_comment',
                        ],
                        'properties' => [
                            'nutrient' => [
                                'type'        => 'string',
                                'description' => 'The nutrient name exactly as it is printed on the label and '
                                    .'given in the facts. Do not translate it.',
                            ],
                            'supplement_exact' => [
                                'type'        => 'string',
                                'description' => 'The exact supplement contribution, with its unit and the days '
                                    .'it was actually taken — e.g. "500 µg per day, taken on 6 of 7 days".',
                            ],
                            'food_estimate_range' => [
                                'type'        => ['string', 'null'],
                                'description' => 'The food-derived range where the app has one, as a range with '
                                    .'its unit. Null when food is not tracked for this nutrient, which is the '
                                    .'usual case.',
                            ],
                            'food_coverage_pct' => [
                                'type'        => ['number', 'null'],
                                'description' => 'The percentage of intake the food estimate covers, copied from '
                                    .'the facts. Null when there is no food estimate at all.',
                            ],
                            'combined_comment' => [
                                'type'        => 'string',
                                'description' => 'One or two sentences. Never a combined total where food '
                                    .'coverage is incomplete — say what is known and what is not.',
                            ],
                        ],
                    ],
                ],

                'cardiovascular' => [
                    'type'        => 'string',
                    'description' => 'Resting heart rate and HRV, and the baseline-drift block. This is the '
                        .'section where drift belongs: read the three long-window comparisons together, '
                        .'respect their gates, and say plainly when one could not be made.',
                ],

                'sleep' => [
                    'type'        => 'string',
                    'description' => 'Duration, consistency, and how the nights line up with the days after '
                        .'them in the table.',
                ],

                'stress_patterns' => [
                    'type'        => 'string',
                    'description' => 'The daily scores and what they go with. Use the anchor statistics for any '
                        .'numeric comparison; name dates for anything you spotted in the table.',
                ],

                'observations' => [
                    'type'        => 'array',
                    'description' => 'Three to six specific things noticed in the day table that are not already '
                        .'said in a section above. Each must be checkable against the facts.',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['observation', 'evidence'],
                        'properties'           => [
                            'observation' => [
                                'type'        => 'string',
                                'description' => 'One sentence. Something that lines up, not a mechanism that '
                                    .'explains it.',
                            ],
                            'evidence' => [
                                'type'        => 'string',
                                'description' => 'The dates, figures and units this rests on, written as a plain '
                                    .'sentence a person can check against the app. This field is what makes the '
                                    .'observation falsifiable. Never name a JSON field from the facts — write '
                                    .'"the watch covered 87% of that day", not "active_kcal_coverage 0.867".',
                            ],
                        ],
                    ],
                ],

                'suggestions' => [
                    'type'        => 'array',
                    'description' => 'Two to five gentle, practical, non-medical suggestions that follow from '
                        .'THIS window. No medication, no dose changes, no diagnoses, no tests.',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['suggestion', 'rationale'],
                        'properties'           => [
                            'suggestion' => [
                                'type'        => 'string',
                                'description' => 'One sentence, something they could do this week.',
                            ],
                            'rationale' => [
                                'type'        => 'string',
                                'description' => 'What in this window\'s facts prompted it. A suggestion whose '
                                    .'rationale would be true for anybody does not belong here.',
                            ],
                        ],
                    ],
                ],

                'data_gaps' => [
                    'type'        => 'array',
                    'description' => 'What was missing, and what it stopped this report from being able to say. '
                        .'Built from the coverage block, in plain sentences rather than field names. '
                        .'A gap that changed nothing is not worth listing.',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
