<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Vision\ParsedLabel;
use App\Services\Vision\LabelOutcome;
use App\Services\Vision\ProposedItem;
use App\Services\Vision\VisionOutcome;
use App\Services\Vision\VisionAnalyzer;

/**
 * The vision model, without the network.
 *
 * Bound over App\Services\Vision\VisionAnalyzer, the seam that makes the job,
 * controllers and state machine testable without an HTTP call or a CI key. What
 * it CANNOT prove is that our request is one Anthropic accepts — a fake
 * answering our own interface hides a wrong parameter name. That is
 * tests/Feature/Vision/VisionRequestShapeTest, faking a PSR-18 transporter one
 * layer lower and asserting the JSON that goes on the wire.
 */
final class FakeVisionAnalyzer implements VisionAnalyzer
{
    /** @var list<string> the image bytes handed to each call, in order */
    public array $calls = [];

    /** @var list<list<string>> the already-logged names handed to each call, in order */
    public array $context = [];

    /** @var list<string|null> the user's note handed to each call, in order */
    public array $hints = [];

    /** @var list<string> the rendered descriptions handed to each estimate, in order */
    public array $descriptions = [];

    /** @var list<string> the label image bytes handed to each reading, in order */
    public array $labels = [];

    private ?VisionOutcome $outcome = null;

    private ?LabelOutcome $labelOutcome = null;

    public function model(): string
    {
        return 'claude-opus-5';
    }

    public function promptVersion(): string
    {
        return 'v3-photo';
    }

    public function textPromptVersion(): string
    {
        return 'v1-text';
    }

    public function labelPromptVersion(): string
    {
        return 'v1-label';
    }

    /**
     * One photograph of one course, plus the meal's existing items and its
     * owner's note. Both are recorded: `$context` is what the double-count guard
     * asserts on, `$hints` what proves a re-run still carries its reason.
     *
     * @param  list<string>  $alreadyLogged
     */
    public function analyze(string $imageBytes, array $alreadyLogged = [], ?string $hint = null, string $mediaType = 'image/jpeg'): VisionOutcome
    {
        $this->calls[] = $imageBytes;
        $this->context[] = $alreadyLogged;
        $this->hints[] = $hint;

        return $this->outcome ?? self::defaultOutcome();
    }

    /** @return list<string> */
    public function lastContext(): array
    {
        $last = end($this->context);

        return $last === false ? [] : $last;
    }

    /** What the last photo call was told the user said, if anything. */
    public function lastHint(): ?string
    {
        // false means "no calls yet"; a stored null is a real answer (no hint
        // given on that call) and must come back as null too, not the sentinel.
        $last = end($this->hints);

        return $last === false ? null : $last;
    }

    /**
     * The text path, answering the SAME configured outcome as analyze(): a test
     * pinning what happens to a proposal should get one answer from both entry
     * points, since only what was sent differs upstream.
     */
    public function estimate(string $description): VisionOutcome
    {
        $this->descriptions[] = $description;

        return $this->outcome ?? self::defaultOutcome();
    }

    /**
     * One photograph of a supplement label, on its own outcome rather than the
     * meal entry points' `$outcome`: a different question with a different answer
     * shape, or a test that set up rice would read it back as a transcription.
     */
    public function readLabel(string $imageBytes, string $mediaType = 'image/jpeg'): LabelOutcome
    {
        $this->labels[] = $imageBytes;

        return $this->labelOutcome ?? self::defaultLabelOutcome();
    }

