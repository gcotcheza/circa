<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Tests\Support\FakeVisionAnalyzer;
use App\Services\Vision\VisionAnalyzer;

/**
 * The fake analyzer, bound in place of the one that calls Anthropic.
 *
 * Every test of a photograph or a typed description has to replace
 * VisionAnalyzer before the container first resolves it, or the suite reaches
 * the API for real. It is kept as a property because the test then tells it
 * what to propose. Laravel's setUpTraits calls a hook named for its trait from
 * inside parent::setUp(), which is early enough for any test method and late
 * enough to have an application to bind into.
 */
trait InteractsWithVision
{
    protected FakeVisionAnalyzer $analyzer;

    protected function setUpInteractsWithVision(): void
    {
        $this->analyzer = new FakeVisionAnalyzer;

        $this->app->instance(VisionAnalyzer::class, $this->analyzer);
    }
}
