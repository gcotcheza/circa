<?php

declare(strict_types=1);

namespace Tests\Unit\Vision;

use PHPUnit\Framework\TestCase;
use App\Services\Vision\ParsedLabel;
use App\Services\Vision\PromptV1Label;

/**
 * Reading the model's answer, live and replayed: the two decodes must agree
 * exactly, or "the review screen shows what the audit row records" becomes
 * "something close to it".
 */
final class ParsedLabelTest extends TestCase
{
    public function test_it_reads_a_dutch_panel_without_touching_the_units(): void
    {
        $label = ParsedLabel::fromDecoded([
            'name'         => 'Vitamin D3 75 mcg',
            'brand'        => 'Nordvita',
            'serving_text' => 'per softgel',
            'nutrients'    => [
                ['nutrient' => 'Vitamine D3 (cholecalciferol)', 'amount' => 75, 'unit' => 'mcg'],
                ['nutrient' => 'Olijfolie', 'amount' => 100, 'unit' => 'mg'],
            ],
            'notes' => 'Panel gelezen.',
        ]);

        self::assertSame('Vitamin D3 75 mcg', $label->name);
        self::assertSame('Nordvita', $label->brand);
        self::assertSame('per softgel', $label->servingText);
        self::assertCount(2, $label->nutrients);
        self::assertSame('Vitamine D3 (cholecalciferol)', $label->nutrients[0]->nutrient);
        self::assertSame(75.0, $label->nutrients[0]->amount);
        self::assertSame('mcg', $label->nutrients[0]->unit);
        self::assertFalse($label->isEmpty());
    }

    public function test_a_line_with_no_readable_figure_keeps_its_name(): void
    {
        $label = ParsedLabel::fromDecoded([
            'name'         => 'Daily Complete 50',
            'brand'        => null,
            'serving_text' => null,
            'nutrients'    => [
                ['nutrient' => 'Vitamine D', 'amount' => null, 'unit' => null],
            ],
            'notes' => 'The vitamin D line was obscured.',
        ]);

        self::assertNull($label->brand);
        self::assertNull($label->servingText);
        self::assertSame('Vitamine D', $label->nutrients[0]->nutrient);
        self::assertNull($label->nutrients[0]->amount);
        self::assertNull($label->nutrients[0]->unit);
    }

    /** A nameless line is nothing the user can review, edit or believe. */
    public function test_a_nameless_line_is_dropped(): void
    {
        $label = ParsedLabel::fromDecoded([
            'name'         => 'Something',
            'brand'        => null,
            'serving_text' => null,
            'nutrients'    => [
                ['nutrient' => '', 'amount' => 12, 'unit' => 'mg'],
                ['nutrient' => 'Zink', 'amount' => 7.5, 'unit' => 'mg'],
            ],
            'notes' => '',
        ]);

        self::assertCount(1, $label->nutrients);
        self::assertSame('Zink', $label->nutrients[0]->nutrient);
    }

    /** An empty reading is still correct: a SUCCESS, not the failure screen. */
    public function test_a_photograph_that_is_not_a_label_reads_as_empty(): void
    {
        $label = ParsedLabel::fromDecoded([
            'name'         => '',
            'brand'        => null,
            'serving_text' => null,
            'nutrients'    => [],
            'notes'        => 'This is a plate of pasta.',
        ]);

        self::assertTrue($label->isEmpty());
        self::assertSame('This is a plate of pasta.', $label->notes);
    }

    public function test_an_empty_string_field_becomes_null_rather_than_a_blank(): void
    {
        $label = ParsedLabel::fromDecoded([
            'name'         => '  Magnesium  ',
            'brand'        => '   ',
            'serving_text' => '',
            'nutrients'    => [],
            'notes'        => '',
        ]);

        self::assertSame('Magnesium', $label->name);
        self::assertNull($label->brand);
        self::assertNull($label->servingText);
    }

    public function test_a_missing_document_does_not_throw(): void
    {
        $label = ParsedLabel::fromDecoded([]);

        self::assertSame('', $label->name);
        self::assertSame([], $label->nutrients);
        self::assertTrue($label->isEmpty());
    }

    /** The message is kept whole, so the walk must get past the thinking block. */
    public function test_the_replay_of_a_stored_message_reads_identically(): void
    {
        $document = [
            'name'         => 'Omega-3 375',
            'brand'        => 'Helixa',
            'serving_text' => 'per 2 mini softgels',
            'nutrients'    => [
                ['nutrient' => 'EPA (eicosapentaeenzuur)', 'amount' => 375, 'unit' => 'mg'],
                ['nutrient' => 'DHA (docosahexaeenzuur)', 'amount' => 250, 'unit' => 'mg'],
            ],
            'notes' => '',
        ];

        $live = ParsedLabel::fromDecoded($document);

        $replayed = ParsedLabel::fromRawResponse([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Reading the panel.'],
                ['type' => 'text', 'text' => json_encode($document, JSON_THROW_ON_ERROR)],
            ],
        ]);

        self::assertEquals($live->toArray(), $replayed?->toArray());
    }

    public function test_a_stored_message_with_no_readable_answer_is_null(): void
    {
        self::assertNull(ParsedLabel::fromRawResponse(['content' => []]));
        self::assertNull(ParsedLabel::fromRawResponse([
            'content' => [['type' => 'text', 'text' => 'not json at all']],
        ]));
    }

    /** The API enforces the schema: a field it does not require will one day be absent. */
    public function test_the_schema_requires_every_field_the_parser_reads(): void
    {
        $schema = PromptV1Label::schema();

        self::assertSame(
            ['name', 'brand', 'serving_text', 'nutrients', 'notes'],
            $schema['required']
        );

        self::assertFalse($schema['additionalProperties']);

        $line = $schema['properties']['nutrients']['items'];

        self::assertSame(['nutrient', 'amount', 'unit'], $line['required']);
        self::assertFalse($line['additionalProperties']);

        // Nullable rather than optional: an absent field is a shape the parser
        // must defend against, a null is one it can read.
        self::assertSame(['number', 'null'], $line['properties']['amount']['type']);
        self::assertSame(['string', 'null'], $line['properties']['unit']['type']);
    }

    /** The prompt version is the audit's handle on which text produced a row. */
    public function test_the_label_prompt_is_versioned_separately(): void
    {
        self::assertSame('v1-label', PromptV1Label::VERSION);
        self::assertStringContainsString('TRANSCRIPTION', PromptV1Label::TEXT);
        self::assertStringContainsString('DO NOT CONVERT ANYTHING', PromptV1Label::TEXT);
    }
}
