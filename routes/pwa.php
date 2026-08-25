<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Pwa\OfflineController;
use App\Http\Controllers\Pwa\ManifestController;
use App\Http\Controllers\Pwa\ServiceWorkerController;

/*
|--------------------------------------------------------------------------
| PWA shell (step 8) — registered with NO middleware group
|--------------------------------------------------------------------------
|
| Three public, session-less routes, outside both `web` and `api`
| (bootstrap/app.php).
|
| WHY PUBLIC: the manifest is read at "Add to Home Screen" (no auth
| context); the service worker registers from the login page too, and a
| 302-to-login served as `application/javascript` would install a login
| form as a worker; the offline page is what renders when nothing can
| reach the network, where a redirect can't be followed. Nothing exposed
| beyond an app name, three colours, linked build-asset filenames, static
| prose.
|
| WHY NO SESSION (why this file exists, not three lines in routes/web.php):
| `SESSION_DRIVER=database` and `/sw.js` revalidates on EVERY navigation —
| inside `web` each hit starts a session, writes a `sessions` row for a
| non-visitor, and Set-Cookies, doubling session growth and killing
| Cloudflare-edge caching. None reads a session, CSRF token or Inertia
| prop.
|
| Global middleware (TrustProxies, host-vhost security headers) still
| applies — framework-wide, not group-scoped.
|
*/

Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest');
Route::get('/sw.js', ServiceWorkerController::class)->name('pwa.sw');
Route::get('/offline', OfflineController::class)->name('pwa.offline');
