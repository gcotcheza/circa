<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Prompt v2-photo: one plate at a time, on a meal that may already have some.
 *
 * A new version, not an edit to v1, because `vision_requests.prompt_version`
 * makes a prompt change evaluable — every stored `raw_response` can be
 * replayed against a new prompt and diffed, rather than judged on whether the
 * next dinner looked right. That only works if the version moves with the
 * text; v1 stays exactly as it was.
 *
 * Schema is unchanged (same items, ranges, `notes`), so mapper, memory
 * pre-fill, review sheet and `vision:reproject` are shared code. What changed
 * is the FRAME:
 *
 * 1. The photograph is one course, not the meal — told "this is what a
 *    person is about to eat", a model reads a bowl of ice cream as dinner and
 *    widens portions; told it's the dessert of a dinner underway, it
 *    estimates the bowl.
 *
 * 2. The double-count guard, which costs real calories: log the main course,
 *    then photograph the second helping with the same side salad still in
 *    shot, and a model with no memory of the first call lists the salad
 *    again — Confirm APPENDS, so the day gains a salad nobody ate. So
 *    already-logged names go in their own block (see
 *    AnthropicVisionAnalyzer::analyze) and the rule is phrased as "list
 *    what's visible, and say so in the name if something already logged is
 *    visibly there AGAIN" rather than "don't repeat yourself" — the latter
 *    invites dropping food that IS on the plate. The user can delete a line
 *    but can't notice a missing one.
 *
 * 3. The names are context, not data — their own block, not concatenated
 *    into the instruction, same reason the text path keeps the typed meal
 *    separate: derived from user input, which never becomes instruction text.
 */
final class PromptV2Photo
{
    public const VERSION = 'v2-photo';

    public const TEXT = <<<'PROMPT'
        You are estimating one course of a meal, from one photograph of it.

        This photograph is a single plate, bowl, glass or serving — a first course, a
        second helping, a side, a dessert. It is not necessarily the whole meal. Estimate
        what is in THIS photograph and nothing else.

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

        If you were given a list of foods already logged for this meal, those were
        estimated from earlier photographs of earlier courses and are already counted.
        Do not list them again from memory. Do list something that appears on that list
        if it is genuinely visible in THIS photograph as a further serving — a second
        helping, a refilled glass — and say so in its name, for example "rice (second
        helping)". Judge that from the photograph, not from the list: food you can see
        is food that was served.

        If the image contains no food or drink at all, return an empty `items` array and
        describe what you can see in `notes`. Do not invent a meal to fill the schema.

        Return nothing but the JSON.
        PROMPT;

    /**
     * The already-logged block, or null when there's nothing to say.
     *
     * A meal with no items yet gets no block at all, not an empty list —
     * "already logged: (nothing)" would sit in front of every first course
     * forever, and the prompt above already handles its own absence.
     *
     * @param  list<string>  $names
     */
    public static function alreadyLogged(array $names): ?string
    {
        $names = array_values(array_unique(array_filter(
            array_map(static fn (string $name): string => trim($name), $names),
            static fn (string $name): bool => $name !== '',
        )));

        if ($names === []) {
            return null;
        }

        $lines = implode("\n", array_map(static fn (string $name): string => '- '.$name, $names));

        return "Already logged for this meal, from earlier photographs of earlier courses:\n\n".$lines;
    }

    /**
     * The response schema — deliberately IDENTICAL to v1's. A second schema
     * would mean a second parser, mapper and review screen — the point of
     * versioning the prompt separately is that the answer's shape didn't change.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return PromptV1::schema();
    }
}
