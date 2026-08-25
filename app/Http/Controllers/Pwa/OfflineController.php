<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use Illuminate\Http\Response;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;

/**
 * `GET /offline` — the page the service worker shows when a navigation cannot
 * reach the network.
 *
 * A route rather than a flat HTML file so it is compiled, tested and versioned
 * like every other view, and so the precache entry is a URL the router owns.
 *
 * PUBLIC by necessity: a guest navigation while offline would otherwise fall
 * through auth middleware to a redirect the worker cannot follow, and this page
 * exists precisely to render when nothing else can. It is also the one HTML
 * response in the app ALLOWED to be cached, holding no data — no meals, no
 * calories, no name.
 */
final class OfflineController extends Controller
{
    public function __invoke(): Response
    {
        /** @var View $view */
        $view = view('offline');

        return response($view->render(), 200, [
            'Content-Type' => 'text/html; charset=utf-8',

            // A day at the edge. The worker keeps its own copy for as long as
            // the build version holds, so this governs only the first fetch
            // and browsers with no worker yet.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
