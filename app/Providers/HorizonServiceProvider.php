<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon, and specifically who is allowed to look at it.
 *
 * This app has no user accounts yet (step 3 brings the UI), so the stock
 * gate (`in_array($user->email, [...])`) would evaluate against a null
 * user and, single-user with no login, be the only thing between the
 * public internet and a dashboard that lists every queued job, retries
 * them, and exposes job payloads. Not worth an open door.
 *
 * THREE layers, because one will eventually be misconfigured:
 *
 *  1. This gate. Denies unless the app is running locally, or the request
 *     carries HORIZON_DASHBOARD_TOKEN. Unset token => deny, always: an
 *     absent secret must never read as "no secret required". Denial
 *     renders 403.
 *  2. The host vhost returns 404 for
 *     /horizon before the request reaches PHP, so the internet can't
 *     reach it even if this gate is wrong.
 *  3. The in-stack nginx publishes on 127.0.0.1:3083 only, so the token
 *     path is usable through an SSH tunnel and nowhere else.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function (mixed $user = null): bool {
            if ($this->app->environment('local')) {
                return true;
            }

            return $this->hasValidToken(request());
        });
    }

    /**
     * Shared-secret access for a tunnelled look at the dashboard.
     *
     * hash_equals, not `===`: a plain comparison leaks the length of the
     * matching prefix through timing, which is enough to recover the token
     * given patience. Same reasoning as the ingest key.
     */
    private function hasValidToken(?Request $request): bool
    {
        $expected = (string) config('horizon.dashboard_token', '');

        if ($expected === '' || $request === null) {
            return false;
        }

        $provided = (string) ($request->header('X-Horizon-Token') ?? $request->query('token', ''));

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
