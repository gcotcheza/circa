<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\Pwa\BuildAssets;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;

/**
 * `GET /sw.js` — the service worker script.
 *
 * A route, not a static file, for three things a file can't do: (1) THE
 * PRECACHE LIST must name the current build's content-hashed assets,
 * generated at request time from the Vite manifest so the worker and page
 * can never disagree — a committed file with hashes is wrong the moment
 * someone builds. (2) THE CACHE HEADERS: a service worker MUST revalidate,
 * since one cached for a year can never be updated once it's on a home
 * screen, and nginx would need a location block where this is one line
 * next to its reason. (3) THE SCOPE HEADER, `Service-Worker-Allowed: /`,
 * is redundant while served from the root and stops being redundant the
 * day it moves — sending it costs nothing. The response carries an ETag,
 * so per-navigation revalidation is a 304 with an empty body.
 *
 * IT IS PUBLIC (no auth): the worker registers from the login page too,
 * and a 302-to-login served as `application/javascript` would install a
 * "worker" that's a login form. Nothing in the script is secret.
 */
final class ServiceWorkerController extends Controller
{
    private const SOURCE = 'js/service-worker.js';

    public function __invoke(Request $request, BuildAssets $assets): Response
    {
        $script = str_replace(
            ['__SW_VERSION__', '__SW_PRECACHE__'],
            [
                $assets->version(),
                // JSON_UNESCAPED_SLASHES so paths read as paths — a worker
                // full of `\/build\/` is legal JS and unreadable at 7am.
                (string) json_encode($assets->precacheUrls(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ],
            (string) File::get(resource_path(self::SOURCE)),
        );

        $response = response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',

            /*
             * NO LONG-LIVED CACHE. `no-cache` means "store it, but ask
             * first" — with the ETag below, that makes the per-navigation
             * check a 304. It also stops Cloudflare holding it: `.js` is in
             * the edge's default cacheable list, and this origin
             * Cache-Control is what opts out — a worker cached at the edge
             * would mean the phone keeping the old app for hours after a
             * deploy.
             */
            'Cache-Control' => 'no-cache, must-revalidate',

            // Redundant at the root; correct if it ever moves. See above.
            'Service-Worker-Allowed' => '/',
        ]);

        $response->setEtag(md5($script));

        // Turns the update check into an empty 304 without changing any of the
        // headers above.
        $response->isNotModified($request);

        return $response;
    }
}
