<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Prompt v3-photo: the same plate, with what the person eating it already
 * knows.
 *
 * `vision_requests.prompt_version` exists so a prompt change is evaluable
 * — every stored `raw_response` can be replayed against a new prompt and
 * diffed — which only works if the version moves whenever the text does;
 * v2-photo stays untouched. The hint couldn't be added as a context block
 * alone under v2's unchanged instruction, since v2's "RANGES, ALWAYS,
 * NEVER a point estimate" is sent LAST and reads as a rule about
 * everything above it — a note saying "120 g of drained tuna" would be
 * overruled by that very sentence. Carving out the exception is an edit to
 * the instruction, hence a new version.
 *
 * The schema, mapper, memory pre-fill, review sheet and `vision:reproject`
 * are unchanged from v2, and the already-logged block is still rendered
 * by PromptV2Photo::alreadyLogged() rather than copied. What's new: THE
 * NOTE IS EVIDENCE — a photograph can't tell you the tuna was drained or
 * the rice weighed, but the person who cooked it can, and the model must
 * not "improve" a stated figure (the same rule PromptV1Text applies to
 * typed figures, now on the photo path). A WEIGHT AND A COUNT ARE
 * DIFFERENT CLAIMS — "120 g of tuna" fixes the grams; "3 eggs" fixes the
 * count and leaves the grams to estimate. And "RANGES, ALWAYS" became
 * "...FOR EVERYTHING YOU ARE ESTIMATING" (v1-text's exact wording): a
 * measured number carries no uncertainty to express, so ranging it would
 * throw the measurement away.
 *
 * Same two-block hygiene as the text path: the user's words render into
 * their own content block, the instruction is a constant no input is ever
 * interpolated into, and it's sent LAST so the final word is always ours.
 * What the model was told lands in `vision_requests.input_payload`, so
 * `prompt_version` + `input_payload` + `raw_response` together reproduce
 * the exact call — what makes a hinted analysis auditable.
 */
final class PromptV3Photo
{
    public const VERSION = 'v3-photo';

    public const TEXT = <<<'PROMPT'
        You are estimating one course of a meal, from one photograph of it.

        This photograph is a single plate, bowl, glass or serving — a first course, a
        second helping, a side, a dessert. It is not necessarily the whole meal. Estimate
        what is in THIS photograph and nothing else.

        Identify every distinct food and drink in the frame and give, for each one, the
        portion in grams and the energy and macronutrients that portion contains.

        IF YOU WERE GIVEN A NOTE FROM THE PERSON WHO ATE THIS, IT IS EVIDENCE, NOT A
        GUESS FOR YOU TO IMPROVE. What they say the food IS settles what it is: they
        cooked it, and you are looking at a photograph. A WEIGHT they give — "120 g of
        drained tuna" — is something they put on a scale: use it exactly, as both ends of
        that item's portion range, and do not round it, widen it, or adjust another item
        to compensate for it. A COUNT they give — "3 eggs" — settles how many, not what
        they weigh: estimate the grams of exactly that many, no more and no fewer. Either
        way the energy and macronutrients for that portion may still be a range, because
        knowing the weight of something is not knowing how it was cooked.

        Estimate only what they did not state, and do not read their note as a complete
        list of the plate. It is what they happened to know, not an inventory: list every
        food and drink you can see that they did not mention, exactly as you would have
        without it. In `notes`, say which items you took from them and which you
        estimated.

        RANGES, ALWAYS, FOR EVERYTHING YOU ARE ESTIMATING. Never a point estimate. Every
        number you estimate is a min and a max, and the gap between them is your
        uncertainty made visible. Widen it when you are unsure — of the portion, of the
        ingredients, of how it was cooked. A wide honest range is useful; a narrow
        confident wrong one is worse than nothing, because it will be added to a day's
        total as though it were measured.

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
     * The user's note about this plate, as its own block — or null when they
     * didn't write one.
     *
     * The header says WHO is speaking, so the model reads it as a claim by
     * the person who ate the food, not an instruction from this app — the
     * distinction the rule in TEXT hangs on: their statements are
     * authoritative about the FOOD and nothing else. Newlines are collapsed
     * so a hint can't be laid out to look like the start of another block;
     * the structural defence (separate content block, sent last, never
     * interpolated) is the strong one, but a one-line note is one less
     * thing to reason about. Absent rather than empty when there's nothing
     * to say, since TEXT already handles its own absence.
     */
    public static function userHint(?string $hint): ?string
    {
        $hint = trim((string) preg_replace('/\s+/u', ' ', (string) $hint));

        if ($hint === '') {
            return null;
        }

        return "A note from the person who ate this, in their own words:\n\n".$hint;
    }

    /**
     * The already-logged block, unchanged from v2 and deliberately not
     * copied — one guard kept in one place, the same argument that keeps
     * the schema shared with PromptV1.
     *
     * @param  list<string>  $names
     */
    public static function alreadyLogged(array $names): ?string
    {
        return PromptV2Photo::alreadyLogged($names);
    }

    /**
     * The response schema — deliberately IDENTICAL to v1's, so there's one
     * parser, one mapper, one review screen: versioning the prompt
     * separately only ever changes the instruction, never the shape of the
     * answer.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return PromptV1::schema();
    }
}
