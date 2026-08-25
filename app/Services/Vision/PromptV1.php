<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Prompt v1 and the response schema it is paired with.
 *
 * A constant, not config, because `vision_requests.prompt_version` exists
 * so a prompt change becomes evaluable: the stored `raw_response` for
 * every past photo can be replayed against a new prompt and diffed,
 * instead of the change being judged on whether the next dinner looked
 * about right. That only works if the version moves whenever the text
 * does — a prompt in config/health.php could be edited without touching
 * VERSION, and then half the rows labelled `v1` would have come from two
 * different prompts with no way to tell them apart. So: edit the text,
 * add a v2 class, leave v1 alone.
 *
 * The schema is enforced by the API (structured outputs), not the prompt
 * — why the prompt says nothing about JSON formatting beyond the last
 * line. What the prompt is for is the part a schema can't express: that
 * these are RANGES, that the low end of every nutrient belongs to the low
 * end of the portion, and that "I don't know" is spelled "wider," not
 * "omitted."
 */
final class PromptV1
{
    public const VERSION = 'v1';

    public const TEXT = <<<'PROMPT'
        You are estimating what a person is about to eat, from one photograph of it.

        Identify every distinct food and drink in the frame and give, for each one, the
        portion in grams and the energy and macronutrients that portion contains.

        RANGES, ALWAYS. Never a point estimate. Every number you return is a min and a
        max, and the gap between them is your uncertainty made visible. Widen it when
        you are unsure — of the portion, of the ingredients, of how it was cooked. A
        wide honest range is useful; a narrow confident wrong one is worse than nothing,
        because it will be added to a day's total as though it were measured.

        Keep the ranges internally consistent: the low end of every nutrient range must
        be the value AT portion_g_min, and the high end the value AT portion_g_max. So
        if you say 120-200 g of rice, kcal_min is the calories in 120 g and kcal_max the
        calories in 200 g.

        Judge the portion against whatever scale reference is visible — a fork or spoon,
        a hand, a standard 26-28 cm dinner plate, a mug, a drinks can, a phone — and say
        in `notes` which one you used. If nothing in the frame gives you a scale, say so
        in `notes` and widen the portion ranges substantially rather than guessing.

        Separate the components you can actually see. Sauce or dressing you can identify
        is its own item; a stew you cannot take apart is one item with a wide range.

        `confidence` is about identification, not portion: `high` if you are sure what
        the food is, `low` if it is a guess from colour and shape.

        If the image contains no food or drink at all, return an empty `items` array and
        describe what you can see in `notes`. Do not invent a meal to fill the schema.

        Return nothing but the JSON.
        PROMPT;

    /**
     * The JSON schema handed to `output_config.format`.
     *
     * Every object is `additionalProperties: false` and every field
     * required, because structured outputs enforce exactly what's written
     * here and an optional field is one the parser then has to defend
     * against. No `minimum`/`maximum` constraints — not supported by
     * structured outputs, and pretending otherwise would mean the
     * plausibility ceilings lived in two places. They're applied on the
     * way into the database instead (see ProposedItemMapper).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $number = ['type' => 'number'];

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['items', 'notes'],
            'properties'           => [
                'items' => [
                    'type'        => 'array',
                    'description' => 'One entry per distinct food or drink. Empty if the photo contains none.',
                    'items'       => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => [
                            'name',
                            'portion_g_min', 'portion_g_max',
                            'kcal_min', 'kcal_max',
                            'protein_g_min', 'protein_g_max',
                            'carbs_g_min', 'carbs_g_max',
                            'fat_g_min', 'fat_g_max',
                            'confidence',
                        ],
                        'properties' => [
                            'name' => [
                                'type'        => 'string',
                                'description' => 'Short human name, e.g. "grilled chicken breast".',
                            ],
                            'portion_g_min' => $number,
                            'portion_g_max' => $number,
                            'kcal_min'      => $number,
                            'kcal_max'      => $number,
                            'protein_g_min' => $number,
                            'protein_g_max' => $number,
                            'carbs_g_min'   => $number,
                            'carbs_g_max'   => $number,
                            'fat_g_min'     => $number,
                            'fat_g_max'     => $number,
                            'confidence'    => [
                                'type'        => 'string',
                                'enum'        => ['low', 'medium', 'high'],
                                'description' => 'How sure you are that the food is identified correctly.',
                            ],
                        ],
                    ],
                ],
                'notes' => [
                    'type'        => 'string',
                    'description' => 'The scale reference used, anything ambiguous, or what the photo shows when it contains no food.',
                ],
            ],
        ];
    }
}