    public function labelCount(): int
    {
        return count($this->labels);
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function estimateCount(): int
    {
        return count($this->descriptions);
    }

    public function lastDescription(): string
    {
        $last = end($this->descriptions);

        return $last === false ? '' : $last;
    }

    public function will(VisionOutcome $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    /**
     * @param  list<ProposedItem>  $items
     */
    public function willPropose(array $items, string $notes = 'Plate used as the scale reference.'): self
    {
        return $this->will(VisionOutcome::analysed(
            items: $items,
            notes: $notes,
            raw: ['stub' => true],
            inputTokens: 1_800,
            outputTokens: 420,
            latencyMs: 5_400,
        ));
    }

    public function willFind(string $reason = 'nothing edible'): self
    {
        return $this->will(VisionOutcome::analysed(
            items: [],
            notes: "There is no food in this photograph — it shows {$reason}.",
            raw: ['stub' => true],
            inputTokens: 1_500,
            outputTokens: 60,
            latencyMs: 2_100,
        ));
    }

    public function willFail(string $error = 'The analysis service returned an error.'): self
    {
        return $this->will(VisionOutcome::failed($error, latencyMs: 900));
    }

    /**
     * The label the next reading comes back with, built the way the real path
     * builds it: `ParsedLabel::fromDecoded` on a structured-output-shaped
     * document. Skipping the decoder would let a schema field rename pass the
     * whole suite and fail on the first real bottle.
     *
     * @param  array<string, mixed>  $document
     */
    public function willRead(array $document): self
    {
        $this->labelOutcome = LabelOutcome::read(
            label: ParsedLabel::fromDecoded($document),
            raw: self::rawOf($document),
            inputTokens: 3_200,
            outputTokens: 700,
            latencyMs: 8_100,
        );

        return $this;
    }

    /** "That is not a label": a SUCCESS with nothing in it, and a different screen from a failure. */
    public function willReadNothing(string $sight = 'a plate of pasta'): self
    {
        return $this->willRead([
            'name'         => '',
            'brand'        => null,
            'serving_text' => null,
            'nutrients'    => [],
            'notes'        => "This is not a supplement label — it shows {$sight}.",
        ]);
    }

    public function willFailTheLabel(string $error = 'The label reading came back empty.'): self
    {
        $this->labelOutcome = LabelOutcome::failed($error, latencyMs: 1_200);

        return $this;
    }

    /**
     * A whole message as the API returns one: a thinking block, then the JSON
     * document as the first text block — the shape `vision_requests.raw_response`
     * holds and the review screen decodes, so anything else leaves poll untested.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function rawOf(array $document): array
    {
        return [
            'id'          => 'msg_stub',
            'model'       => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content'     => [
                ['type' => 'thinking', 'thinking' => 'Reading the panel line by line.'],
                ['type' => 'text', 'text' => json_encode($document, JSON_THROW_ON_ERROR)],
            ],
        ];
    }

    public function willRefuse(): self
    {
        return $this->will(VisionOutcome::failed(
            'The analysis was declined for this photo. Try a different picture, or log the meal by hand.',
            latencyMs: 1_100,
            raw: ['stop_reason' => 'refusal'],
            inputTokens: 1_500,
            outputTokens: 0,
        ));
    }

    /** A small, real-shaped Dutch label: two lines, per capsule, no conversions. */
    private static function defaultLabelOutcome(): LabelOutcome
    {
        $document = [
            'name'         => 'Magnesium Glycinate-120',
            'brand'        => 'Helixa',
            'serving_text' => 'per capsule',
            'nutrients'    => [
                ['nutrient' => 'Magnesium (glycinate)', 'amount' => 120, 'unit' => 'mg'],
                ['nutrient' => 'Vitamine B6', 'amount' => 1.4, 'unit' => 'mg'],
            ],
            'notes' => 'Panel read in full.',
        ];

        return LabelOutcome::read(
            label: ParsedLabel::fromDecoded($document),
            raw: self::rawOf($document),
            inputTokens: 3_200,
            outputTokens: 700,
            latencyMs: 8_100,
        );
    }

    private static function defaultOutcome(): VisionOutcome
    {
        return VisionOutcome::analysed(
            items: [self::rice()],
            notes: 'Portion judged against a 27 cm plate.',
            raw: ['stub' => true],
            inputTokens: 1_800,
            outputTokens: 420,
            latencyMs: 5_400,
        );
    }

    /**
     * Deliberately CONSISTENT: every nutrient's low end is its value at
     * portion_g_min, as the prompt asks, so the density conversion round-trips.
     */
    public static function rice(): ProposedItem
    {
        return new ProposedItem(
            name: 'White rice',
            portionGMin: 120,
            portionGMax: 200,
            kcalMin: 156,   // 130 kcal/100 g at 120 g
            kcalMax: 260,   // 130 kcal/100 g at 200 g
            proteinGMin: 3.24,
            proteinGMax: 5.4,
            carbsGMin: 33.6,
            carbsGMax: 56.0,
            fatGMin: 0.36,
            fatGMax: 0.6,
            confidence: 'high',
        );
    }

    public static function chicken(): ProposedItem
    {
        return new ProposedItem(
            name: 'Grilled chicken breast',
            portionGMin: 100,
            portionGMax: 150,
            kcalMin: 165,
            kcalMax: 247.5,
            proteinGMin: 31,
            proteinGMax: 46.5,
            carbsGMin: 0,
            carbsGMax: 0,
            fatGMin: 3.6,
            fatGMax: 5.4,
            confidence: 'medium',
        );
    }

    /** Also consistent: 34 kcal/100 g at both ends of a 100-150 g portion. */
    public static function broccoli(): ProposedItem
    {
        return new ProposedItem(
            name: 'Broccoli',
            portionGMin: 100,
            portionGMax: 150,
            kcalMin: 34,
            kcalMax: 51,
            proteinGMin: 2.8,
            proteinGMax: 4.2,
            carbsGMin: 6.6,
            carbsGMax: 9.9,
            fatGMin: 0.4,
            fatGMax: 0.6,
            confidence: 'high',
        );
    }

    /**
     * What the model spots on a second look at the same dinner: it turns a
     * remembered three-item plate into a four-item proposal.
     */
    public static function oliveOil(): ProposedItem
    {
        return new ProposedItem(
            name: 'Olive oil',
            portionGMin: 5,
            portionGMax: 15,
            kcalMin: 44.2,
            kcalMax: 132.6,
            proteinGMin: 0,
            proteinGMax: 0,
            carbsGMin: 0,
            carbsGMax: 0,
            fatGMin: 5,
            fatGMax: 15,
            confidence: 'low',
        );
    }

    /**
     * Rice CONTRADICTING what the user typed. The preservation rule says this
     * loses: a typed figure is not a hypothesis the model gets to improve. A fake
     * echoing the user's values back would prove nothing — the prompt asks for
     * exactly that, and the question is what happens when it does not.
     */
    public static function wrongRice(): ProposedItem
    {
        return new ProposedItem(
            name: 'White rice',
            portionGMin: 300,
            portionGMax: 400,
            kcalMin: 900,
            kcalMax: 1200,
            proteinGMin: 20,
            proteinGMax: 30,
            carbsGMin: 190,
            carbsGMax: 250,
            fatGMin: 2,
            fatGMax: 3,
            confidence: 'low',
        );
    }

    /**
     * Rice that does NOT round-trip to a flat density: 120-200 g at 150-300 kcal
     * is a 125-150 kcal/100 g band, which a remembered density can be tighter than.
     */
    public static function vagueRice(): ProposedItem
    {
        return new ProposedItem(
            name: 'White rice',
            portionGMin: 120,
            portionGMax: 200,
            kcalMin: 150,
            kcalMax: 300,
            proteinGMin: 3.24,
            proteinGMax: 5.4,
            carbsGMin: 33.6,
            carbsGMax: 56.0,
            fatGMin: 0.36,
            fatGMax: 0.6,
            confidence: 'low',
        );
    }
}
