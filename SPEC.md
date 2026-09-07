# Health Tracker — Build Spec v2

Refined 2026-08-07 from the original Claude-chat handoff. All factual claims below were
verified against live sources on this date; corrections from v1 are baked in silently.

Personal calorie/protein/weight tracking PWA. Single user, replaces a paid app.
Deployed on a small VPS alongside a handful of other self-hosted apps.

Design rationale that used to live in long code comments is extracted to
`docs/rationale-app.md`, `docs/rationale-data.md` and `docs/rationale-frontend.md`;
in-code `See docs/... §` pointers reference it. Working conventions: `CLAUDE.md`.

## Stack (decided)
- **Laravel 13** (`laravel/framework: ^13.0`) on **PHP 8.5** — latest stable of both;
  PHP 8.5 is already the runtime of another app on this host. (*Not* Laravel 11 as v1 said —
  L11 security support ended 2026-03-12 and L12's bug-fix window closes 2026-08-13.)
- **Inertia + Vue 3** (latest; no separate API layer for the UI)
- **Postgres 18** (latest stable) — own `postgres:18-alpine` container; same
  one-DB-container-per-app pattern as the other apps on the host (they're on 16 — no need to match)
- **Redis (latest) + Laravel Horizon** for queues
- **Docker Compose** on the existing VPS (user-confirmed). Containers run **non-root from
  day one** — don't inherit the root-container debt the other three apps still carry.
- **Hostname: `health.example.com`** (own origin). The PWA needs its own service-worker
  scope and storage; the example.com shared-origin storage collisions between Scribly and
  Reflection are exactly what this avoids. Proxied through Cloudflare like everything else.
- Vision: **Anthropic API, `claude-opus-5`** with structured outputs
  (`output_config.format` json_schema / `messages.parse()`). At 3–5 photos/day this is
  roughly $0.03/photo ≈ a few dollars/month — model cost is not a design constraint.
  Uses the existing Anthropic account (Reflection already calls the API from this host);
  create a **separate API key** for this app so usage stays attributable per project.
- PWA: installable, camera capture, offline-tolerant logging (constraints in § iOS PWA).

## Data ingestion

### Source app — Health Auto Export (verified 2026-08-07)
Actively maintained (v9.0.15 shipped Aug 2026). **Correct pricing:** REST API automations
require **Premium** — $1.99/mo, $6.99/yr, or **$24.99 one-time lifetime** (recommended).
The $2.99 "Basic" unlock is manual export only and will NOT run automations.
"API Export format version 2" is still the current format.

### Cloudflare posture (changes v1's approach)
The v1 "silent failures against Cloudflare edge certs" gotcha is **undocumented anywhere**
(vendor help center, GitHub, troubleshooting docs — nothing). The *real*, documented
constraint is Cloudflare's free-plan **100 MB/request cap (HTTP 413)** vs HAE's warning
that payloads can reach several hundred MB.

**Decision: stay behind Cloudflare; keep the origin firewall locked to CF IPs.**
- Steady state (hourly, since-last-sync) payloads are small — nowhere near 100 MB.
- **Batch Requests: ON** in HAE (splits large bodies across multiple POSTs).
- Historical backfill: chunked manual date-range exports, not one giant dump.
- Documented fallback, only if real-world silent failures appear: a dedicated ingest
  port with a plain Let's Encrypt cert, opened outside the CF lockdown. Not built up front.
- nginx `client_max_body_size` / PHP `post_max_size` ≈ 100 MB (matching the CF ceiling).

### Step 0 — capture real payloads before designing anything (new)
Ship a throwaway `POST /api/ingest` that authenticates (`X-API-Key` shared secret),
writes `raw_ingest_payloads (id, headers jsonb, body jsonb, received_at)`, returns 202.
Run it against the phone for a few days; derive the final schema from real bytes.
**Keep this table forever** — it is the replay source for reprocessing and backfills,
and it starts the 14-day TDEE data clock on day one.

### Ingest pipeline (steady state)
- Validate → persist raw → dispatch to queue → 202 immediately.
  (Step 2: `ParseRawPayload` on the redis queue, worked by the `horizon` compose
  service. The dispatch is wrapped — a queue outage must not turn a successfully
  banked payload into a 500 that makes the phone re-send it.)
- `ingest_runs`: **append-only log**, keyed `(session_id, sha256(raw_body))`, with
  automation-id/aggregation/period/row-count/status. Never a rejection gate (HAE's
  `session-id` semantics are ambiguous; batches of one run may share it). Alert on
  runs that produced 0 rows — server-side gap detection beats trusting the phone.
  Step 2 correction: `row_count` is rows **represented**, not rows changed — a
  replay changes nothing and must not therefore read as a zero-row gap. `status
  empty` then means "this payload carried no datapoints", which is the real
  silence worth alerting on. The sha is taken over the body as Postgres returns
  it from `jsonb`, not over the original request bytes (jsonb normalises key
  order and whitespace on the way in).
- Row-level idempotent upsert is the sole dedup mechanism.
- **Per-datapoint failures are skipped, not fatal** (step 2). The phone exports
  hourly and unattended; one unrecognised sample must not stop a week of history
  from landing. Skipped datapoints are counted, logged, and summarised onto
  `ingest_runs.error` while the run still completes. The exception is a KNOWN
  metric arriving in a unit with no defined conversion — that fails the run,
  because storing the number would silently corrupt a column whose name promises
  otherwise.
- `php artisan ingest:replay {--from-id=} {--to-id=} {--dry-run}` reprocesses
  banked payloads through the same code path, synchronously. Running it twice is
  the idempotence proof: the second pass inserts nothing and the derived tables
  are byte-identical.

### Bucket alignment is NOT always hourly (observed 2026-08-07, step 2)
The first payloads were a historical backlog and every datapoint was
hour-aligned. The first genuinely incremental "Since Last Sync" export was not:
its buckets start at the **previous sync instant** (`16:30:53`, `17:30:53`), one
hour wide. Buckets are therefore stored exactly as HAE reports them — snapping to
the hour would claim data happened in a window it did not, and would merge two
different quantities under `GREATEST`.

Consequence for step 3: **daily rollups cannot assume buckets tile a day.** Two
buckets may overlap (a sync-aligned one and an hour-aligned one covering some of
the same minutes), and `DISTINCT ON (metric, bucket)` does not deduplicate
overlaps — only exact bucket collisions. This has to be handled where
`daily_summaries` is built.

**Step 3 resolves it: greedy non-overlapping selection, priority-first**
(`App\Services\Rollup\BucketSelector`). Candidates for one metric on one day are
sorted by (source-priority rank, `started_at`, longer bucket first, id) and
walked; a bucket is accepted only if it overlaps nothing already accepted.
Intervals are half-open `[start, end)`, so back-to-back hourly buckets are not
overlaps and a normal fully-aligned day passes through untouched — the result is
identical to the old `DISTINCT ON` answer.

Three consequences worth stating, because they were choices:

- A partially-overlapping lower-priority bucket is rejected **whole**, never
  pro-rated into the gap it leaves. Pro-rating assumes the value is spread
  evenly across the bucket, and a 20-minute walk inside an hour is exactly where
  that is most wrong. The cost is a possible slight undercount of contested
  minutes, and it is *measured*: `coveredSeconds()` feeds the coverage flags.
- A lower-priority bucket that overlaps nothing IS used. The Watch on the
  charger from 12:00–13:00 leaves a hole the iPhone can fill; an undercount is
  not preferable to a measurement that exists. The day is still flagged.
- A bucket belongs wholly to the day its **start** falls in, matching the
  `local_date` generated column. A 23:30–00:30 bucket is not split, for the same
  even-distribution reason.

### Sleep day-records are window-truncated too (observed 2026-08-09, step 12.1)
**The third confirmed instance of the partial-window re-send family**, after (1) the
trailing partial hour re-sent larger and (2) buckets that start at the previous sync
instant rather than on the hour. Same root cause every time: *"Since Last Sync" clips
whatever it exports to the export window, and does not mark it as clipped.*

`sleep_analysis` is a **day record**, not a bucket — one row per night — and HAE clips
it as well. One night comes back on every subsequent export, each copy starting later.
Night of 2026-08-08, Apple Watch, from `raw_ingest_payloads`:

| payload | received (UTC) | sleepStart → sleepEnd (local) | totalSleep |
|---|---|---|---|
| 114 | 08:36 | 00:33 → 08:02 | 7.02 h |
| 115 | 10:54 | 04:16 → 08:02 | 3.64 h |
| 116 | 14:38 | 06:41 → 08:02 | 1.24 h |
| 117, 118 | 18:37 | 06:41 → 08:02 | 1.24 h (byte-identical resends) |

Every row is an honest answer to "what happened since the last sync". `sleep_end` never
moves; `sleep_start` walks forward; every stage minute shrinks with it (`deep` reaching
0). The v1 upsert was last-writer-wins, so the stored night walked 7.02 → 3.64 → 1.24
and Saturday's card read **"1h 14m"**.

**Rule (`SleepSessionWriter`, mirrored in `SleepRow::supersedes()` for the in-payload
collapse): an incoming night replaces the stored one for the same
`(night_date, source_id)` only if it is no less complete on either axis.**

1. **Volume** — `total_sleep_minutes >= ` the stored value. Clipping only removes sleep,
   so a smaller total is by construction a narrower view of the same night. `>=` not
   `>` on purpose: Apple re-scores a night's stages hours later and a same-total
   restatement must still land.
2. **Coverage** — the incoming sleep window must not be a strict sub-interval of the
   stored one (`sleep_start` no earlier **and** `sleep_end` no later, windows differing).
   That is precisely the shape of a clipped export, vetoed outright. The veto is what
   stops a truncation whose total happens to tie from slipping past rule 1.

Anything else — wider window, later window, larger total, same window with different
stages — is a real restatement and is taken, so a night can always grow and always be
re-scored but can never shrink. NULL `total_sleep_minutes` ranks below every real
duration (`coalesce(…, -1)`): a record that does not say how much sleep it measured
cannot displace one that does. A missing boundary makes truncation unprovable rather
than true (`coalesce(…, false)`), so rule 1 governs alone. On top of the predicate the
`DO UPDATE` keeps its whole-row `IS DISTINCT FROM` guard, so an identical resend is
still a provable no-op — a refused truncation and a byte-identical resend both count
`unchanged`.

Repaired by `php artisan ingest:replay`, not by hand: this is exactly the case
`raw_ingest_payloads` is kept forever for.

**Why not "widest window wins" instead?** Because coverage alone cannot see a re-score,
and volume alone cannot see an equal-total clip. Both axes are needed, and neither is
sufficient.

### Metrics schema (corrected — v1's single table can't hold real HAE data)
- `health_metrics` — scalars only: metric, value, unit, `aggregation`
  (instant|sum|avg|min|max), `period` (**from the `automation-aggregation` header** —
  step 1 correction: that header carries the bucket width, observed `Hours`;
  `automation-period` carries the export *window* mode, observed `Since Last Sync`,
  and is logged on `ingest_runs` only), `started_at`/`ended_at` (timestamptz,
  **NOT NULL** — step 1 correction: Postgres treats NULLs as distinct inside a unique
  index, so nullable bounds silently void the key below; an instant is the zero-length
  interval `started_at == ended_at`), `source_id`, ingested_at.
  Unique key: `(metric, aggregation, period, started_at, ended_at, source_id)`.
  Heart rate lands as three rows (min/avg/max) per bucket.
- `sleep_sessions` — own table: night_date (from HAE's own date field), in_bed_start/end,
  sleep_start/end, asleep/core/deep/rem/awake minutes, source_id. Sleep is interval
  data; do not shred it into scalars. (Observed: HAE reports the stages in *hours*,
  and `inBed`/`asleep` come back as 0 from the Watch — columns kept, nullable.)
- `sources` — stable id + raw device string (device renames must not fork history),
  per-metric priority config: **Watch > iPhone > scale**. Real source strings carry
  U+2019 and U+00A0 (`Demo’s Apple Watch`), arrive pipe-joined when HAE summarises
  across devices (`Watch|iphone`), and are sometimes the empty string
  (every `apple_stand_hour` datapoint) — so priority keys on a normalised
  `device_kind`, never on the raw string.
  Step 2 correction: identity is the normalised **slug**, not `raw_name`. The
  same device reaches us spelled several ways (ASCII vs curly apostrophe, NBSP vs
  space, `A|B` vs `B | A` — both composite orderings are in the captured data),
  and each spelling becoming its own source would fork exactly the history this
  table exists to keep whole. Composite parts are trimmed and sorted before
  slugging; `raw_name` keeps the first spelling seen so the row still round-trips
  to a real payload. 10 raw strings → 9 sources.

### Upsert semantics (the direction v1 left unspecified)
The trailing hour of every export is partial and legitimately re-sent larger later:
- Cumulative metrics (steps, distance, active/resting energy):
  `ON CONFLICT DO UPDATE SET value = GREATEST(excluded.value, value)`
- Instant samples (weight, body fat %, lean body mass, BMI) and avg/min/max:
  last-writer-wins by `ingested_at`.

The full cumulative list, decided in step 2 from the 33-metric inventory
(`MetricCatalog::CUMULATIVE`): `step_count`, `walking_running_distance`,
`cycling_distance`, `flights_climbed`, `active_energy`, `basal_energy_burned`,
`apple_exercise_time`, `apple_stand_time`, `apple_stand_hour`, `handwashing`,
`mindful_minutes`. The membership test is "would summing two halves of the bucket
give the bucket's value?". An unclassified new metric defaults to last-writer-wins,
never to `GREATEST` — wrong-but-inert beats wrong-and-growing.

Sleep is the third direction, and it is a whole-record rule rather than a scalar
one — see "Sleep day-records are window-truncated too" above.

All three upserts carry a `WHERE` on the `DO UPDATE`, so a conflict that would change
nothing writes nothing at all. That is what makes idempotence provable rather
than merely arithmetically indistinguishable from a no-op.

### Read-side rule (prevents the double-count bug)
iPhone and Watch both report steps/energy for the same bucket as separate legitimate
rows. **Rollups pick ONE source per (metric, bucket) via priority (`DISTINCT ON`) —
never `SUM` across sources.** Summing roughly doubles expenditure and poisons TDEE.

### Timezones
Store `timestamptz` (UTC) + original device offset. One configured app timezone; a
stored generated column `local_date = (recorded_at AT TIME ZONE app_tz)::date`, indexed,
is the **only** join key to `daily_summaries`. Sleep keys on HAE's night date directly.

## Food logging
Four entry paths, all producing `meal_items` rows:

1. **Photo** — capture in PWA **or pick one from the library** → upload → Claude
   vision (structured outputs) → proposed items → user confirms/edits. Nothing is
   logged without a tap.
2. **Barcode** — **`zxing-wasm` (via the `barcode-detector` polyfill wrapper, same
   author)**, lazy-loaded on first scan (~1 MB wasm / ~400–500 KB brotli). Native
   `BarcodeDetector` is Chromium-only and non-functional on iOS through 26.5 (WebKit
   bug 281848 open) — it is at most a feature-detected fast path, never the plan.
   Measured in step 4: the wasm is **1,065 KB** (453 KB gzip) in its own asset, the
   Emscripten glue **43 KB** (15 KB gzip) in its own chunk, and the entry bundle grew
   **4.7 KB** — the scanner components are `defineAsyncComponent`-ed for exactly that
   reason. The binary is served from our own origin, not jsDelivr: `locateFile` is
   overridden to a Vite-emitted asset, so a kitchen-counter scan does not depend on a
   third-party CDN. Reader-only build (`zxing_reader.wasm`); formats restricted to
   EAN-13 / EAN-8 / UPC-A / UPC-E, because every extra symbology is another set of
   hypotheses tested per frame.
3. **Manual / recent** — text entry + one-tap re-log.
4. **Describe it** (step 9) — type the item names, leave the numbers blank, and the
   same model estimates portions and nutrition as ranges from the words. Same
   schema, same `analyzing → proposed → confirmed` state machine, same review
   sheet as the photo path — the evidence is a sentence rather than a picture.

**Step 3 decisions for the manual path.** Two entry modes reduce to one stored
shape, converted server-side (`App\Http\Requests\MealRequest`) so the arithmetic
has a single definition and an offline queue replaying a stale payload cannot
run last version's:

- *quick* — a name and a kcal figure (optional macros). Stored as **100 g at
  that many kcal/100 g**: nobody weighs a cappuccino, and at a 100 g basis the
  density *is* the absolute, so the modes converge with no special case.
- *per 100 g* — a density and a weight; absolutes derived as the schema intends.

Both are **zero-width** (min == max on every density and portion) — the honest
claim about a number a human typed. Vision (step 5) is what produces genuinely
wide items, and the quadrature band already handles a mix.

Manual meals are `confirmed` on save, and their items get `confirmed_at`:
"the model proposes, the user confirms" is about the vision path, and a human
typing the food *is* the confirmation. The one exception is **Save & estimate**
(step 9), where the numbers are about to be a model's: that meal is born
`analyzing` with its items unconfirmed, so it never counts toward a day and then
stops counting again while the model thinks.

**Step 9 reverses one step-3 decision: the numbers are OPTIONAL.** Step 3
required a kcal figure on every item. Living with the app showed what that
costs — the moment a meal cannot be written down without its calories, the app
stops being somewhere you write down what you ate. You know you had a chicken
curry; you do not know what was in it, and the only two options the old rule
left were to invent a number or to log nothing. An invented 600 kcal is
indistinguishable from a measured one the moment it reaches `daily_summaries`,
which is the failure this whole app is built around not committing.

So: the item **name** is required, because a row nobody can read back records
nothing. The portion, the energy and all three macros are optional. An item with
only a name is legal, stores as 0 (the density columns are NOT NULL), and
contributes 0 to the day. The plausibility ceilings are unchanged — a *wrong*
number is the failure worth preventing, and a *blank* one is not.

`meals.eaten_at` is written in **UTC**. Eloquent serialises a Carbon with the
connection's offset-less date format, so handing a zone-aware instant straight
to a `timestamptz` column lands it at the wrong moment — and `local_date` is
generated from that column, so around midnight it lands on the wrong *day*.

### Nutrition data model (fixes "editable portions")
- `meal_items` stores **per-100g min/max densities** (kcal, protein, carbs, fat) plus
  `portion_g_min/max`; absolute values are derived. Editing 150 g → 220 g recomputes.
- `food_products` — local Open Food Facts cache: barcode PK, name, per-100g nutrients,
  `raw jsonb`, `fetched_at`. `meal_items.food_product_id` references it; a re-log never
  re-hits the network; a bad OFF entry is correctable locally.
- **Open Food Facts** (verified live): v3 endpoint
  `GET https://world.openfoodfacts.org/api/v3/product/{barcode}.json`, no auth for reads,
  15 req/min/IP. Mandatory User-Agent: `HealthTracker/1.0 (user@example.com)`.

**Step 4 decisions for the barcode path.** Everything below was forced by what
OFF actually returns (checked against the live v3 response for `3017624010701`,
which has 251 product fields) rather than by its documentation.

*Energy parsing, in this order* — each fallback exists because the one above it
is genuinely absent from real entries, and reading the wrong one is wrong by a
factor of 4.184 rather than visibly broken:

| Field | Treatment |
| --- | --- |
| `energy-kcal_100g` | Used verbatim. It is what the contributor typed. |
| `energy-kj_100g` | ÷ 4.184. |
| `energy_100g` | The unit is in the **sibling** `energy_unit` key and is kJ far more often than kcal. Taken as kcal only when that key says so; otherwise converted. |

Macros are `proteins_100g` / `carbohydrates_100g` / `fat_100g`, each independently
nullable. **A missing macro is NULL, never 0** — the column, the API response and
the UI all carry the difference, because a fabricated zero would flow straight
into the day's total. Numeric strings are accepted (OFF sends them); anything
non-finite, negative, above **900 kcal/100 g** (denser than pure fat) or above
**100 g of a macro per 100 g** is a contributor typo and is stored NULL with the
original still in `raw`.

*Three outcomes, three status codes.* `422 invalid_barcode` / `404 not_found` /
`503 unavailable`. Collapsing the last two is the failure worth avoiding: it
sends the user off to hand-type nutrition for a product the cache would have had
seconds later. A 429 from OFF is `unavailable`, not `not_found`.

*Nothing in the cache expires.* `fetched_at` makes staleness visible; only an
explicit `?refresh=1` re-reads a row, and a refresh that cannot reach OFF returns
the cached row rather than an error — the user asked for a newer answer, not for
the old one to be taken away. An outage is never written to the cache.

*UPC-E is expanded to UPC-A* before lookup and storage: it is a compressed
printing of the same number (its check digit is computed over the expansion), so
the small barcode on a can and the big one on the multipack reach one cache row.
12-digit UPC-A is **not** zero-padded to 13 — OFF serves both spellings and
rewriting the user's scan buys nothing.

*Scanned items are zero-width.* A manufacturer's declaration is point data; all
the uncertainty in a barcode item is in the grams. `meal_items.food_product_id`
records where the numbers came from — the values are pre-filled and **editable**,
so correcting a bad OFF entry locally does not have to fight the provenance link.

*The lookup endpoint is `GET /api/products/{barcode}`, registered in
`routes/web.php`* (session auth, `throttle:products` at 30/min) rather than in
the session-less `api` group that Health Auto Export posts to. The `/api` path
prefix is kept only so `bootstrap/app.php` renders its exceptions as JSON: a
guest must get 401, not a 302 that `fetch()` would follow and hand back as a
login page.

### Vision pipeline (persisted state, not fire-and-forget)
- `meals.status`: `draft → analyzing → proposed → confirmed | failed`, driven by the job.
- `vision_requests` audit table: meal_id, **meal_photo_id** (which plate — step 10),
  model, prompt_version, image_sha256,
  raw_response jsonb, input/output tokens, latency_ms, status, error. Keyed on a
  client-generated **Idempotency-Key** at capture (double-tap ≠ double-pay; enables
  offline retry). Prompt changes become evaluable against history.
- Downscale to ~1024 px long edge + strip EXIF before sending. Photos on a dedicated
  disk with retention (original 90 days, thumbnail kept) — `meal_items` is the record.
- A meal has SEVERAL photos (`meal_photos`), one per course, each with its own
  analysis and its own confirm. See "A meal is a series of plates" below; the
  status bullet above describes a single entry's progress, not the meal's.
- Response schema: as v1 (items[] with name, portion/kcal/macros as min–max ranges,
  confidence; notes). **Ranges everywhere: a best estimate may lead, but the range
  is always beside it, never dropped.** Prompt the user to include a scale
  reference in frame.

**Step 5 decisions for the photo path.** Everything below was forced by the
schema that already existed, by what the model can actually answer from a
photograph, or by the one device this runs on.

*The model is asked for ABSOLUTES; the table stores DENSITIES.* `meal_items`
keeps per-100 g ranges plus a portion so that editing 150 g → 220 g recomputes
the item — but "how much protein is on this plate" is a question a photograph
can answer and "what is the protein density of this stew" is not. So the schema
asks for `portion_g_min/max` and per-item absolute `kcal`/macro ranges, and
`App\Services\Vision\ProposedItemMapper` divides:

| | |
| --- | --- |
| `density_min` | `100 × kcal_min / portion_min` |
| `density_max` | `100 × kcal_max / portion_max` |

which is the exact inverse of how `IntakeCalculator` reads them back, so the
model's band round-trips to the calorie. The prompt asks for consistent ends
(`kcal_min` is the value **at** `portion_g_min`) precisely so the division lands
the right way round; a self-contradictory answer — "100–200 g, 150–160 kcal" —
would invert, so the pair is **sorted** rather than trusted and the band widens
instead. Densities are clamped to the same ceilings the barcode path uses
(900 kcal/100 g, 100 g of a macro per 100 g).

*The review form speaks in absolutes too*, and posts through the same mapper:
nobody reviews a photograph in kcal per 100 g, and confirming an untouched
proposal is then provably a no-op on the numbers. It is a **separate request
class** from `MealRequest` — routing a proposal through the manual form would
flatten every range to a point estimate at the exact moment the user agreed the
range was right.

*One retry, and it is a button.* `AnalyzeMealPhoto` is `tries = 1`; the SDK
retries once for 429/5xx/connection errors, which covers everything a retry can
fix. A refusal, an unreadable photo or an unparseable answer will fail the same
way in thirty seconds, so the meal goes to `failed` carrying the reason and the
user decides. "Re-analyze" creates a **new** `vision_requests` row with a new
idempotency key — two rows against one image is exactly the comparison the audit
table exists for.

*An empty answer is a success.* A photo of a chair reaches `proposed` with zero
items and a note explaining what it shows. Calling that `failed` would tell the
user to retry something that cannot succeed.

*The server re-encodes.* The browser downscales to ~1024 px through a canvas —
it must, for bandwidth and because iOS runs out of memory decoding a full-size
photo twice, and it is what turns HEIC into something GD can read. The server
then does it **again**, which is the difference between a request and a
guarantee: EXIF (GPS coordinates of a kitchen) cannot survive a GD re-encode,
and a client whose canvas step silently failed cannot smuggle any through. The
`image_sha256` is of those canonical bytes.

*Retention repoints rather than nulls.* `photos:prune` deletes originals older
than `config('health.vision.photo_retention_days')` (90) and rewrites
`meal_photos.path` (was `meals.photo_path` — step 10) to the kept 256 px
thumbnail. Nulling — sketched in the step-1
migration — would orphan the thumbnail; the `originals/` vs `thumbs/` prefix is
also how re-analysis knows to refuse rather than ask the model to identify a
dinner from a postage stamp. The scheduler is a **compose service** running
`schedule:work`, not a host crontab: a crontab would run as whoever owns it and
would be missing from a fresh `docker compose up`.

*Capture is a file input, not `getUserMedia`.* The barcode scanner needs a live
stream because it decodes frames; this needs one still. `<input type="file"
accept="image/*" capture="environment">` opens Apple's own camera — already
trusted, already permitted, with tap-to-focus — instead of prompting for camera
access before the user has framed the shot, which is what a PWA that re-prompts
on every launch would do.

*Step 9: TWO inputs, because `capture` is a one-way door.* `capture="environment"`
does not mean "prefer the camera", it means "the camera, and nothing else" — iOS
opens the rear camera immediately and the photo library is not reachable from
that sheet at all. Correct for the plate in front of you; useless for the one
photographed at lunch and logged on the train home, which was the first thing
real-world use asked for. There is no attribute value offering both, so there
are two hidden inputs — one with the attribute, one without — behind two buttons
that say which is which ("Take photo" / "Choose photo"). They share one handler,
so downscale, EXIF strip, upload and analysis are one path from the first line.

*A past-day photo was already correct, and is now pinned.* `MealPhotoRequest`
takes `date` + `time` and the capture sheet posts the day being viewed, so
`eaten_at` (and therefore the generated `local_date`) already followed the
selected day rather than the clock. Picking from the library made that
load-bearing rather than incidental, so `tests/Feature/Vision/PastDayPhotoTest`
now asserts it end to end — upload, analysis and confirm — including the
near-midnight case a missing UTC conversion would break. The sheet also says
"Logging to <day>, not today" when the day is not today, because a camera sheet
that looks identical on every day is one a user can reasonably read as "now".

### Text estimation — "describe it" (step 9)

A TEXT-ONLY variant of the pipeline above. Everything downstream of the model is
literally the same code: `ProposedItem`, `ProposedItemMapper`, `MemoryPrefill`,
`ProposalWriter`, the `analyzing → proposed → confirmed | failed` state machine,
and the review sheet (renamed `PhotoReview.vue` → `ProposalReview.vue`, which was
never photo-specific — it reviews a meal in `proposed`).

| Decision | As implemented |
| --- | --- |
| Prompt | `PromptV1Text`, `prompt_version` **`v1-text`**. A separate class and a separate version, because `vision_requests.prompt_version` is what makes a prompt change evaluable and two prompts sharing one version string destroys that as thoroughly as editing a prompt in place. The photo prompt and the text prompt then move independently. |
| Schema | **Shared verbatim** with `PromptV1`. It is the contract everything downstream is built on; a second schema differing by one field name would be a second parser, a second mapper and a second review screen. |
| Evidence | The meal as typed — item names, any portions given, any values given, meal type, time, notes — rendered by `TypedMeal::describe()`. Every line states what was **GIVEN** and what was **NOT GIVEN**, in those words: "no figure" and "0 kcal" are the difference between a question and an answer, and the model has to be told which it is looking at in a form it cannot misread as data. |
| Block order | Description first, instruction second — the same evidence-then-question rule as the photo path. Two blocks rather than one concatenated string, so the user's words never become part of the instruction text. |
| Model / tokens | `claude-opus-5`, `maxTokens` 4096, same structured-output call, same timeouts, same one SDK retry, same `tries = 1`, same `throttle:vision`. |
| Audit row | The same `vision_requests` row, with a new **`request_kind`** column (`photo` \| `text`). *Not* inferred from a null `image_sha256`: `reanalyze()` also claims its row with a null sha256, so nullability cannot distinguish the two — which would break precisely the query the table exists for. A second additive column, `input_payload jsonb`, stores the typed meal (and, from step 12, the photo hint): `prompt_version` + `raw_response` only make a prompt change replayable if the INPUT can be replayed, and for a photo that is `image_sha256` while for text it lives nowhere else once the proposal has replaced the items. |
| Preserved values | **A number the user typed is never changed** — not by the model, not by memory, not by both. The prompt asks for it twice; `TypedMeal::preserve()` guarantees it, running LAST over the density rows and recomputing `density = 100 × absolute / portion` at each end so the derived absolute equals what was typed whatever portion survived. A typed *density* (per-100 g mode) is written straight in, portion-independently: that is a claim about the food, not about the plate. |
| Nothing is lost | `ProposalWriter` replaces a meal's items wholesale, so an item the model failed to mention would be **silently deleted** — and unlike a wrong number there would be nothing on screen for the confirmation step to catch. `TypedMeal::complete()` appends any typed item the answer did not match, as the zero-width row an ordinary save would have written. |
| Order of claims | model → mapper → **memory** → **user**. Memory beats the model (it summarises what this person actually confirmed); the user beats memory (memory is about the past, they are telling you about tonight). `memory_adjusted` is cleared on any item the user's values were written to, because the "from history" badge would then be a lie. |
| Asking again | `POST /api/meals/{uuid}/estimate` with a NEW idempotency key — same semantics as re-analysing a photo. While the proposal is still unconfirmed it re-uses the ORIGINAL `input_payload` rather than reading the stored rows: after one estimate a preserved 150 g and an estimated 300 g look identical in the table, and deriving from rows would hand the model its own previous answer as though a human had typed it. Once the user confirms, the rows *are* the user's and become the source. |
| Offline | `POST /api/meals/estimate` is an ordinary queued write (`KIND.mealEstimate`), idempotent on both keys. The estimate happens whenever it lands, because the job runs server-side; the phone does not need to be awake. Asking for an estimate on an **already-saved** meal is deliberately *not* queued — it needs the meal to be on the server first, and an estimate that silently arrives tomorrow is not what the button offered. |

#### Corrections after the first week of real use (step 9.1)

Three defects, all found on one breakfast: a single text box reading *"Full
kwark 250g with 1 tablespoons of honey, 2 tablespoon of chia seed, 1 tablespoon
of protein powder and 1 handful if frozen wild blueberries"*, every number left
blank.

**1. Confirm answered "The server refused this (405)" — while succeeding.**
`MealProposalController` redirects to the day, and a redirect after a write is a
**302**. Per the Fetch standard only a POST is downgraded to GET across a
301/302; every other method is preserved — so a `PUT` that gets a 302 is
**re-issued as a PUT** against the target. Inertia's middleware already rewrites
302 → 303 for `PUT`/`PATCH`/`DELETE`, but only for requests carrying
`X-Inertia`, and the offline queue replays writes with a hand-rolled `fetch()`
that deliberately does not. So the browser sent `PUT /?date=…`, `/` is
registered GET-only, and the 405 was the *second* request. Fixed in
`HandleInertiaRequests::handle()`, which applies the 303 to **every** caller: it
is correct for all of them, and "only for clients that announce themselves" was
never a property worth having. Pinned from both sides — the verb and URI are
read out of `ProposalReview.vue` and matched against the router, the response is
asserted to be 303, the wrong verb is asserted to 405, and `PUT /` is asserted
to 405 so nobody "fixes" it by widening the day route.

**2. Blank ≠ typed zero.** `meal_items` densities are NOT NULL, so a blank is
stored as `0.000`; `TypedItem::toRow()` also writes exactly **100 g** as the
*basis* of an absolute-mode item, because at 100 g the density is the absolute
value. Neither is a claim, and both were being read back as one — the review
sheet showed the described line at "100–100 g, 0–0 kcal" as though the user had
typed them, and a second estimate described it to the model as *"Portion: 100 g
(GIVEN — use exactly this)"*. Only figures recorded in
`vision_requests.input_payload` are the user's. Where a payload does not exist
(the estimate button on an already-saved meal),
`MealEstimateController::typedItemFrom()` reconstructs one under the same three
rules the sheet itself uses: **a zero density is blank, a range is blank, and
100 g is not a weight** (`MealSheet.vue`'s `orBlank()` / `perHundred`). One
answer to "what did the user type", or the boxes on screen and the description
sent to the model describe different meals.

**3. Decomposition replaces, it does not duplicate.** A user item **with** typed
values is data: restored verbatim if the answer did not mention it, never
changed. A user item **without** typed values is a *description*, and the
proposal is its answer — it may be renamed, corrected or split, and it must not
survive beside its own components. The test for "was it answered" is whether the
model returned anything that was **not** already one of the user's own lines: if
it did, those items are the decomposition; if it did not, nothing replaced the
line and it is restored, because silently deleting a food the user listed is the
failure `TypedMeal::complete()` exists to prevent.

**4. The model's note is not the user's notes.** `ProposalWriter::propose()` was
writing the model's working note into `meals.notes` — harmless on the photo path
where the user types nothing, and on the text path it **overwrote what they
wrote** and then offered *"Split the single line into its five components; …"*
back in an editable Notes box, so Confirm stored the model's reasoning as their
words. Two speakers, two columns: `notes` is the user's and only a request they
sent changes it; **`model_notes`** is the model's account of its own answer,
never editable, replaced by each proposal, and rendered as attributed commentary
in the review sheet and on the meal card. The migration moves an existing note
across only where the stored `raw_response` proves the model wrote it.

**Repairing what was already written.** `php artisan vision:reproject {uuid}`
rebuilds a stored proposal from `raw_response` + `input_payload` under the
corrected rules, with **no API call** — which is what those two columns were
added for. It does not re-run `MemoryPrefill` (memory is a live claim, and after
confirmation a meal is part of the history it would be re-read from), does not
change whether a meal is confirmed, and refuses to alter numbers on a confirmed
meal without `--force`.

#### A meal is a series of plates (step 10)

The first week of real use asked for a second photograph of the same dinner —
the second helping, the pudding that arrived twenty minutes after the main
course had already been logged. `meals.photo_path` could not express it, and
neither could the state machine sitting on top of it.

**`meal_photos` is a table of ENTRIES, not attachments.** A row is one plate,
photographed and analysed on its own: `meal_id`, a client-generated `client_id`,
`path`, `thumb_path`, `sha256`, `position`, `model_notes`. `position` is the
order the courses arrived in and is what "photo 2" means everywhere afterwards;
removals renumber, so it stays contiguous. A JSON column on `meals` was the
cheap alternative and is wrong three ways: retention walks photographs (a row
scan, versus rewriting a document to repoint one element), a photo has to be
individually addressable by the serving route and the review sheet, and two
other tables now point at it.

`meals.photo_path` is kept and **deprecated** — nothing reads it. Dropping it in
the same migration that moved its contents would have made the move
irreversible for the sake of one nullable column; as written, `down()` drops the
new table and the old rows are untouched. Dropping it is a later cleanup.

**Three client-generated ids, three jobs.** `uuid` is the MEAL and is
deliberately the same for every plate of one dinner. `client_id` is the PHOTO —
three plates flushing out of the offline queue are three uploads to one meal,
and the meal uuid can no longer tell the second plate from the first plate
arriving twice. `idempotency_key` is the ANALYSIS, unchanged.

**One image per call, and each plate is analysed on arrival.** The API takes
several images in one message; this deliberately does not use that. A combined
answer cannot be confirmed in pieces (the dessert arrives after the main course
is already counted), cannot be re-analysed in pieces ("the dessert is wrong"
would mean paying for the main course again), and cannot be audited in pieces —
a `vision_requests` row covering three photographs answers neither of the
questions the table exists for. So: one plate, one row, one call, one
separately-confirmable proposal. `vision_requests.meal_photo_id` says which.

**Prompt `v2-photo`** (superseded by `v3-photo` in step 12, which adds the
user's note; everything below still holds). Same JSON schema as v1 — the mapper,
memory pre-fill, review sheet and `vision:reproject` are the same code on all
three — so what changed
is the frame: the photograph is one COURSE rather than the whole meal, and the
already-logged item names ride in their own content block between the image and
the instruction. That block is the **double-count guard**: photograph the second
helping with the same salad still in shot and a model with no memory of the
first call lists the salad again, and Confirm appends, so the day gains a salad
nobody ate. The rule is stated in terms the photograph can settle — "list what
is visible in THIS photograph; if something already logged is visibly there
again, say so in the name" — because a user can delete a line they can see and
cannot notice one that is missing. The names are their own block, never
concatenated into the instruction, for the same reason the text path keeps the
typed meal separate: they derive from user input.

**Provenance: `meal_items.meal_photo_id`.** NULL is the common case and means
"not from a photograph" — typed, scanned, or estimated from a description. It is
what makes three operations unambiguous once a meal holds several answers at
once: re-analysing one plate discards that plate's proposal and no other,
removing a plate can offer to remove exactly its items, and confirming one plate
appends. `nullOnDelete` rather than cascade: an item with a `confirmed_at` is a
record of something a human agreed they ate, and deleting the photograph is a
statement about the photograph.

**The state machine, in two halves.**

| | |
| --- | --- |
| `meals.status` | The MEAL's commitment: `draft → analyzing → proposed → confirmed \| failed`, with one new rule — **it never goes backwards out of `confirmed`**. `Meal::scopeConfirmed()` is what the daily rollup selects on, so a dessert being analysed must not take the main course out of the day's totals. A failed analysis on a confirmed meal likewise leaves the status alone. |
| The ENTRY | Derived from the plate's own latest `vision_requests` row: `analyzing`, `failed`, `proposed` (it answered and the items are still waiting for a tap), or `settled`. This is what the review sheet renders, what the poller watches (`pending`, a count), and the half that can be outstanding on an otherwise finished meal. |

Consequences, stated because each one is a decision:

- **Confirming appends.** `ProposalWriter` is scoped to one entry; items from
  other plates, and anything typed or scanned, are never read or deleted.
- **A proposal replaces its own entry's items, confirmed or not.** A plate has
  one answer at a time, and showing the old rice beside the new chicken would be
  the same food on screen twice. What protects the user is the SCOPE, not
  `confirmed_at` — and a proposal is only ever written when an answer arrives,
  so a re-analysis that fails leaves what was confirmed exactly where it was.
- **Discarding one entry's proposal** removes that plate and its unconfirmed
  items. Its CONFIRMED items stay unless the caller asks (`remove_items`), with
  provenance nulled so they read as typed lines — which is what they now are.
  Removing the last plate of a meal nobody has committed to discards the meal,
  because an empty draft is a card that cannot be opened or confirmed.
- **A meal left with nothing pending returns to a coherent state** — `proposed`
  if unconfirmed items remain, else `draft`. A spinner nobody can clear was the
  alternative.

**The rollup gained a second filter.** `DailySummaryBuilder::confirmedItems()`
now requires `confirmed_at IS NOT NULL` as well as a confirmed meal. The meal
filter alone was sufficient while a confirmed meal could only hold confirmed
items; it would now count the dessert the moment the model answered, thirty
seconds before anybody agreed to it.

**Retention walks plates.** `photos:prune` repoints `meal_photos.path` at the
thumbnail, and cuts on the MEAL's `eaten_at` rather than the photo's
`created_at` — so a dinner's courses expire together rather than the dessert
outliving the main course by the twenty minutes between them.

**Cost.** Unchanged per photograph (~1.0–1.6k input tokens for a 1024 px JPEG,
plus the prompt), and the bill now scales with courses rather than meals: a
three-plate dinner is three calls. The double-count guard adds a handful of
tokens per call — one line per item already on the meal — and is the cheapest
part of the exchange it protects.

#### The shared plate (step 11)

The first week of real use produced a three-course Vietnamese dinner and a meal
note that read *"I only ate half of this plate, it was a shared plate"*. Notes
are inert. The day counted the whole dinner, and the only alternative on offer
was editing eleven numbers by hand.

**The model estimates the PLATE; the app applies the share.** This is the rule
the whole feature hangs off, and it is the reason the prompt did not change.
A photograph can settle what is on a plate. It cannot settle how much of it
this particular person ate — nobody else was in the frame, the other three
diners' bowls are not in shot, and the second helping looks exactly like the
first. Asking the model to guess would put an invented number in the one place
this app has always insisted on a human tap. So the prompt is untouched by the
share: it asks what is visible in THIS photograph, which is the plate, and a
note that happens to speculate about sharing is ignored rather than parsed.
(Step 12 does change the prompt, for a different question — what the food IS —
and leaves this rule exactly where it is.)

**Two levels, and the rule between them.** The review sheet grows a chip row
above the items — *All · ¾ · ½ · ⅓ · ¼ · %* — and every item gets the same
control, compact, on its own line.

| | |
| --- | --- |
| The PLATE's chip | Sets every item that has not been decided individually. Stored on `meal_photos.share_fraction`. |
| An ITEM's chip | Sets that line only, and it stops following the plate. "We shared the rolls, but the pho was all mine" is the whole reason this exists. |
| Releasing an override | Tapping an item's chip back onto the plate's own fraction. Without it an override is a one-way door, and "actually, everything here was shared equally" is unsayable. |

An override is **derived, never flagged**: an item whose `share_fraction`
differs from its plate's IS the override. A boolean beside it would be a second
place for the same fact to be wrong.

**The representation, and why the original portion is a stored column.** Three
new columns, all additive:

```
meal_items.share_fraction        decimal(5,4)  default 1.0
meal_items.portion_full_g_min    decimal(10,3) — what was on the plate
meal_items.portion_full_g_max    decimal(10,3)
meal_photos.share_fraction       decimal(5,4)  default 1.0
```

with one invariant, applied in exactly one place
(`App\Services\Meals\ConsumptionShare`, from `MealItem::creating`):

```
portion_g_* = portion_full_g_* × share_fraction
```

The cheap alternative is one column, recovering the estimate as
`portion_g / share_fraction`. It is wrong, and it fails silently. A third of a
100 g portion stores 33.333; dividing that back gives 99.999, which the next
edit scales, stores and divides again. Every change of mind shaves a little off
the number the model actually said, and nothing on screen says why the rice is
shrinking. Set ½, then ⅓, then All, and the user is entitled to see EXACTLY the
estimate they started with. So the estimate is written once by whoever produced
it, never computed back from the scaled copy, and changing the share is one
multiplication from a number that has not moved.

**`portion_g_*` keeps the EATEN portion**, because that is what every existing
reader already wants: `IntakeCalculator`, the daily rollup, the meal card, the
trends page and `photos:prune` all ask "what did this person eat", and get the
right answer with no change at all. Storing the plate there instead and
teaching five readers to multiply is the version where one of them forgets and
a day doubles.

**Densities are never scaled.** Per 100 g is a fact about the food, not about
how much of it was eaten — half a bowl of pho is still pho — so a share moves
the portion and nothing else, and the absolutes fall out of the derivation that
already existed. Scaling both would quarter a plate somebody halved.

**Re-analysis re-applies the plate's share; reprojection carries the item's.**
A re-analysed shared plate stays shared, or "have another look at this" would
quietly double a dinner. It re-applies the ENTRY's share only: a fresh answer is
a new list of foods with no identity the old rows can be matched to, so pinning
"all mine" onto whichever row sorts third would be inventing a decision about
food the user has not seen yet. `vision:reproject` is the one operation that
CAN match them — it rebuilds from the same stored answer that produced the rows
— and so it carries per-item overrides across by slug, exactly as it already
carries `confirmed_at`. A repair that silently restored the whole plate months
later is the kind of unexplained change the command exists to avoid.

**Memory remembers the plate, not the share.** `ConfirmedMeal::portionOf()`
reads `portion_full_g_*`. Recording the eaten half instead would compound:
`MemoryPrefill` pulls the next proposal towards the remembered portion, the
share is applied on top of whatever it lands on, and a dinner shared once would
shrink every time it was photographed again. The sharing is a fact about one
evening; the portion is a fact about the food.

**The share reaches typed and scanned items too.** Same control, on the edit
sheet, per item — which is the only way to fraction a BARCODE item, where the
packet declares what is in the whole bar and says nothing about how much of it
was eaten. In quick mode the 100 g storage BASIS is what scales, which comes to
the same thing: half of a 300 kcal cappuccino is 150 kcal. The text path needs
no chips, because "half a pizza" typed in words is already a thing the model
estimates correctly.

**On the wire, the client posts the PLATE plus a fraction.** The boxes on
screen show `plate × share` and move as the chips are tapped, so the effect is
visible before Confirm rather than after; typing into a box while a share is
set means "I ate this much", and the plate behind it is set to what that
implies. Posting the halved numbers instead would mean reconstructing the plate
by dividing on every edit, which is the drift above with extra steps. A payload
carrying no `share_fraction` at all — every confirm queued by the previous
version of the app — means All, which is what it always meant.

#### The photo hint (step 12)

**The recommended way to tighten a range on a photograph.** Every gram on the
photo path came back as a band, and a good part of that uncertainty was never
the model's to resolve: a picture cannot tell you the tuna was drained, that the
rice was weighed, or that there are three eggs under the cheese. The person
holding the phone knows, and had nowhere to say so. The text path is not the
answer — it replaces the photograph, when what is wanted is a photograph plus
one sentence.

So the upload gains **one optional line**, and skipping it is the normal case:
no block is sent, no column is written, and the plate is asked exactly the
question it was asked before.

**What the note is authoritative about, and what it is not.**

| | |
| --- | --- |
| What the food IS | Settled by the note. They cooked it; the model is looking at a photograph. |
| A WEIGHT they state | A measurement. Used exactly, as both ends of that item's portion range — not rounded, not widened, and no neighbouring item adjusted to compensate. |
| A COUNT they state ("3 eggs") | Settles how many, **not** what they weigh. The grams of exactly that many are still estimated. |
| Energy / macros | Still a range even at a stated weight — knowing what something weighed is not knowing how it was cooked. |
| Everything else | Estimated as before, including every visible item the note did not mention. A note is what the user happened to know, not an inventory. |
| How much of it they ate | **Not** the note's business. That is the chip row (step 11), and it stays a human tap. |

**Per PLATE, in `meal_photos.hint` (nullable text, additive).** A dinner is a
series of courses; what you know about the eggs is not what you know about the
pudding. It is deliberately a third column beside two that already exist, for
the reason step 10 split them in the first place — three sentences, three
subjects: `meals.notes` is the user about the MEAL, `meal_photos.model_notes` is
the model about its own answer, `meal_photos.hint` is the user about the FOOD,
addressed to the model. Merging any two would turn the review sheet's Notes box
into a prompt input.

**Editable after the fact, and editing it re-runs nothing.** The hint that
actually occurs to somebody is the one they think of at the sink, so it is
editable on a plate that already exists — including one confirmed yesterday. An
edit saves and changes the state of the button ("Analyse this photo again with
your note"); it does not spend a call. The numbers on screen were produced
without the note, only asking again can apply it, and asking again replaces
figures the user may have corrected by hand. Confirm carries the note too, so
deciding the numbers were close enough does not throw the sentence away.

**Prompt `v3-photo`, and why the version had to move.** The note travels as its
own content block between the image and the instruction, never concatenated into
either — the same two-block hygiene the text path uses for a typed meal, with
the instruction sent last so the final word is always ours. But a context block
alone would not have worked: `v2-photo` says *"RANGES, ALWAYS. Never a point
estimate"*, and its rules read as rules about everything above them, so a note
saying "120 g of drained tuna" would have been overruled by our own next
sentence. v3 carves the exception — *"RANGES, ALWAYS, **for everything you are
estimating**"* — which is `v1-text`'s exact wording for `v1-text`'s exact
reason. Editing the instruction is a new version, so `v2-photo` is frozen and
every row that says `v2-photo` was produced by it. The schema and the
already-logged block are shared rather than copied.

**The audit row records the note.** `vision_requests.input_payload` holds
`{"hint": "…"}` on a hinted photo call — the column step 9 added for the typed
meal, doing the same job on the other path. `image_sha256` used to be the whole
input to a photo analysis and is not any more: two rows against one photograph
that asked different questions would otherwise be indistinguishable, and the
hinted answer would read as the model changing its mind. The job sends what the
ROW says rather than what the plate says, because the note stays editable while
a call is in flight and the row has to describe the call that was actually made.
`vision:reproject` needs no change — it rebuilds from `raw_response`, which is
already an answer to the question the note was part of.

### Meal memory (redefined — photo hashing doesn't work)
Fingerprint = hash of the **sorted, slugified item-name set** returned by vision
(`chicken-breast|rice-white|broccoli`), used to re-rank and pre-fill from history —
not to identify photos. `meal_memory` + `meal_memory_items` child table; everyday UX is
"frequent/recent meals" ranked by times_logged + recency with `pg_trgm` name matching.
Photo-similarity via pgvector embeddings is deferred (Laravel 13 has native pgvector
query support when we want it).

**Step 6 pins the decisions.** All four thresholds live in `config/health.php`
→ `memory`, so moving one is a config edit, never a migration.

| Decision | As implemented |
| --- | --- |
| Fingerprint | `sha256` of the sorted, de-duplicated, slugified item names joined with `|`. **sha256, not sha1** — `meal_memory.fingerprint` is `char(64)`, which is a sha256 digest; a 40-char sha1 would be space-padded by Postgres and every lookup would become a whitespace question. `Str::slug` folds case, accents and curly apostrophes; a name that ASCII-slugs to nothing (a non-Latin script) falls back to a normalised form of itself rather than to the empty string, which would make every such meal identical. |
| Hook | ONE: `MealMemoryObserver` on `Meal::saved`, deferred through `DB::afterCommit`. Every write path — typed, scanned, photo-confirmed, re-logged from the picker — ends by saving the meal, and the items are written after the meal row inside the same transaction, so recording inline would fingerprint an empty meal. |
| Edit semantics | `meals.memory_fingerprint` records which memory a log was counted under. Same fingerprint on save → nothing happens (an edit is not another dinner). Different fingerprint → the **new** memory is incremented and re-snapshotted; the old one **keeps its count as history**. No decrement, no zero-cleanup: `times_logged` is a claim about the past, and correcting today's entry does not un-eat the other n−1. |
| Snapshot | Densities are replaced by the latest confirmation (the user's best current statement). `typical_portion_g` is a **running mean** over the logs of that fingerprint — a habit, not a band. Same-slug items merge: portions add, densities widen. |
| Partial match | Containment = shared ÷ **union** (Jaccard), threshold **0.60**. Symmetric on purpose: dividing by the proposal instead would score a two-item snack as a perfect match for any eight-item meal containing both. 3-of-4 = 0.75 matches (the model spotted the oil this time); 2-of-4 = 0.50 does not. Exact fingerprint hits skip scoring entirely and always win. |
| Pre-fill | Remembered **portion** replaces the proposed range only when it falls inside it — if the photo says 40–90 g and the habit is 180 g, the photo wins. Remembered **density** replaces the proposed one only when its band is strictly narrower. Nothing is ever widened. |
| Frecency | `ln(1 + times_logged) * 0.5 ^ (days_since_last / 14)`. The log stops a 300× breakfast from being 300× as interesting; the decay stops the list ossifying around what the user *used* to eat. |
| Search | `pg_trgm` similarity ≥ **0.30** against `canonical_name` **and** the item names (nobody remembers what the app called their lunch, they remember it had salmon in it). GIN trgm indexes on both columns; the `%` operator is what makes them usable, the explicit comparison is what pins the threshold. |

The audit distinction is kept queryable: `vision_requests.raw_response` is never
touched, `meal_items.memory_adjusted` flags the numbers memory changed, and
`meals.memory_match_id` / `memory_match_score` record what a proposal was
compared against and how closely.

**Memory is never seeded.** It starts empty on a fresh deploy and fills only from
confirmed meals — a picker pre-populated with plausible dinners nobody ate would
be the app lying about the user's own history. The step-3 "recent meals" list
stays as the fallback for exactly as long as memory is empty.

## Daily summaries & TDEE

### Rebuild mechanics
Derive the affected `local_date` set from each ingest batch / meal change → dispatch one
`RebuildDailySummary($date)` per date, `ShouldBeUnique` + short delay to coalesce
(a backfill can dirty 90 dates; batched runs must not re-queue the same date N times).

**Step 3 wiring.** The dirty set is computed inside the parse
(`ParsedPayload::dirtyLocalDates()`, carried out on `ParseResult`) and dispatched
by the caller — `ParseRawPayload` and `ingest:replay`, never by
`RawPayloadProcessor` itself, which runs inside the write transaction and would
otherwise queue a job that reads uncommitted rows. Meal and meal-item changes go
through observers. Delay 30 s, unique lock 900 s.

The delayed queue path is the durable one, but the meal endpoints ALSO rebuild
the day inline before redirecting: 30 seconds of coalescing is right for an
unattended export and wrong for a page the user is about to look at. Same
builder, which is idempotent, so the duplication costs queries and cannot
produce a different answer.

`php artisan summaries:rebuild [--date=|--from=|--to=|--queue]` rebuilds a range
or every day that has data. The table is fully derived, so this is the normal
way to apply a config change, not a repair.

### Honesty flags (expenditure is uncertain too)
- `is_complete_log` (user-confirmable; heuristic default: ≥N items, last item after
  ~18:00 local, kcal above a floor). Breakfast-only days must not read as low-intake days.
- `has_full_metric_coverage` / `active_kcal_is_partial` — a day the Watch wasn't worn
  under-reports burn by hundreds of kcal; flag it, don't pretend.
- Intake band: combine per-item ranges in quadrature (RSS of half-widths) around a
  midpoint; store `kcal_in_mid` alongside min/max. Linear min/max sums are uselessly wide.

**Step 3 pins the numbers** (all in `config/health.php` → `rollup`, so moving one
is a config edit plus `php artisan summaries:rebuild`, never a migration):

| Flag | Rule as implemented |
| --- | --- |
| `is_complete_log` | ≥ **3** items **and** last confirmed meal at/after **18:00** local **and** `kcal_in_mid` ≥ **1000**. All three; each alone has an obvious false positive. |
| `has_full_metric_coverage` | Day is finished (not today) **and** `active_energy` and `basal_energy_burned` each cover ≥ **90%** of the local day. `step_count` is deliberately excluded — no steps in an hour means no bucket, so steps legitimately cover 15–22 h of a normal day. |
| `active_kcal_is_partial` | Watch-derived `active_energy` coverage < **80%** of the elapsed day. "Watch-derived" is `device_kind` ∈ {`watch`, `composite`} — HAE's "Watch\|iPhone" composite did involve the wrist. |
| provisional day | Today is always written (the daily view needs it) and always has `has_full_metric_coverage = false`: hours that have not happened cannot have been measured. Coverage is divided by the *elapsed* day, so a normal morning is not flagged partial. |

`active_kcal_coverage` (numeric 0–1) is stored alongside the flag, so the UI can
say "the Watch covered 61% of this day" instead of showing an unfalsifiable
warning, and a threshold change can be reasoned about after the fact.

The **user-confirmable** half of `is_complete_log` is deferred: the column is
rewritten by every rebuild, so an override needs its own column to survive one.
Nothing in step 3 sets it by hand.

Step 3 also adds `steps`, `exercise_minutes`, `distance_km` and
`active_kcal_coverage` to `daily_summaries`. They could be computed on the fly,
but each is a greedy selection rather than a SUM, and the trends page asks for
28 days of four of them at once.

### TDEE estimator (now an actual equation)
`TDEE = mean(intake over complete-log days) − kcal_per_kg × OLS slope of EMA-smoothed
weight` over rolling 14–28 day windows.
- Gate: ≥14 complete-log days AND ≥8 weigh-ins in window; show "collecting data" until then.
- Slope from OLS over the EMA series — never endpoint-minus-endpoint (pure noise; daily
  weight noise is ±1 kg).
- Store `tdee_min/max` (regression SE ⊕ intake range) + `method_version`. Range, not a number.

**Step 7 pins the rest** (`config/health.php` → `tdee`; `App\Services\Tdee\*`).

The equation as implemented, with the uncertainty spelled out:

```
intake_mean = mean(kcal_in_mid)          over complete-log days in the window
band_low    = mean(kcal_in_mid − kcal_in_min)   "        "        "
band_high   = mean(kcal_in_max − kcal_in_mid)   "        "        "
slope, SE   = OLS over the EMA series, x = calendar day index of each weigh-in
tdee_mid    = intake_mean − kcal_per_kg × slope           (kcal_per_kg = 7700)
tdee_min    = tdee_mid − sqrt((kcal_per_kg × SE)² + band_low²)
tdee_max    = tdee_mid + sqrt((kcal_per_kg × SE)² + band_high²)
```

- **In quadrature**, because the two uncertainties are independent: how vague the
  food log is has nothing to do with how noisy the scale was. Linear addition
  would produce a range too wide to act on.
- **Asymmetric**, because the intake band is — which is why `tdee_mid` is a
  stored column (additive migration) and not `(min + max) / 2`. So are
  `intake_mean_kcal` and `weight_slope_kg_per_day`: the card explains itself from
  the row it displays, rather than from a second computation that can disagree.
- **The intake band is NOT divided by √n.** A portion-size error repeats every
  day rather than cancelling; averaging 20 days of ±180 kcal does not give ±40.
- The published range is *not* a 95% CI (one SE on the slope term, a full
  plausible spread on the intake term) and is labelled a plausible range.

**Window rule.** Ends **today** (local), runs back `window_days` (28), and is
front-trimmed to the first day in that span carrying a complete log or a weigh-in
— never shorter than 14 days. "Longest window ≤28 d that clears the gate" reduces
to exactly this: both gate counts only grow as the window grows backwards, so if
any window in 14…28 passes then the 28-day one does. Trimming changes no number;
it stops a stored row claiming to cover days it knows nothing about. Ending on
today (rather than yesterday) means a stretch of unlogged days pushes the estimate
back to "collecting data" instead of letting it go quietly stale.

**Triggers.** Nightly `tdee:estimate` at 03:40 Europe/Amsterdam — after the day
has rolled over *and* after the export covering 23:00–00:00 has landed and its
rebuild has run. Plus `RecomputeTdee`, dispatched by any `daily_summaries` rebuild
(queued or inline) whose date is inside the window; `ShouldBeUnique` on a
**constant** key, because there is one current window and a 90-day replay would
otherwise queue 90 identical fits. Below the gate, nothing is written at all.

**The page computes; the table remembers.** The trends card derives the estimate
on render (two queries and a least-squares fit) so the evening the fourteenth
complete day lands, the number is there rather than 60 s later — and upserts it,
touching `computed_at` only when the answer actually moved. Same precedent as
DailyView building a missing summary on render.

**Known, documented biases.** (a) 7700 kcal/kg assumes the mass moved is tissue;
the EMA is what stops a water swing being read as 2 300 kcal. (b) The EMA's
start-up transient biases the slope ~7% toward zero when the window *is* the whole
weigh-in history — the user's first estimate only, worth ~8 kcal on a clean series
and up to ~150 kcal on a noisy one; seeding the EMA from full history is what
keeps it to that one window. (c) The mean over complete-log days stands in for the
whole window, which is why the gate demands 14 of them.

## UI shell (step 3, decisions the spec left open)

**Inertia + Vue 3, no SSR, three pages** (`Day`, `Trends`, `Auth/Login`) resolved
eagerly — the whole bundle is ~98 KB gzipped and a page-per-request round trip on
a phone is worse than shipping all three. Tailwind v4; dark mode follows
`prefers-color-scheme` with no in-app toggle (the phone already has that switch,
on a schedule the user set). System fonts, so nothing is fetched to look native
on the one device that matters. Charts are hand-rolled SVG: ~60 lines each, no
runtime dependency, and they inherit `currentColor` so both themes come free.

**Auth: one account, and exactly one self-service action.** Session
login/logout, plus an authenticated in-app **password change** (`PUT
/profile/password`, a section on the profile page). That change is gated by the
`current_password` rule — the user must know the password they are replacing —
and it re-hashes the current session (`Auth::logoutOtherDevices`) so the phone it
was changed from stays signed in while every other device is signed out. It is
emphatically a CHANGE, not a reset: there is still no registration, no
*unauthenticated* password-reset or verification route and no email flow
anywhere, and a test asserts `/register`, `/forgot-password` and `/reset-password`
all 404. The account is first created by `php artisan db:seed --class=SingleUserSeeder`,
which **generates** a strong password and prints it once — nothing but the bcrypt
hash is stored, and a full rotation from outside a session still means re-running
the seeder with `SEED_USER_PASSWORD` set. Login is throttled 5/min per email+IP.
`remember` is always on: being logged out every two hours is how a food log stops
being used.

`bootstrap/app.php` trusts proxies at `*`. That is safe *because* the stack
publishes on `127.0.0.1:3083` only — nothing on the internet reaches php-fpm
without traversing the host vhost, so no attacker is positioned to forge
`X-Forwarded-*`. Publishing that port publicly would invalidate the reasoning.
Session cookies are `secure` + `lax`.

**`POST /api/ingest` is untouched by all of it** — the api group carries no
session, no CSRF and no auth middleware, and the phone authenticates with
`X-API-Key`. Two tests exist solely to keep it that way, because the failure
would be silent: ingestion would stop and the first symptom would be a gap in
the history days later.

It does, however, carry a **throttle and a body ceiling**, which are the only
two things standing between "unauthenticated write" and "unauthenticated write
anyone can hammer". `throttle:ingest` is 60/min per IP — sixty times what the
hourly export needs, so it is invisible to a real phone catching up a backlog,
and it is what makes guessing the shared secret pointless rather than merely
slow (`hash_equals` makes the *comparison* constant-time; nothing else made the
*attempt* expensive). `config('health.ingest.max_body_bytes')`, default **8 MiB**,
is checked with one `strlen` **before** `json_decode`: the vhost allows 100 MB
because meal photos cross the same server block, and decoding a body that size
allocates roughly ten times it in PHP arrays before anything can object.

**`GET /api/health` is unauthenticated on purpose** — an uptime check has to be
able to reach it, which means anybody can, so its body is **timestamps, statuses
and pipeline counters only: never a measurement, never free text, never a row
count of the owner's data.** `status`, `time`, the two "last"s (bytes *arrived*
vs bytes *understood*), the unparsed-payload count and the runs-by-status
counters, plus the client-error counts. Deliberately *not* published:
`ingest_runs.error` (written as a verbatim exception message, and Laravel builds
a `QueryException`'s message by interpolating the failing statement's *bindings*
into it — which for this pipeline are metric names, values and timestamps), the
HAE `session_id`, the latest client error's message/url/build, and whole-table
totals. The error text is also sanitised where it is *written*
(`App\Services\Ingest\IngestErrorText`: exception class + SQLSTATE), so the
column cannot hold a reading whoever reads it later. `/api/health` is throttled
60/min: it is eight aggregate queries a hit.

**Recent meals** (one-tap re-log) are the last ~10 distinct meals, deduplicated
on the sorted set of item-name slugs — SPEC's `meal_memory` fingerprint computed
on the fly. The real tables with `times_logged` ranking and `pg_trgm` matching
stay in step 6; this needs no schema. Re-logging copies stored items **verbatim,
ranges included**: re-logging something uncertain does not make it certain.

**The weight EMA is computed server-side** (`config('health.trends.ema_alpha')`,
default 0.25), seeded from the first weigh-in ever rather than from zero, and
seeded from history *outside* the window so a 7-day chart does not open on a
cold start. Gaps are skipped, never interpolated — step 7's TDEE estimator takes
the OLS slope of this same series, and an invented point would become a
fabricated slope.

**Asset build runs in a profile-gated compose service**, not as a multi-stage
layer in the app image:

```bash
docker compose --profile build run --rm assets   # npm ci && vite build
```

The app image bind-mounts the whole project at `/var/www/html`, so anything
`COPY`ed to that path is shadowed the moment the container starts — a
multi-stage build would produce assets nginx could never see. The service runs
as the app uid, so `node_modules/` and `public/build/` land correctly owned.

## iOS PWA constraints (verified 2026-08-07)
- **User's daily browser is Chrome on iPhone — which is a WebKit shell**, so every
  constraint below applies unchanged in Chrome (including the BarcodeDetector absence).
  "Add to Home Screen" works from Chrome's share menu on modern iOS, and the installed
  app runs in the same standalone WebKit container regardless of which browser added it,
  with its own isolated storage. Test the install flow from Chrome, not just Safari.
- **Background Sync API does not exist on iOS WebKit** — offline meal logging is an
  in-page IndexedDB queue, flushed on foreground/`online`. Client-generated UUIDs +
  Idempotency-Key (already required above) make the flush safe.
- Call **`navigator.storage.persist()` at first launch** — home-screen web apps get
  browser-grade quota and persistence heuristics favor them; this protects the offline
  queue from 7-day script-storage eviction.
- **Camera permission is not remembered** in home-screen web apps (re-prompt per launch;
  WebKit 215884). Put scanning/photo behind an explicit tap; avoid hash-routing on the
  scanner view (hash changes can re-trigger prompts). getUserMedia in standalone PWAs
  works on current iOS (18.1.1+ fixed the 18.0 regression).
- iOS 26: any site added to Home Screen opens as a web app by default; still no install
  prompt (Share → Add to Home Screen only).
- Locked phone = no HealthKit access; overnight data lands in the morning; automations
  need Background App Refresh on and suffer under Low Power Mode. Real-time is off the
  table by design — dashboard recalculates on ingest arrival. Add a **"last successful
  ingest"** indicator + server-side gap alerting.

### Step 8 decisions (PWA polish)

Everything below was forced by WebKit, by the fact that the only client is a
Home-Screen web app on one iPhone, or by what the earlier steps already
guaranteed.

**Installability.** `/manifest.webmanifest`, `/sw.js` and `/offline` are Laravel
routes registered in `routes/pwa.php` with **no middleware group at all**
(`Route::group([], ...)` from `bootstrap/app.php`). Public, because the OS reads
the manifest at install time, the worker is registered from the login page, and
the offline page is shown exactly when a redirect cannot be followed. Session-less,
because `SESSION_DRIVER=database` and a browser revalidates `/sw.js` on *every*
navigation — inside the `web` group each check would write a `sessions` row for a
visitor who is not one. The manifest is a route rather than a file so it carries
`application/manifest+json`, which nginx's stock `mime.types` has no entry for.

**Icons are generated, not exported.** `php artisan pwa:icons`
(`GeneratePwaIconsCommand`) draws the SVG masters and every PNG from one set of
geometry constants: a white plate with a heart-rate trace across it, on teal-600.
No SVG rasteriser exists on this host, so the PNGs are drawn with ext-gd — which
step 5 already requires — at 4× and downsampled; that supersampling *is* the
antialiasing, because GD's `imageantialias()` does not apply to thick lines or
filled ellipses. Plain and maskable are **two different croppings**, not one file
tagged `any maskable`: a maskable icon may be cropped to the circle inscribed in
80% of the square, so its glyph has to be smaller.

**Service worker, deliberately conservative.**

| Request | Strategy |
| --- | --- |
| `/build/*` (content-hashed, `immutable`) | cache-first, runtime-filled |
| precached statics (offline page, manifest, icons) | cache-first |
| navigations (HTML) | network-only; `/offline` on failure |
| Inertia XHR (`X-Inertia`) | not intercepted |
| `/api/*` (including `/api/ingest`) | not intercepted |
| anything not `GET`, anything cross-origin | not intercepted |

"Not intercepted" means no `respondWith` and no `fetch` — there is no code path
in which a bug can answer an authenticated request from a cache. **Nothing
authenticated is ever cached**: a worker that serves a stale daily view shows
yesterday's calories as today's, with nothing on screen to say so.

The **1 MB zxing wasm is not precached** — precache runs on install, i.e. the
first launch after every deploy, and the runtime `/build/` rule picks the binary
up the first time a scan actually needs it. The cache name carries a hash of the
Vite manifest, so a deploy busts it and a container restart does not; `activate`
deletes every older cache. The worker `skipWaiting()`s and claims, and the page
shows a **"Updated — reload" prompt rather than reloading itself**: auto-reload is
correct until the one time it fires with a half-typed dinner in the meal sheet.

**Offline queue.** In-page IndexedDB (`health-tracker` → `actions`), because
**Background Sync does not exist in WebKit** — there is no `sync` event to
register and no way to run app code while the app is off screen. Flushed on
`online`, on `visibilitychange → visible`, and at launch. Replay goes through the
**same endpoints** and relies on the idempotence steps 3 and 5 already built
(client uuid, `Idempotency-Key`), so a double flush costs a request and changes
nothing.

The first attempt goes through the queue module too, so the bytes the server sees
on a replay are identical to the bytes it would have seen at the time — one write
path rather than an Inertia visit one way and a fetch the other. That forced one
framework change: `shouldRenderJsonWhen` now also covers `$request->expectsJson()`,
because a validation failure on an Inertia route is `redirect()->back()->withErrors()`
— a 302 that `fetch` follows to a 200, which the queue would read as *sent* and
delete. Inertia's own visits send `Accept: text/html, application/xhtml+xml`, so
`expectsJson()` is false for them and nothing else changes.

Failure taxonomy, and what each does to the queued action:

| Outcome | Queue behaviour |
| --- | --- |
| network error / `navigator.onLine === false` | stays queued; the flush stops there |
| 429, 5xx | stays queued; the flush stops there |
| 401 / 419 | **blocked**, kept, "sign in / reload and this will send" |
| 422 | **blocked**, kept, carrying the server's message |
| other 4xx | **blocked**, kept |
| 2xx | deleted |

Blocked actions are skipped by later flushes (one bad meal must not hold up four
good ones) and are shown with Retry / Discard. **Nothing is ever dropped
silently** — the 50-action cap refuses new work and says so rather than evicting
the oldest, because discarding a meal the user watched go into the app is the one
failure the whole module exists to prevent. **Deletes are not queued**: replaying
a destructive action that may be racing an edit is a promise not worth making.

**`navigator.storage.persist()`** is called at the first *authenticated* launch
and its outcome stored and shown ("Offline log is not protected — add this to your
Home Screen"). WebKit evicts script-writable storage after seven days without
interaction; a Home-Screen web app gets its own container and is the case the
persistence heuristics favour. A denied origin is re-asked on later launches,
because the answer changes the moment the app is installed.

**Last-ingest indicator** on the daily view, from `DailyView`'s props rather than
a client poll of `/api/health`: the age is the *server's* clock against the last
arrival, and a phone whose clock has drifted forward would hide the gap. Threshold
`config('health.ingest.stale_after_hours')`, default **36 h** — a locked iPhone
has no HealthKit access, so an eight-to-twelve hour overnight gap is what a
*healthy* night looks like from here, and an indicator that is orange most
mornings has stopped being an indicator. Never having received anything reads as
stale, because that is exactly the state a mis-pointed automation leaves.

### Recommended HAE automation config (metric list corrected in step 1)
JSON, Export Version 2 pinned, Since Last Sync, Summarize ON, Hourly grouping,
Batch Requests ON. Add the Automations widget to the Home Screen; keep the
phone charging overnight to loosen background restrictions.

**Metric list, corrected against real payloads.** v2 listed "skeletal muscle mass"
and "visceral fat index" — the scale in use (FITAGE / Fitdays) exports **neither**.
The body-composition metrics that actually arrive are `weight_body_mass` (kg),
`body_fat_percentage` (%), `body_mass_index` and `lean_body_mass` (kg). The ones that
matter, as named by HAE:

    step_count · walking_running_distance · active_energy · basal_energy_burned
    heart_rate · heart_rate_variability · resting_heart_rate · sleep_analysis
    weight_body_mass · body_fat_percentage · body_mass_index · lean_body_mass

Also observed: the automation is currently exporting **33** metrics, not ~11 — the
extra 21 (audio exposure, walking asymmetry, handwashing, VO2 max, …) are harmless
and cheap to bank, so they are ingested rather than filtered. Energy arrives in **kJ**,
not kcal.

**Step 2 reverses step 1 here: conversion happens on the way IN.**
`health_metrics.unit` holds the CANONICAL unit, and `App\Services\Ingest\
MetricCatalog` holds the per-metric map. In practice that is two metrics —
`active_energy` and `basal_energy_burned` are stored in kcal (÷ 4.184) — plus
sleep stages, which arrive in fractional hours and land in `sleep_sessions` as
minutes. Everything else is stored exactly as delivered, and a metric absent from
the map keeps whatever unit it arrives with, so banking a new HAE metric never
needs a code change.

Why the reversal: step 1's argument for verbatim storage was that converting on
the way in makes replay lossy. It does not — `raw_ingest_payloads` is permanent
and keeps every original byte, so the conversion is reproducible by definition.
Meanwhile a `value` column that mixes kJ and kcal rows depending on which
firmware sent them cannot be summed without every reader re-implementing the
conversion, and one of them eventually would not.

## Supplements (tier 1)

The bottles on the shelf, and whether they were actually taken. Deliberately the
smallest useful version of the idea: **adherence, not nutrition.**

### What tier 1 is

- A list of supplements, each with the figures printed on its label.
- One tap per supplement per day to say it was taken. Works on a past day.
- **One press for the whole shelf**, which is what the day view actually shows.
- A quiet attention state on the day view in the evening if something is still
  unticked.
- One line on Trends: "supplements: 6/7 evenings complete".

### The card on the day view is one row

    Supplements · 0/4                                        [ Take all ]

Collapsed by default, on every day. "Take all" ticks every active supplement for
the day being looked at; once there is nothing left it becomes `✓ Supplements —
all taken` and the button goes. Tapping the row (not the button) opens the
per-bottle list for the exceptions — skipped the fish oil, ticked one by
mistake — and tapping it again folds it back. Partial states read honestly in
the collapsed line: `2/4`, and after the evening hour, `2 still to take` in
amber.

The reasoning is proportion. Swallowing four things and telling the app takes two
seconds and happens every day; a row per bottle spent five rows of a phone screen
on it permanently, pushing the meals and the balance line below the fold. The
rows are one tap away because the exceptions are real, not because they are the
common case.

**"Take all" is a loop over the existing per-bottle `PUT`, not a bulk endpoint.**
That is a data-integrity decision rather than a convenience one: the offline
queue de-duplicates by action id, and the per-bottle id is `supplement:{id}:{date}`.
Pressing "take all" with no radio and then un-ticking one bottle therefore
REPLACES that bottle's queued statement, so exactly one statement per bottle per
day ever reaches the server and it is the last one the user made. A bulk action
would sit in the queue under an id of its own, with nothing to replace it — flush
it after the un-tick and it silently re-ticks a bottle the user had just said no
to. Four requests is a rounding error next to that.

Expansion is per-visit state: not stored, not in the URL, and never auto-opened —
including on a past day with a partial count. Paging back through a week is a
sequence of days, not a sequence of investigations, and a card that changed
height between them would reflow everything under it on every arrow press.

### What tier 1 is deliberately NOT

- **No estimated micronutrients from food.** Nothing here looks at what was
  eaten. Working out how much magnesium came off a plate means a per-100 g
  micronutrient table for every food in the log, and every number in it would be
  an estimate of an estimate. That is the *tier 2 weekly report* — a
  once-a-week, explicitly-approximate view of coverage — and it is a different
  feature with a different honesty problem. Keeping them apart is the point:
  tier 1's numbers are transcriptions of printed labels and are exact.
- **No push notifications.** iOS PWAs can have them (16.4+, installed to the
  Home Screen, user-granted), and they are still out of scope. A notification is
  a permission prompt, a subscription to keep alive, a server-side scheduler,
  and a thing that fails silently when any of the three lapses — in exchange for
  a reminder to do something the user already opens this app every evening to
  do. The evening emphasis rides on that existing habit instead. If it turns out
  not to be enough, push is a later decision made on evidence.
- **No dose-per-intake, no schedule, no times-per-day.** The dose is a property
  of the bottle (`units_per_day`), set once. One tap means "I took my dose",
  whether the dose is one tablet or two softgels. A per-pill tick would be two
  taps for the magnesium and a count nobody wants to keep.
- **No stock tracking, no reorder reminders, no interactions checking.** The
  last one especially: this app is not qualified to have an opinion about
  whether two supplements should be taken together.

### The one arithmetic rule

`supplement_nutrients.amount` is the figure **as printed**, for the serving the
label states it for. `supplements.serving_text` records that serving verbatim
("per capsule", "per 2 mini softgels"). `units_per_day` counts those servings.

    daily total = amount × units_per_day

Nothing is ever normalised, converted or divided — not by the model, not by the
seeder, not by the app. A panel printed "per 2 capsules: 200 mg", taken as two
capsules, is 200 mg × 1 rather than 100 mg × 2, and the app never has to work
out which because it does not try. Restating a figure against a different
serving is a conversion, and a converted figure is one the user cannot check
against the bottle in their hand.

### Where the figures come from

Five supplements ship with the app (`SupplementSeeder`), transcribed from the
manufacturers' published panels, each row carrying `data_source` and the
`source_url` it was read off. That is reference data of the same kind as the
`food_products` cache — **no intakes are ever seeded**, because that table is a
record of days that happened.

Every seeded figure is editable in the same screen as any other, and saving the
panel flips `data_source` to `hand_entered`: once a human has been through the
form, the manufacturer is no longer answerable for what is in the row.

Where a published source contradicts itself, the line is seeded **named and
empty** with a note saying why, rather than filled with a plausible guess. The
Nordvita multivitamin's vitamin D row is the live example — "400 iu", "5 mcg" and
"600% RI" cannot all be true.

### The label-photo path

Still there, and now what it should always have been: the way you add the
*sixth* bottle. Photograph the supplement-facts panel → one vision call
(`PromptV1Label`, `prompt_version` `v1-label`, audited in `vision_requests` with
`request_kind = 'label'` and a NULL `meal_id`) → a review screen with every line
editable → Confirm creates the supplement.

Two things differ from the meal photo path, and both follow from it being a
**transcription** rather than an estimate:

- **2048 px, not 1024.** A supplement-facts panel is thirty lines of 5-point
  type. At the meal ceiling the µg figures are a grey smear, and an illegible
  figure does not come back as a wider range — it comes back as a wrong number
  that looks exactly like a right one. Four times the image tokens, once per
  bottle. (`health.vision.label_max_edge_px`)
- **The prompt is the opposite instruction.** Every other prompt in this app
  asks for ranges and for uncertainty to be widened. This one forbids
  estimation, conversion, merging lines and filling gaps, and asks for the panel
  in its own language and units — the labels are Dutch.

Label photographs are **exempt from the 90-day retention sweep** (they live
under `labels/`, which the sweep's `originals/% JOIN meals` cannot reach). A
meal photo is evidence for a decision already written into `meal_items`; a label
is reference data about a bottle you still own, and it is what the edit screen
shows when you go back to check a figure a year later. The cost is bounded by
how many supplements a person takes, not by how often they eat.

### Adherence

An evening counts when **every** supplement that existed that day was ticked.
Three rules keep the number from lying: a day before a bottle existed is not in
the denominator, a day that has not happened yet is not a miss, and there is no
partial credit. Only active supplements are scored — stopping something must not
keep dragging the number down.

### Why `active` is a choice at creation

`active = false` reads in both directions and both are real: *stopped taking it*
(off the card, out of the denominator, every past tick preserved — a delete
would cascade the history away) and *not started yet* (the spare bottle bought
today and opened in three months, transcribed while the label is in your hand).

### Feeding tier 2

`supplement_intakes` is one row per (supplement, local date), which joins to
`daily_summaries` and `meals` on `local_date` exactly as everything else in this
app does. The future weekly micronutrient report reads it directly: label
figures × `units_per_day` × the days it was actually taken. Nothing about tier 1
needs to change for that to be possible, which is why the table stores a day
rather than an event.

## Build order (revised)
0. **Raw-capture ingest endpoint** (few days of real payloads; starts the TDEE clock;
   buy HAE Premium lifetime and point it at the endpoint) — plus Docker Compose skeleton,
   subdomain + vhost.
1. Schema **derived from observed payloads** + migrations, models, factories.
2. Ingestion pipeline with tests: upsert semantics, source priority, timezone/DST cases,
   `ingest_runs` logging, gap detection.
3. Inertia/Vue shell: daily view, manual meal entry (client UUIDs + idempotency keys
   from the start), weekly trend chart. **Done** — plus the `daily_summaries`
   rollup that feeds them (see "UI shell" above).
4. Barcode path: zxing-wasm + OFF lookup + `food_products` cache. **Done** — see
   "Step 4 decisions for the barcode path" above.
5. Vision pipeline: status machine, `vision_requests` audit, ranges UI. **Done** — see
   "Step 5 decisions for the photo path" above.
6. Meal memory. **Done** — see "Meal memory" above.
7. TDEE back-calculation. **Done** — see "TDEE estimator" above. Correctness is
   established against synthetic fixtures, not production: there are two weigh-ins
   and zero complete-log days in the real history, so the live app correctly shows
   "collecting data" and `tdee_estimates` is correctly empty.
8. PWA polish: installability, offline IndexedDB queue, `storage.persist()`, camera UX.
   **Done** — see "Step 8 decisions (PWA polish)" above. The camera UX was settled
   in steps 4 and 5 (a file input with `capture="environment"` for the photo, an
   explicit tap before the live stream for the scanner) and is deliberately
   untouched here.

9. Meal-entry UX + the corrections the first week of use produced. **Done** — see
   "Step 9" and "Corrections after the first week of real use" above.
10. Multi-photo meals: a meal is a series of plates, each analysed and confirmed
    on its own. **Done** — see "A meal is a series of plates" above.
11. The shared plate: the model estimates the plate, the user says how much was
    theirs. **Done** — see "The shared plate" above.
12. The photo hint: one optional line beside a photograph, editable afterwards,
    and the recommended way to tighten a range. **Done** — see "The photo hint"
    above.
13. Supplements, tier 1: a seeded shelf, one tap per evening, adherence on
    Trends. **Done** — see "Supplements (tier 1)" above. Tier 2 (the weekly
    micronutrient estimate, food included) is deliberately a separate feature
    and is not built.

**Build order 0–8 is complete.** Steps 0–3 give a usable app; everything after is
improvement.

## Non-goals
Multi-user/public signup · writing back into Apple Health · native iOS app ·
push notifications.

Micronutrients have moved from "non-goal" to "half done, deliberately". Tier 1
(supplements, above) records exactly what a label prints and whether it was
taken. Tier 2 — micronutrients estimated from *food*, as a weekly, explicitly
approximate coverage view — remains unbuilt and is still the right shape for
them: a per-100 g micronutrient table for every food in the log is an estimate
of an estimate, and it does not belong on a daily screen next to figures that
were measured.

## Principles
- Uncertainty is a feature: ranges everywhere — **on expenditure too** (coverage flags).
- The model proposes, the user confirms. Nothing logged without a tap.
- Schema comes from real payloads, not documentation.
- Ship the boring loop first: log food, see intake vs burn, see the weekly trend.
