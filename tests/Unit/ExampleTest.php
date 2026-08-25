<?php

namespace Tests\Unit;

use PHPUnit\Runner\Version;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic smoke test: the runner itself reports a real version, not a
     * literal `assertTrue(true)` — PHPStan folds that to "always true" since
     * both sides are compile-time constants, which defeats the point of a
     * smoke test (a genuine assertion that would fail if something broke).
     */
    public function test_the_test_runner_reports_a_real_version(): void
    {
        $this->assertNotSame('', Version::id());
    }
}
