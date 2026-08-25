<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Somewhere to put the failures that happen on the phone.
 *
 * WHY THIS TABLE EXISTS. The app is a client-rendered PWA: blade ships an
 * empty `#app` and JavaScript draws every pixel after that, so "the screen
 * was blank" leaves NO server-side trace — HTML and assets are 200s, the
 * queue is empty, and the person holding the phone has no way to say what
 * it said, because it said nothing. Not hypothetical: it's the bug this
 * migration was written for, where the only server evidence was "no 404s
 * in the nginx log" — worthless, since `access_log off` was set on the
 * very location the 404 would have been in.
 *
 * So: one small table, written by `POST /api/client-errors`, holding what
 * the browser knows when something throws. A count surfaces on
 * `/api/health`, turning "the app was blank this morning" into a row with
 * a stack trace and a build hash.
 *
 * WHAT IS DELIBERATELY NOT IN HERE: no user id, no IP, no session id, no
 * page props. This is a single-user app behind session auth, so a user
 * column would identify exactly one person and buy nothing, and an IP
 * column would be the only place in the schema storing one. `url` and
 * `user_agent` are the whole identifying surface — which screen and which
 * browser build are the first two questions asked of any client crash.
 *
 * BOUNDED COLUMNS, ON PURPOSE. Every string here has a length, and
 * ClientErrorController truncates to it before writing rather than
 * letting Postgres refuse the row — a crash reporter whose own insert
 * throws hides the crash, and the pathological input is ordinary, not
 * adversarial: a minified stack from a 338KB bundle can be tens of
 * kilobytes, a data: URL in a message can be a megabyte. `stack` is
 * `text`, capped in PHP at 4KB, because it's the one field where more is
 * genuinely better.
 *
 * `build` is the Vite manifest hash — the same string `/sw.js` carries as
 * its cache version. It makes a report answerable: an error from a build
 * replaced three deploys ago is a stale tab, not a live bug, and without
 * this column the two look identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_errors', function (Blueprint $table): void {
            $table->id();

            /*
             * Which handler caught it: `error` (window.onerror), `rejection`
             * (unhandledrejection) or `vue` (app.config.errorHandler). Worth
             * keeping separate — an unhandled rejection is usually a failed
             * fetch or a dynamic import of a missing chunk, while a `vue`
             * error is a component that threw during render and took the
             * screen with it.
             */
            $table->string('kind', 32);

            $table->string('message', 1000);

            // The script URL and position window.onerror hands over. Null
            // for rejections and Vue's handler, which have neither.
            $table->string('source', 1000)->nullable();
            $table->integer('line')->nullable();
            $table->integer('col')->nullable();

            $table->text('stack')->nullable();

            // The page it happened on, and what was rendering it.
            $table->string('url', 2000)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Vite manifest hash — see the note above.
            $table->string('build', 64)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The only query this table is asked: "what's come in lately".
            // Descending since every reader wants the newest first;
            // written down even though Postgres can walk the index either
            // way, so the intent survives.
            $table->index(['created_at'], 'client_errors_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_errors');
    }
};
