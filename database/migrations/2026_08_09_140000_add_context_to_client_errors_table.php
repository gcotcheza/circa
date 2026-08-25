<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Two columns, because three identical rows could not answer one question.
 *
 * WHAT THE TABLE COULD NOT SAY. The first fault this table caught arrived
 * three times in a morning as:
 *
 *     kind    | rejection
 *     message | undefined is not an object (evaluating 'e.toString')
 *     source  | (null)
 *     line    | (null)
 *     url     | https://health.example.com/?date=2026-08-09
 *
 * Every field was filled in correctly and the row still didn't say what
 * went wrong: it was an Inertia response with an unusable body, and the
 * facts that would have identified it on sight (`200`, `application/json`,
 * `0 bytes`) were known in the browser at the moment of failure and had
 * nowhere to go. These two columns are that nowhere.
 *
 * `component` — WHICH SCREEN. The Inertia page component on show: `Day`,
 * `Trends`, `Stress`. `url` carries the address, but an address isn't a
 * component — `/` is `Day`, and a report mid-navigation carries the
 * address it was LEAVING. Makes a minified stack immediately narrowable
 * for one string.
 *
 * `context` — WHY, IN THE REPORTER'S OWN WORDS. A short JSON object of
 * facts about the failure: the status and content type of a response that
 * couldn't be parsed, its length, whether a retry was already spent, what
 * sort of value was thrown (`undefined` vs `TypeError` are different
 * mornings).
 *
 * TEXT, NOT `json` — THE REASON IS THE WHOLE POINT OF THIS TABLE. The
 * client truncates this field to fit the column; truncated JSON is
 * invalid JSON, and a Postgres `json` column throws on invalid JSON —
 * from the INSERT inside the one endpoint whose entire contract is that
 * it cannot fail. A crash reporter that 500s on a malformed report is
 * worse than none, because it looks like one. `select context from
 * client_errors` reads identically either way.
 *
 * Both nullable, neither indexed: written by a browser already in
 * trouble, read by one person with `order by id desc`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_errors', function (Blueprint $table): void {
            // Inertia component names: one PascalCase word, occasionally
            // `Folder/Name`. 64 is generous and bounded, like every string here.
            $table->string('component', 64)->nullable()->after('build');

            $table->string('context', 1000)->nullable()->after('component');
        });
    }

    public function down(): void
    {
        Schema::table('client_errors', function (Blueprint $table): void {
            $table->dropColumn(['component', 'context']);
        });
    }
};
