<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Prompt v1-text: the same question, asked of a sentence instead of a photo.
 *
 * A separate version, not a branch inside PromptV1: `vision_requests.prompt_version`
 * lets a prompt change be replayed against history and diffed, which two
 * prompts sharing one version string would destroy — improving the photo
 * prompt must not invalidate the text history, and vice versa. The SCHEMA
 * is deliberately shared (`PromptV1::schema()`), the contract the rest of
 * the pipeline is built on (ProposedItem parses it, ProposedItemMapper
 * converts it, the review sheet edits it); a second schema differing by
 * one field would mean a second parser, mapper, and review screen. Only
 * the evidence and instruction differ between paths, not the shape of the
 * answer.
 *
 * The rule that matters most: DO NOT CORRECT THE USER. A typed "210 kcal"
 * next to the rice is a reading off a packet, not a hypothesis to improve
 * — a model that "fixes" it to 260 silently overwrites the one number
 * that wasn't a guess. The prompt says so twice, and the pipeline doesn't
 * rely on it either: the job restores every user-supplied value over
 * whatever comes back (see App\Services\Vision\TypedMeal::preserve), so
 * the model reasons AROUND fixed numbers rather than being silently
 * contradicted afterwards.
 */
final class PromptV1Text
{
    public const VERSION = 'v1-text';

    public const TEXT = <<<'PROMPT'
        You are estimating what a person ate, from their own written description of it.

        Above is a meal as its owner typed it into a food log: a list of items, some
        with a portion, some with figures they already know, most with neither. For
        every item, give the portion in grams and the energy and macronutrients that
        portion contains.

        WHAT THE USER TOLD YOU IS GROUND TRUTH. Any portion or value marked as given is
        a fact, not a proposal. Echo it back EXACTLY — same number, as both the min and
        the max, because a number a person typed carries no uncertainty for you to
        express. Do not round it, do not "correct" it, and do not adjust a neighbouring
        item to compensate. Estimate ONLY what is missing. A given figure is also your
        best clue about the rest of that item: 210 kcal of rice tells you roughly how
        much rice there is.

        RANGES, ALWAYS, for everything you are estimating. Never a point estimate. Every
        number you supply is a min and a max, and the gap between them is your
        uncertainty made visible.

        A DESCRIPTION IS VAGUER THAN A PHOTOGRAPH, AND THE RANGES MUST SAY SO. You
        cannot see this food. You do not know how big the bowl was, how much oil went in
        the pan, or whether "a sandwich" is a round of white bread or a foot of baguette.
        Where the description is thin, widen the portion range substantially rather than
        assuming a standard serving — a wide honest range is useful, and a narrow
        confident wrong one is worse than nothing, because it will be added to a day's
        total as though it were measured. Where the description is specific ("2 slices
        of wholemeal toast"), you may be correspondingly tight.

        Keep the ranges internally consistent: the low end of every nutrient range must
        be the value AT portion_g_min, and the high end the value AT portion_g_max. So
        if you say 120-200 g of rice, kcal_min is the calories in 120 g and kcal_max the
        calories in 200 g.

        Return one entry per item the user listed, in the order they listed them, using
        their own wording for `name` so they can recognise their own meal. Do not merge
        two of their items into one and do not invent an item they did not mention — no
        assumed side salad, no assumed glass of water, no assumed butter on the toast
        unless they said so. Split one of their items only if they clearly described
        several foods in a single line ("chicken and rice"), and say so in `notes`.

        Use the meal type and the time of day as context for what a portion is likely to
        be, and the meal notes for anything else the user thought was worth writing down.

        `confidence` is about identification, not portion: `high` when the item names a
        specific food, `low` when it is a category ("leftovers", "snack") you have had to
        guess at.

        In `notes`, say in one or two sentences what you had to assume — the assumptions
        are the whole reason the numbers are ranges, and they are what the user is being
        asked to check.

        Return nothing but the JSON.
        PROMPT;

    /**
     * The response schema, shared verbatim with the photo path.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return PromptV1::schema();
    }
}
