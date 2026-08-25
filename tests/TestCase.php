<?php

namespace Tests;

use Illuminate\Testing\PendingCommand;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The Inertia root view's @vite throws when public/build was never
        // generated, so a clean checkout would need an npm install first.
        // Nothing here asserts on hashed bundle filenames.
        $this->withoutVite();
    }

    /**
     * `$this->artisan()` is typed `PendingCommand|int` — a real
     * PendingCommand normally, but `int` (the exit code) once a test has
     * faked the console kernel. Every call site here wants the assertable
     * object, so narrow it once instead of at each call.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $result = $this->artisan($command, $parameters);

        assert($result instanceof PendingCommand);

        return $result;
    }

    /**
     * A test knows a row it just wrote is there — `->first()`, a keyed
     * collection lookup, `->refresh()` are typed nullable for the general
     * case, not for the specific row this test made. Assert it once here
     * rather than `?->` past a value the test is asserting exists.
     *
     * @template T
     *
     * @param  T|null  $value
     * @return T
     */
    protected function notNull(mixed $value, string $message = ''): mixed
    {
        self::assertNotNull($value, $message);

        return $value;
    }

    /**
     * A nested read out of decoded JSON or an untyped array — Inertia props,
     * `TestResponse::json()`, a fact snapshot — is `mixed` to PHPStan, which
     * `collect()` cannot template on. The test already knows the shape from
     * the response it just asserted; say so once here.
     *
     * @return array<mixed>
     */
    protected function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
