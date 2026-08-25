<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Report\ReportFocus;
use App\Services\Report\ReportWriter;
use App\Services\Report\ReportOutcome;
use Database\Factories\HealthReportFactory;

/**
 * The report writer, without the network.
 *
 * Bound over App\Services\Report\ReportWriter, the seam that makes the job,
 * command, controller and state machine testable without an HTTP call or a CI
 * key. It CANNOT prove our request is one Anthropic accepts — a fake answering
 * our own interface hides a wrong parameter name or a rejected schema; that is
 * tests/Feature/Report/ReportRequestShapeTest, faking a PSR-18 transporter one
 * layer lower, as FakeVisionAnalyzer pairs with VisionRequestShapeTest. THE
 * DEFAULT ANSWER COMES FROM THE FACTORY — one canned document shared with
 * HealthReportFactory, so a schema change breaks fake and fixtures together.
 */
final class FakeReportWriter implements ReportWriter
{
    /** @var list<array<string, mixed>> every fact sheet handed to this writer, in order */
    public array $calls = [];

    /** @var list<ReportFocus> the focus handed alongside each of those fact sheets */
    public array $focuses = [];

    private ?ReportOutcome $outcome = null;

    public function model(): string
    {
        return 'claude-opus-5';
    }

    /**
     * The version the real writer sends, as a literal. Not read off
     * PromptV2Report: a fake tracking the live constant would agree with
     * production whatever production did, and the stamp on a report is worth
     * asserting against a typed value. ReportRequestShapeTest proves the literal
     * still matches the wire.
     */
    public function promptVersion(): string
    {
        return 'v2.5-report';
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function write(array $facts, ?ReportFocus $focus = null): ReportOutcome
    {
        // Recorded, not swallowed: what the assembler produced IS the question,
        // and asserting from the far side of the job proves the job passed it on.
        $this->calls[] = $facts;

        // The focus separately: it is in the snapshot too, but passing it as an
        // argument is what makes the output schema focus-aware, so reading it back
        // out of the facts array would pass even if the job dropped it.
        $this->focuses[] = $focus ?? ReportFocus::everything();

        return $this->outcome ?? self::defaultOutcome();
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * @return array<string, mixed>
     */
    public function lastFacts(): array
    {
        // `end()` not `array_key_last()`: see lastFocus() below — indexing with
        // a possibly-null key is a question, not an answer.
        $last = end($this->calls);

        return $last === false ? [] : $last;
    }

    public function lastFocus(): ReportFocus
    {
        // `end()` not `array_key_last()`: the latter is nullable on an empty array
        // and indexing with a null key is a question. A writer never called was
        // never given a focus, which is `everything()`.
        $last = end($this->focuses);

        return $last === false ? ReportFocus::everything() : $last;
    }

    public function will(ReportOutcome $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    public function willWrite(?array $document = null, int $inputTokens = 14_200, int $outputTokens = 3_100): self
    {
        return $this->will(ReportOutcome::written(
            report: $document ?? HealthReportFactory::document(),
            raw: ['stub' => true],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            latencyMs: 48_000,
        ));
    }

    public function willFail(string $error = 'The report came back empty.', ?int $inputTokens = 14_200, ?int $outputTokens = 0): self
    {
        return $this->will(ReportOutcome::failed(
            error: $error,
            latencyMs: 9_100,
            raw: ['stub' => true],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        ));
    }

    private static function defaultOutcome(): ReportOutcome
    {
        return ReportOutcome::written(
            report: HealthReportFactory::document(),
            raw: ['stub' => true],
            inputTokens: 14_200,
            outputTokens: 3_100,
            latencyMs: 48_000,
        );
    }
}
