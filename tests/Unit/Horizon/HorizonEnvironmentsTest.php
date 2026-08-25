<?php

declare(strict_types=1);

namespace Tests\Unit\Horizon;

use Tests\TestCase;

/**
 * Which environments Horizon has a supervisor set for.
 *
 * Horizon reads `horizon.environments.<APP_ENV>`, and a MISSING key is not an
 * error — it boots the master process with zero workers, so every queued job
 * sits in `pending` forever with nothing in the logs to say why. The staging
 * stack runs Horizon under APP_ENV=staging, so a
 * `staging` block has to exist. The one time it did not, a staged health report
 * was stuck the better part of an hour before anyone found the cause.
 */
final class HorizonEnvironmentsTest extends TestCase
{
    public function test_staging_has_an_ingest_supervisor(): void
    {
        $environments = config('horizon.environments');

        self::assertIsArray($environments);
        self::assertArrayHasKey(
            'staging',
            $environments,
            'Horizon has no `staging` environment — the staging stack would run with no workers.',
        );
        self::assertArrayHasKey(
            'ingest',
            $environments['staging'],
            'The `staging` environment is missing the `ingest` supervisor that watches the default queue.',
        );
    }

    public function test_every_horizon_environment_defines_the_ingest_supervisor(): void
    {
        foreach (config('horizon.environments') as $env => $supervisors) {
            self::assertArrayHasKey(
                'ingest',
                $supervisors,
                "Horizon environment '{$env}' has no `ingest` supervisor, so it would run no workers.",
            );
        }
    }
}
