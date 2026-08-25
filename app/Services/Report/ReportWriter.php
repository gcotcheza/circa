<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * The seam between "assemble the facts" and "ask Anthropic about them".
 *
 * Exists for the same reason App\Services\Vision\VisionAnalyzer does: it
 * lets the job, command, controller and state machine all depend on an
 * interface tests can fake, proving the feature without a network. What a
 * fake can't prove — that the built request is one Anthropic accepts — is
 * covered by tests/Feature/Report/ReportRequestShapeTest, which fakes one
 * layer lower, at the PSR-18 transporter, and asserts the wire JSON.
 */
interface ReportWriter
{
    /**
     * The focus is passed alongside the facts, not dug back out of them: it
     * lives inside the snapshot too (making a stored report replayable),
     * but the writer needs it as a value object, not a nested array, since
     * it decides the output schema — a focused report drops sections whose
     * facts are absent from `required`. Reconstructing that value object
     * from the array right after building the array from it would be a
     * round trip existing only to keep the signature short.
     *
     * @param  array<string, mixed>  $facts  the assembled snapshot
     */
    public function write(array $facts, ?ReportFocus $focus = null): ReportOutcome;

    /** The exact model string, recorded on every health_reports row. */
    public function model(): string;

    /** The prompt version, recorded for the same reason. */
    public function promptVersion(): string;
}
