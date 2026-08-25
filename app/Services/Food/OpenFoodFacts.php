<?php

declare(strict_types=1);

namespace App\Services\Food;

use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/**
 * The Open Food Facts v3 read client. One method, one endpoint.
 *
 * The User-Agent is not optional — OFF requires an identifying one and
 * blocks generic ones. It's `HealthTracker/1.0 (user@example.com)`,
 * configured rather than literal only so the version can be bumped without
 * a code change. The allowance is 15 requests/minute/IP, which is why the
 * cache in front of this class is the point and this class is the exception.
 *
 * NOTHING HERE THROWS. A barcode lookup failing is an ordinary Tuesday —
 * user in a kitchen with a phone and a jar, OFF a free service — and an
 * unhandled exception would replace a "try again" button with a broken
 * screen. Every failure comes back as a LookupStatus the caller must handle.
 */
final class OpenFoodFacts
{
    /**
     * @return array{status: LookupStatus::Found, product: ProductData}|array{status: LookupStatus::NotFound|LookupStatus::Unavailable, product: null}
     */
    public function fetch(Barcode $barcode): array
    {
        $url = rtrim((string) config('health.food.base_url'), '/')
            .'/api/v3/product/'.$barcode->value.'.json';

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('health.food.user_agent'),
                'Accept'     => 'application/json',
            ])
                ->connectTimeout((float) config('health.food.connect_timeout', 4))
                ->timeout((float) config('health.food.timeout', 8))
                ->get($url);
        } catch (ConnectionException $e) {
            // DNS, TCP, TLS or the timeout. Indistinguishable from OFF being
            // down from where we stand, and handled the same way.
            Log::warning('Open Food Facts unreachable', [
                'barcode' => $barcode->value,
                'error'   => $e->getMessage(),
            ]);

            return $this->unavailable();
        }

        return $this->interpret($barcode, $response);
    }

    /**
     * @return array{status: LookupStatus::Found, product: ProductData}|array{status: LookupStatus::NotFound|LookupStatus::Unavailable, product: null}
     */
    private function interpret(Barcode $barcode, Response $response): array
    {
        // 404 is the documented "no such product" and carries a body saying so.
        // It is the ONE failure that is about the barcode rather than about the
        // service, so it is the one that must not be reported as an outage.
        if ($response->status() === 404) {
            return ['status' => LookupStatus::NotFound, 'product' => null];
        }

        if (! $response->successful()) {
            // 429 (over 15 req/min) and 5xx alike: retryable, not the user's
            // fault, and never cached — writing a row here would poison the
            // cache with an outage.
            Log::warning('Open Food Facts returned an error status', [
                'barcode' => $barcode->value,
                'status'  => $response->status(),
            ]);

            return $this->unavailable();
        }

        try {
            $payload = $response->json();
        } catch (Throwable) {
            $payload = null;
        }

        if (! is_array($payload)) {
            Log::warning('Open Food Facts returned a non-JSON body', [
                'barcode' => $barcode->value,
                'status'  => $response->status(),
            ]);

            return $this->unavailable();
        }

        // A 200 can still be a failure: OFF answers `status: failure` with
        // `result.id = product_not_found` on some deployments and mirrors, and
        // the body is the authority when it disagrees with the status line.
        if (($payload['status'] ?? null) === 'failure' || ! is_array($payload['product'] ?? null)) {
            return ['status' => LookupStatus::NotFound, 'product' => null];
        }

        return [
            'status'  => LookupStatus::Found,
            'product' => ProductData::fromResponse($payload, $barcode),
        ];
    }

    /**
     * @return array{status: LookupStatus::Unavailable, product: null}
     */
    private function unavailable(): array
    {
        return ['status' => LookupStatus::Unavailable, 'product' => null];
    }
}
