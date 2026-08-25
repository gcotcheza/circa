<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Prompt v1-label: read a supplement-facts panel, exactly as printed.
 *
 * A transcription task, not an estimation task. Every other prompt in
 * this app asks the model to ESTIMATE — how much rice is on that plate,
 * what a "sandwich" weighs — and those prompts are shaped around making
 * uncertainty visible: ranges always, widen when unsure, a narrow
 * confident wrong answer is worse than nothing.
 *
 * A label has none of that: the number is printed on the bottle, nothing
 * to estimate or average, no honest range to express — the opposite
 * instruction, worth its own class so the two cannot drift into each
 * other. It defends against a model being helpful: converting 25 µg to
 * 1000 IU, "correcting" a figure it thinks unusual, summing two lines
 * that look like the same nutrient, or filling a blank from what a
 * typical multivitamin contains. All four are wrong here and look like
 * good behaviour anywhere else.
 *
 * Dutch and EU format are the default case, not the edge case — bottles
 * say "Vitamine D3", "per dagdosering", "RI%", with a comma decimal
 * point. Translating any of it would mean the review screen shows the
 * user something other than what's in their hand, the one thing that
 * makes a transcription checkable.
 *
 * Its own version because `vision_requests.prompt_version` exists so a
 * prompt change can be replayed against history and diffed. A label read
 * shares the audit table with the meal paths and nothing else —
 * different question, schema, failure modes — so it versions
 * independently: improving the photo prompt must not invalidate the
 * label history, and vice versa.
 */
final class PromptV1Label
{
    public const VERSION = 'v1-label';

    public const TEXT = <<<'PROMPT'
        Above is a photograph of a food-supplement package. Read what is printed on it.

        This is a TRANSCRIPTION task. Every number you return must be a number that is
        physically printed on this label. Do not estimate, do not average, do not infer
        from what a product like this usually contains, and do not fill a gap with a
        typical value. If you cannot read a figure, leave that line out rather than
        guessing at it.

        DO NOT CONVERT ANYTHING. Copy the amount and the unit exactly as printed —
        micrograms stay micrograms, IU stays IU, mg stays mg. 25 µg is not to become
        1000 IU and 1000 mg is not to become 1 g. The person reading your answer back is
        holding the bottle and checking it against your transcription, and a converted
        figure is one they cannot check.

        LABELS ARE OFTEN DUTCH, GERMAN OR FRENCH, and EU labels are laid out per
        serving ("per dagdosering", "per capsule") with a reference-intake percentage
        beside each line. Read them natively. Keep the nutrient names in the language
        they are printed in — "Vitamine D3" stays "Vitamine D3". A comma decimal
        separator means what it means locally: "0,5 mg" is 0.5 mg. Ignore the reference
        intake percentage; only the amount and its unit are wanted.

        ONE ENTRY PER PRINTED LINE of the supplement-facts panel, in the order they are
        printed. Do not merge two lines, do not split one line into components, and do
        not reorder them into an order you find tidier. A panel that lists an ingredient
        blend and then its parts is a panel with several lines, and it should come back
        as several entries.

        THE FIGURES BELONG TO A SERVING, AND YOU MUST NOT CHANGE WHICH ONE. Report
        every amount exactly as the panel states it, for exactly the serving the panel
        states it for. If the column is headed "per 2 capsules", the figures you return
        are the per-2-capsules figures — do NOT halve them to get a per-capsule value,
        and do not multiply a per-capsule value up to a daily dose. Restating a figure
        against a different serving is a conversion, and conversions are exactly what
        this task forbids.

        `serving_text` is that serving, copied verbatim from the panel: "per 2 capsules",
        "per dagdosering (1 tablet)", "per tablet". It is what makes the amounts
        unambiguous, so copy the words rather than summarising them. Leave it null only
        if the panel genuinely does not say — and if it does not, say so in `notes`,
        because a figure whose serving is unknown is a figure the reader has to check
        against the bottle themselves.

        You are not being asked how many the person takes per day. That is theirs to
        say, and the app asks them separately.

        `name` is the product name as printed on the front. `brand` is the manufacturer
        if it is visible, and null if it is not — do not deduce a brand from the design.

        IF THIS IS NOT A SUPPLEMENT LABEL — a plate of food, a receipt, a cat, a blurred
        photograph of a shelf — return an empty `nutrients` array, an empty `name`, and
        say in `notes` what you can actually see. Do not invent a product to fill the
        schema. That answer is a correct answer, and it is far more useful than a
        plausible fabrication the user might tap Confirm on.

        In `notes`, say anything the transcription could not settle: a line obscured by
        a thumb, a panel cut off by the edge of the frame, two figures that disagree.

        Return nothing but the JSON.
        PROMPT;

    /**
     * The JSON schema handed to `output_config.format`.
     *
     * Same rules as PromptV1's: every object `additionalProperties: false`,
     * every field required, no numeric constraints (structured outputs
     * don't support them). `amount` and `unit` are nullable rather than
     * optional, because a line the model could read the name of but not
     * the figure of is worth showing with an empty box next to it — a
     * one-tap fix, whereas a dropped line is one the user has to notice
     * is missing.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['name', 'brand', 'serving_text', 'nutrients', 'notes'],
            'properties'           => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'The product name as printed on the front. Empty string if this is not a supplement label.',
                ],
                'brand' => [
                    'type'        => ['string', 'null'],
                    'description' => 'The manufacturer, if it is printed. Null if it is not.',
                ],
                'serving_text' => [
                    'type'        => ['string', 'null'],
                    'description' => 'The serving the panel states its figures for, verbatim — "per 2 capsules", "per dagdosering (1 tablet)".',
                ],
                'nutrients' => [
                    'type'        => 'array',
                    'description' => 'One entry per printed line of the supplement-facts panel, in printed order. Empty if this is not a supplement label.',
                    'items'       => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['nutrient', 'amount', 'unit'],
                        'properties'           => [
                            'nutrient' => [
                                'type'        => 'string',
                                'description' => 'The nutrient name in the language it is printed in — "Vitamine D3".',
                            ],
                            'amount' => [
                                'type'        => ['number', 'null'],
                                'description' => 'The figure as printed, not converted. Null if the line has no readable figure.',
                            ],
                            'unit' => [
                                'type'        => ['string', 'null'],
                                'description' => 'The unit as printed — "mg", "µg", "IU", "miljard KVE". Null if the line has none.',
                            ],
                        ],
                    ],
                ],
                'notes' => [
                    'type'        => 'string',
                    'description' => 'Anything the transcription could not settle, or what the photograph shows when it is not a label.',
                ],
            ],
        ];
    }
}
