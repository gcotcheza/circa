<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * One meal in — as a photograph or as a sentence — one VisionOutcome out.
 *
 * The seam lets jobs be tested without an HTTP client, and keeps somebody
 * else's SDK (exceptions, retries, response shape) confined to one file
 * (AnthropicVisionAnalyzer) instead of leaking into a queue worker.
 *
 * Note what's NOT in the signature: no model, no prompt, no schema — those
 * are decisions about how this app asks, not what a caller wants. A job
 * that could pass its own prompt would make
 * `vision_requests.prompt_version` a guess; `estimate()` takes a rendered
 * description for the same reason — the caller supplies EVIDENCE, never
 * the instruction.
 *
 * Both methods share one VisionOutcome schema, so everything downstream
 * (ProposedItem, ProposedItemMapper, MemoryPrefill, ProposalWriter, the
 * review sheet) is shared, not duplicated. "Vision" names the pipeline,
 * not the modality.
 */
interface VisionAnalyzer
{
    /**
     * One photograph of one course.
     *
     * `$alreadyLogged`: names already on the meal (earlier courses, typed
     * lines, barcode scans). CONTEXT, not instruction — the same salad
     * still in shot for a second helping shouldn't add a second salad to
     * the day (see PromptV3Photo).
     *
     * `$hint`: what the person eating it said about the plate ("3 eggs,
     * 120 g drained tuna"). Optional, usually null, same CONTEXT-not-
     * instruction rule — the caller supplies the words, never the sentence
     * they're wrapped in, never interpolated. It rides here rather than
     * off the photo row so this seam still takes only evidence: the caller
     * decides what was said, exactly as it decides which bytes were sent.
     *
     * @param  list<string>  $alreadyLogged
     * @param  'image/gif'|'image/jpeg'|'image/png'|'image/webp'  $mediaType  what PhotoStore re-encoded to
     */
    public function analyze(string $imageBytes, array $alreadyLogged = [], ?string $hint = null, string $mediaType = 'image/jpeg'): VisionOutcome;

    /** The meal in words, rendered by App\Services\Vision\TypedMeal::describe(). */
    public function estimate(string $description): VisionOutcome;

    /**
     * One photograph of a supplement package.
     *
     * The third question this seam asks, and the only one that isn't an
     * estimate: a label is TRANSCRIBED, so the answer has a different
     * shape (LabelOutcome, not VisionOutcome) and skips ProposedItem, the
     * mapper, memory and the review sheet — it doesn't pretend to share a
     * type, because a LabelOutcome squeezed into a VisionOutcome would be
     * a list of items that aren't food.
     *
     * It's on this interface rather than its own because what IS shared is
     * everything that made the seam worth having: one place that knows
     * the model string, one place that owns somebody else's SDK, one fake
     * in the test suite instead of two.
     *
     * @param  'image/gif'|'image/jpeg'|'image/png'|'image/webp'  $mediaType  what PhotoStore re-encoded to
     */
    public function readLabel(string $imageBytes, string $mediaType = 'image/jpeg'): LabelOutcome;

    /** The exact model string this analyzer will use, for the audit row. */
    public function model(): string;

    /** The prompt version `analyze()` will use, for the audit row. */
    public function promptVersion(): string;

    /** The prompt version `estimate()` will use. Versioned separately on purpose. */
    public function textPromptVersion(): string;

    /** The prompt version `readLabel()` will use. Versioned separately again. */
    public function labelPromptVersion(): string;
}
