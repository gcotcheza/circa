<?php

declare(strict_types=1);

/*
 * The two energy metrics, named once and read back under
 * `rollup.active_metric`/`resting_metric`. The rate ceilings below key on
 * these variables, not the strings again — a ceiling keyed on a stale
 * literal stops guarding silently rather than failing loudly.
 */
$activeMetric = 'active_energy';
$restingMetric = 'basal_energy_burned';

return [

    /*
    |--------------------------------------------------------------------------
    | Application timezone (the "local day" this app reports in)
    |--------------------------------------------------------------------------
    |
    | Deliberately NOT config('app.timezone'): Laravel stays on UTC, this is
    | the *reporting* zone that decides which calendar day — and which
    | `daily_summaries` row — a datapoint belongs to.
    |
    | Default Europe/Amsterdam: every captured payload shows a +0200 (CEST)
    | device offset, matching the device's locale.
    |
    | !! CHANGING THIS IS A MIGRATION, NOT A CONFIG EDIT !! `health_metrics.
    | local_date` / `meals.local_date` are Postgres STORED GENERATED columns —
    | immutable expressions, so the zone is baked into the DDL as a literal,
    | not read here. A change needs a migration that drops/recreates both
    | columns and rebuilds `daily_summaries` (day boundaries move); those
    | migrations carry the same warning.
    |
    */

    'timezone' => env('HEALTH_TIMEZONE', 'Europe/Amsterdam'),

    /*
    |--------------------------------------------------------------------------
    | Source priority (the anti-double-count rule)
    |--------------------------------------------------------------------------
    |
    | iPhone and Watch both report step_count / distance / energy for the same
    | hour bucket, as separate rows with different sources. The read side must
    | pick ONE row per (metric, bucket) via DISTINCT ON in this order — never
    | SUM across sources, which roughly doubles expenditure and poisons TDEE.
    |
    | Keys are `sources.device_kind`, not raw device strings: device names get
    | renamed (case alone can differ from Apple's own casing), and a rename
    | must not fork history.
    |
    | 'default' covers any metric without an explicit entry; kinds absent from
    | a list rank last, unspecified order.
    |
    */

    'source_priority' => [

        'default' => ['watch', 'phone', 'scale', 'app', 'composite', 'unknown'],

        // Movement + expenditure: Watch is on the wrist, phone half the day
        // on a desk. Watch first, always.
        'step_count'               => ['watch', 'composite', 'phone'],
        'walking_running_distance' => ['watch', 'composite', 'phone'],
        'cycling_distance'         => ['watch', 'composite', 'phone'],
        'flights_climbed'          => ['watch', 'composite', 'phone'],
        'active_energy'            => ['watch', 'composite', 'phone'],
        'basal_energy_burned'      => ['watch', 'composite', 'phone'],

        // Body composition only from the scale (two apps have fronted the
        // same hardware: "FITAGE" and "Fitdays").
        'weight_body_mass'    => ['scale', 'app', 'phone'],
        'body_fat_percentage' => ['scale', 'app', 'phone'],
        'body_mass_index'     => ['scale', 'app', 'phone'],
        'lean_body_mass'      => ['scale', 'app', 'phone'],

        // Fitage-only metrics (`fitage:import`). Only a scale produces them
        // today, so this changes nothing yet — it's here so the rule already
        // matches the four metrics above the day something else measures body
        // water.
        'muscle_mass'           => ['scale', 'app', 'phone'],
        'visceral_fat'          => ['scale', 'app', 'phone'],
        'body_water_percentage' => ['scale', 'app', 'phone'],
        'bone_mass'             => ['scale', 'app', 'phone'],
        'basal_metabolic_rate'  => ['scale', 'app', 'phone'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fitage body-composition import
    |--------------------------------------------------------------------------
    |
    | The scale's own app exports history as .xlsx; Health Auto Export only
    | ever carried four of the metrics, hour-bucketed, on days the phone
    | happened to sync. `php artisan fitage:import` recovers the rest.
    |
    | A rare manual operation, not a pipeline — a human drops files and runs a
    | command at deploy time. It deliberately does NOT write `ingest_runs`:
    | that table is the audit trail for the HAE HTTP endpoint, keyed on
    | (session_id, sha256(body)) with an IngestRunStatus describing an HTTP
    | request; a file-derived row would make "did the phone's export land?"
    | unanswerable from the table whose only job is answering that. The
    | command's output plus a log line is the record; `health_metrics` is the
    | durable result either way.
    |
    */

    'fitage' => [

        // Where `fitage:import` looks when --dir is omitted. Under storage/,
        // which is gitignored — this is personal data and must never be
        // committed.
        'directory' => env('FITAGE_IMPORT_DIR', storage_path('app/imports')),

        // `sources.raw_name` for these rows. Contains "fitage", so
        // Source::kindFor classifies it as DeviceKind::Scale and it ties with
        // HealthKit's "FITAGE" source on every body-composition priority list
        // — letting the rollup's latest-sample-wins tiebreak prefer the
        // export's real instant over HAE's hour-anchored one. Kept SEPARATE
        // from "FITAGE" on purpose: one row per way the data reached us.
        'source_name' => env('FITAGE_SOURCE_NAME', 'FITAGE export'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily rollup (step 3)
    |--------------------------------------------------------------------------
    |
    | Everything DailySummaryBuilder needs that is a judgement call rather
    | than a fact. Facts (cumulative vs not, arrival unit) live in
    | MetricCatalog; these are thresholds a human may want to move.
    |
    */

    'rollup' => [

        // Both halves of expenditure, by HAE metric name — already stored in
        // kcal (MetricCatalog converts on the way in).
        'active_metric'  => $activeMetric,
        'resting_metric' => $restingMetric,

        // Non-energy metrics the activity strip shows, rolled up with the
        // same overlap-safe selection as energy.
        'steps_metric'    => 'step_count',
        'exercise_metric' => 'apple_exercise_time',
        'distance_metric' => 'walking_running_distance',
        'weight_metric'   => 'weight_body_mass',

        'coverage' => [

            // Metrics `has_full_metric_coverage` requires. step_count is
            // deliberately excluded: no steps in an hour means no bucket, so
            // steps normally cover only ~15-22h of a day. Active/basal energy
            // are emitted every hour the Watch is worn, so a gap in THEM is
            // real.
            'required_metrics' => ['active_energy', 'basal_energy_burned'],

            // Coverage fraction for "fully covered". 0.90 = up to ~2.4
            // missing hours, roughly a charge cycle's cost.
            'full_day_fraction' => 0.90,

            // Below this active-energy coverage fraction, burn is an
            // undercount and the day is flagged rather than stated as fact.
            'active_partial_fraction' => 0.80,

            // Device kinds counting as "Watch was on the wrist". `composite`
            // is HAE's "Watch|iPhone" summary — the Watch contributed to it.
            'watch_kinds' => ['watch', 'composite'],
        ],

        /*
         * Drops a bucket whose value-over-stamped-span implies an impossible
         * rate (a Watch reconnect can stamp a whole gap's basal energy into
         * one hour). Ceilings are multiples of the largest bucket ever
         * observed, not its average, and the bucket is never re-windowed
         * across the gap — the stamp is the only time information it carries,
         * and it's wrong. See docs/rationale-data.md § "Rollup — basal/active
         * rate ceiling" for the incident and the derivation.
         */
        'rate_ceiling' => [
            'min_seconds'   => 60,
            'kcal_per_hour' => [
                $restingMetric => 200.0,
                $activeMetric  => 1500.0,
            ],
        ],

        // is_complete_log heuristic — all three must hold, or a
        // breakfast-only day reads as a disciplined 400 kcal one.
        'complete_log' => [
            'min_items'       => 3,
            'last_meal_after' => '18:00',
            'kcal_floor'      => 1000,
        ],

        // Coalescing window for RebuildDailySummary: a backfill can dirty 90
        // dates, re-dirtied several times a minute by a batched export; this
        // turns that into one rebuild per date.
        'rebuild_delay_seconds' => 30,

        // Must exceed the delay above, or the unique lock expires before the
        // job it protects has run.
        'rebuild_unique_for' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Food products / Open Food Facts (step 4)
    |--------------------------------------------------------------------------
    |
    | The barcode path's only outbound dependency. OFF is free, crowd-sourced,
    | read-only for us: no key, 15 requests/minute/IP, and a MANDATORY
    | identifying User-Agent (generic ones are blocked; the address is a
    | contact, not a credential, per OFF's own community guidelines).
    |
    | Timeouts are short on purpose — this is a phone held up to a jar in a
    | kitchen, and a 12-second lookup has already failed for the user; "try
    | again" beats a spinner. `food_products` caches this and never expires
    | (see App\Services\Food\ProductLookup).
    |
    */

    'food' => [

        'base_url' => env('OFF_BASE_URL', 'https://world.openfoodfacts.org'),

        // SPEC.md pins this string. Bump the version, never drop the address.
        'user_agent' => env('OFF_USER_AGENT', 'HealthTracker/1.0 (user@example.com)'),

        'connect_timeout' => (float) env('OFF_CONNECT_TIMEOUT', 4),
        'timeout'         => (float) env('OFF_TIMEOUT', 8),

        // Plausibility ceilings for a crowd-sourced figure: pure fat is
        // 900 kcal/100g and nothing edible is denser; >100g of a macro per
        // 100g is a typo, not a food. Out-of-range values are stored NULL
        // (`raw` keeps the original) — a wrong number silently doubles a day,
        // a missing one doesn't.
        //
        // max_kcal_per_100g is ALSO MealRequest's validation ceiling, on
        // purpose: a product this accepts must not be rejected in the meal.
        'max_kcal_per_100g'  => 900,
        'max_macro_per_100g' => 100,

        // The same judgement about a WHOLE item rather than a density, and
        // read by every request that accepts a figure — typed, estimated,
        // proposed, re-asked — so one path cannot take a number another
        // would refuse.
        'max_kcal_absolute'  => 20000,  // No single item is 20 000 kcal; past it is a slipped decimal.
        'max_macro_absolute' => 2000,   // Nor does one item carry 2 kg of a single macro.
        'max_portion_g'      => 10000,  // 10 kg on one line is a typo, not a portion.
    ],

    /*
    |--------------------------------------------------------------------------
    | Vision pipeline (step 5)
    |--------------------------------------------------------------------------
    |
    | Photograph a plate, Claude proposes items with RANGES, the user confirms.
    | Every value here is a judgement about cost, latency or privacy.
    |
    | The prompt text and response schema are deliberately NOT here — they're
    | a versioned constant (App\Services\Vision\PromptV1), because
    | `vision_requests.prompt_version` is what makes a prompt change
    | evaluable against history; an editable config value would break that.
    |
    */

    'vision' => [

        // Exact model string, recorded on every vision_requests row —
        // changing it here doesn't rewrite what old rows say produced them.
        'model' => env('VISION_MODEL', 'claude-opus-5'),

        // Ceiling on the whole response — thinking AND JSON together.
        // Thinking is ON BY DEFAULT on claude-opus-5 (unlike Opus 4.8, where
        // omitting the parameter meant none), so a budget sized for JSON
        // alone truncates mid-answer.
        'max_tokens' => (int) env('VISION_MAX_TOKENS', 4096),

        // Seconds, enforced by the HTTP client handed to the SDK — the SDK's
        // own `timeout` option is advisory and unused in its source.
        'connect_timeout' => (float) env('VISION_CONNECT_TIMEOUT', 5),
        'timeout'         => (float) env('VISION_TIMEOUT', 120),

        // ONE retry inside the SDK (429/5xx/connection errors). The job is
        // `tries = 1`: a failed analysis becomes a `failed` meal with a
        // Re-analyze button — a refused/unreadable photo will be refused
        // again in 30s, and the person who took it is right there.
        'max_retries' => (int) env('VISION_MAX_RETRIES', 1),

        // Longest edge actually sent. Browser downscales before upload
        // (bandwidth, iOS memory); server re-encodes to the same size too,
        // which makes it a guarantee rather than a request.
        'max_edge_px' => (int) env('VISION_MAX_EDGE_PX', 1024),

        /*
         * Longest edge of a SUPPLEMENT LABEL — deliberately twice the meal
         * ceiling. A plate needs little detail to say "that is rice"; a
         * label is dense small print, and at 1024px the µg figures blur.
         * The task is transcription, so illegible isn't "a wider range", it's
         * a wrong number that looks right.
         *
         * 2048px sits inside what the model reads comfortably (its own
         * ceiling is ~2576px; past that the API downsamples anyway). Roughly
         * 4x the pixels/tokens of a plate, paid ONCE per bottle at setup.
         */
        'label_max_edge_px' => (int) env('VISION_LABEL_MAX_EDGE_PX', 2048),

        // Thumbnail's longest edge — small enough to keep forever, large
        // enough to recognise the meal in a list.
        'thumb_edge_px' => (int) env('VISION_THUMB_EDGE_PX', 256),

        'jpeg_quality' => (int) env('VISION_JPEG_QUALITY', 82),

        // Upload ceiling in KB, checked before decoding. A 1024px JPEG is
        // ~150-400KB; 8MB is generous enough for a client whose canvas
        // re-encode failed, small enough it can't fill the disk.
        'max_upload_kb' => (int) env('VISION_MAX_UPLOAD_KB', 8192),

        /*
         * Retention: the ORIGINAL is deleted after this many days; the 256px
         * thumbnail is kept and `meals.photo_path` repointed at it.
         *
         * `meal_items` is the record of what was eaten — the photo is
         * evidence for a decision already made, and a year of dinners on
         * disk is a liability with no matching benefit. The thumbnail stays
         * because a picture beside each meal is how a human recognises
         * "that Tuesday".
         */
        'photo_retention_days' => (int) env('VISION_PHOTO_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meal memory (step 6)
    |--------------------------------------------------------------------------
    |
    | Accuracy compounds with use: a confirmed meal pre-fills next time it's
    | photographed, and often-eaten meals float to the top of the picker.
    | Every number here is a judgement about how much the past should be
    | trusted — none is a fact about food, and none belongs in a migration.
    |
    */

    'memory' => [

        /*
         * Partial matching: a proposal is matched against a remembered meal
         * by CONTAINMENT (shared item slugs / union of both sets — the
         * Jaccard index); only an exact fingerprint hit skips it.
         *
         * 0.60 against the two cases that matter: chicken+rice+broccoli vs
         * +olive oil is 3/4 = 0.75 (matched — the model spotted the oil);
         * chicken+rice vs +broccoli+yoghurt is 2/4 = 0.50 (not matched — half
         * a dinner is a different dinner). Below ~0.5 a single shared staple
         * (rice) would pull in every rice-bearing meal ever logged.
         */
        'containment_threshold' => 0.60,

        /*
         * Frecency half-life, in days, for the picker's ranking:
         *
         *     score = ln(1 + times_logged) * 0.5 ^ (days_since_last / half_life)
         *
         * The log stops a 300x-logged breakfast from permanently dominating
         * the list; the decay favors "what I'm eating this month" over "what
         * I ate all last year". At 14 days, one meal eaten yesterday
         * outranks one eaten four times two months ago.
         */
        'frecency_half_life_days' => 14,

        /*
         * pg_trgm similarity floor for the search box. Postgres' own `%`
         * default is 0.3 and is a good one: "chicken brest" scores 0.71
         * against "chicken breast", an unrelated food scores under 0.1. Set
         * explicitly rather than trusting the session GUC, which a pooled
         * connection may have been left with anything in.
         */
        'search_similarity' => 0.30,

        // Remembered meals shipped with the daily view (a row scanned, not
        // read) vs returned by the search endpoint (a list actually looked at).
        'picker_limit' => 10,
        'search_limit' => 20,

        /*
         * Ceiling on remembered meals scored in PHP for a partial match.
         * Candidates are pre-filtered in SQL (share ≥1 item slug, ordered by
         * times_logged), so this only bites a pathological history — but an
         * unbounded loop in a queue job the user is watching isn't left to
         * luck.
         */
        'match_candidates' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | TDEE back-calculation (step 7)
    |--------------------------------------------------------------------------
    |
    | The differentiating feature: expenditure is FITTED from what actually
    | happened, not predicted from a BMR formula with height plugged in.
    |
    |   TDEE = mean(kcal_in_mid over complete-log days in the window)
    |          − kcal_per_kg × OLS slope of the EMA-smoothed weight series
    |
    | Every number below is a judgement about how much evidence is enough:
    | `tdee_estimates` is fully derived, so changing one and running
    | `php artisan tdee:estimate` is the whole procedure.
    |
    */

    'tdee' => [

        /*
         * Energy density of body mass, kcal/kg. 7700 is conventional
         * (~1kg adipose at ~87% lipid × 9.4 kcal/g) — an approximation, and
         * the largest systematic assumption here: real tissue lost mixes
         * fat, glycogen and bound water, which carries a fraction of that
         * energy. The EMA exists for exactly this — smoothing stops a
         * three-day water swing reading as 2,300 kcal of tissue.
         */
        'kcal_per_kg' => (float) env('TDEE_KCAL_PER_KG', 7700),

        /*
         * Rolling window in local days, ending TODAY. `window_days` is the
         * ceiling (SPEC: 14-28 days), `min_window_days` the floor. Both gate
         * counts are non-decreasing as the window grows, so "the longest
         * window ≤28 days that clears the gate" is the 28-day one whenever
         * any window clears it — the floor stops the front-trim (TdeeWindow)
         * from going shorter than SPEC allows.
         */
        'window_days'     => (int) env('TDEE_WINDOW_DAYS', 28),
        'min_window_days' => 14,

        /*
         * The gate: below EITHER, the UI says "collecting data" and nothing
         * is written to `tdee_estimates`.
         *
         * 14 complete-log days, since the intake mean is what the whole
         * estimate rests on. 8 weigh-ins, since an OLS slope through fewer
         * points has a standard error wide enough to swallow the answer.
         *
         * TdeeEstimate::MIN_DAYS / MIN_WEIGHINS mirror these as class
         * constants (meetsGate() falls back to them) — THIS is the source of
         * truth, those are the shipped defaults.
         */
        'min_complete_days' => 14,
        'min_weighins'      => 8,

        /*
         * Stamped on every row and part of its unique key, so estimator
         * changes add rows beside the old ones instead of rewriting history.
         * Bump on a FORMULA change — a threshold move above is a re-run, not
         * a new method.
         */
        'method_version' => 'v1',

        /*
         * Coalescing for RecomputeTdee, seconds. One ingest batch can dirty
         * two days, a replay ninety — all inside the window, each otherwise
         * queuing its own recompute. Same shape as the rollup's delay, same
         * reason.
         */
        'recompute_delay_seconds' => 60,
        'recompute_unique_for'    => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest freshness (the "last successful sync" indicator, step 8)
    |--------------------------------------------------------------------------
    |
    | Health Auto Export runs on a phone that won't tell you if it's stopped.
    | The only honest signal is the server clock against the last payload, so
    | the age is computed here and shipped with the daily view rather than
    | polled from the public /api/health endpoint by the browser.
    |
    | 36 HOURS, NOT 12. A locked iPhone has no HealthKit access, so an
    | overnight gap is NORMAL (Low Power Mode and a day away from a charger
    | stretch it further). A threshold that fires before ~36h cries wolf on a
    | healthy Tuesday morning — and an indicator that's usually orange is one
    | nobody reads.
    |
    */

    /*
    | THE BODY CEILING is the other half of this protection, and belongs here
    | rather than in nginx: the vhost's client_max_body_size is 100MB because
    | MEAL PHOTOS share that server block, and a limit sized for a photo is no
    | limit at all for a JSON body about to be json_decode()d into PHP arrays
    | at roughly ten times its own size.
    |
    | 8MiB against a 256MB memory_limit (docker/app/php.ini). A real hourly
    | export is ~250 datapoints, two orders of magnitude under this; the
    | largest legitimate case — a catch-up batch after a signal-less day —
    | still fits comfortably. Anything above isn't an export and is refused
    | BEFORE the decode, when the memory hasn't been spent yet.
    */

    'ingest' => [
        'stale_after_hours' => (int) env('HEALTH_INGEST_STALE_HOURS', 36),
        'max_body_bytes'    => (int) env('HEALTH_INGEST_MAX_BODY_BYTES', 8 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workouts
    |--------------------------------------------------------------------------
    |
    | A second HAE automation sends `data.workouts[]` to the same endpoint.
    | One session is one row in `workouts`, keyed on HealthKit's own UUID.
    |
    | THE THRESHOLD, AND WHY IT EXISTS: one captured session ran 2026-07-07
    | 20:56 to 2026-07-11 20:56 — a hike whose timer was never stopped, four
    | days at an average HR of 70bpm. Imported faithfully and flagged
    | `is_implausible` (readers computing over sessions skip flagged rows) —
    | a 96-hour "session" would otherwise swamp a fortnight's total.
    |
    | 12 hours: the longest genuine session in two years is just under five
    | (a 4h58m Tai Chi class). A marker, never a filter — the day view shows
    | the row WITH the caveat, so leaving a timer running stays visible.
    |
    */

    'workouts' => [
        'max_plausible_hours' => (float) env('HEALTH_WORKOUT_MAX_HOURS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sleep: when only part of a night has arrived
    |--------------------------------------------------------------------------
    |
    | This is a claim about DELIVERY, never about the wrist — a stub session
    | plus missing overnight basal buckets means the export hasn't fully
    | landed yet, not that sleep wasn't recorded. ONLY GAPS OUTSIDE THE
    | SESSION COUNT: a bucket-hole inside the reported session proves nothing
    | (the session itself is evidence its data arrived). See
    | docs/rationale-data.md § "Sleep — partial night arrival" for the
    | incident this guards against and the threshold derivations.
    |
    */

    'sleep' => [
        'night' => [
            'starts_at'           => '22:00',
            'ends_at'             => '08:00',
            'missing_fraction'    => 0.2,
            'watch_lookback_days' => 14,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | On pace (the in-progress day's projected balance)
    |--------------------------------------------------------------------------
    |
    | Today's card sets a finished food log against burn only two-thirds
    | accrued, so it's structurally reddest just after a meal and mellows by
    | midnight. Resting burn is near-constant on complete days (1,129.30 and
    | 1,142.27 kcal on 15/16 Aug 2026) so the rest of today's can be STATED
    | from the median of recent complete days. Active energy is never
    | projected — an afternoon walk isn't a constant.
    |
    | BASIS_DAYS 14 is long enough that one feverish week can't set the
    | level, short enough to follow a real body-mass change. MIN_DAYS 3 is
    | the floor below which the median is one person's Tuesday. Median, not
    | mean, because a single mis-synced day is the failure mode here.
    |
    */

    'pace' => [
        'basis_days' => (int) env('HEALTH_PACE_BASIS_DAYS', 14),
        'min_days'   => (int) env('HEALTH_PACE_MIN_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | PWA (step 8)
    |--------------------------------------------------------------------------
    |
    | The identity the home-screen icon and manifest carry. Config rather
    | than a literal because the same three colours appear in four places —
    | the manifest, two media-scoped <meta name="theme-color"> tags, and the
    | offline page's stylesheet — and they must agree.
    |
    | `theme_color`/`background_color` are the LIGHT values: the manifest
    | format carries exactly one of each with no media-query form, so dark
    | lives only in the meta tags. Matters less on iOS, which takes its
    | status bar from the meta tag.
    |
    */

    'pwa' => [
        'name'       => env('PWA_NAME', 'Health Tracker'),
        'short_name' => env('PWA_SHORT_NAME', 'Health'),

        // stone-50 / stone-950 — the two <body> backgrounds in app.blade.php.
        'theme_color'      => '#fafaf9',
        'theme_color_dark' => '#0c0a09',
        'background_color' => '#fafaf9',

        // teal-600 — the accent, and the icon's field colour.
        'accent_color' => '#0d9488',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trends
    |--------------------------------------------------------------------------
    |
    | Daily weight noise is ±1kg, so raw points are unreadable as a trend.
    | The EMA is computed server-side (one implementation, testable) over
    | days with an actual weigh-in — gaps are skipped, never interpolated, so
    | an invented weight can't feed a real TDEE slope later.
    |
    */

    'trends' => [
        'ema_alpha' => 0.25,
        'ranges'    => [7, 28],

        /*
        |----------------------------------------------------------------------
        | The energy chart's one-line caption (BalanceTally)
        |----------------------------------------------------------------------
        |
        | How many FULLY LOGGED days a window needs before the card says
        | which way it came out.
        |
        | 2 is a floor, not an optimum: one day isn't a pattern. Above two it
        | stops describing the window and starts filtering it — this user's
        | log is complete on ~3 of 7 typical days, and a threshold of four
        | would mean the line almost never appears.
        |
        | Cannot be set below 2; see BalanceTally::from.
        */
        'balance_min_days' => 2,

        /*
        |----------------------------------------------------------------------
        | The body card (weight, and what the scale measures alongside it)
        |----------------------------------------------------------------------
        |
        | Own ranges (not the 7/28-day energy/steps ones) because body
        | composition moves on a seasonal clock and the whole series is a
        | few dozen rows, cheap to ship once. GAP_DAYS draws the line DASHED
        | past a real weigh-in gap (this history has a 57-day one) so
        | continuity never looks like unbroken measurement. DEFAULT_MIN_POINTS
        | picks the opening range by data density rather than a fixed default.
        | EXPORT (below) marks the one series that arrives by hand, with its
        | own staleness answer rather than the card's.
        |
        | See docs/rationale-data.md § "Trends — body card ranges" for the
        | full derivation, including why DEFAULT_MIN_POINTS is 4 and not 10
        | or 6.
        |
        */

        'body' => [
            'gap_days'           => 21,
            'default_min_points' => 4,

            'ranges' => [
                // A week of daily weigh-ins — seven rings with room for a
                // number on each, one label per day along the bottom.
                ['key' => '1w', 'label' => '1w', 'days' => 7, 'title' => 'a week'],
                ['key' => '4w', 'label' => '4w', 'days' => 28, 'title' => '4 weeks'],
                ['key' => '3m', 'label' => '3m', 'days' => 91, 'title' => '3 months'],
                ['key' => '6m', 'label' => '6m', 'days' => 182, 'title' => '6 months'],
                ['key' => '1y', 'label' => '1y', 'days' => 365, 'title' => 'a year'],
                ['key' => 'all', 'label' => 'All', 'days' => null, 'title' => 'all time'],
            ],

            /*
             * Series the chips switch between, in order. `metric` null means
             * the established source of truth (daily_summaries.weight_kg,
             * same column TDEE fits its slope to); the other two read
             * through health_metrics' same instant-pick rule (the scale
             * reports each reading twice) so a day is still one number.
             *
             * body_water_percentage/bone_mass are excluded on purpose:
             * 51.8-53.5% and 2.5-2.6kg over fifteen months is a flat line
             * that would need a magnified axis to show anything.
             *
             * `min_span`: the smallest axis window in the series' own unit —
             * a fortnight where weight moved 200g mustn't auto-scale into a
             * mountain range.
             */
            'series' => [
                [
                    'key'      => 'weight',
                    'label'    => 'Weight',
                    'metric'   => null,
                    'unit'     => 'kg',
                    'digits'   => 1,
                    'min_span' => 2.0,
                ],
                [
                    'key'      => 'fat',
                    'label'    => 'Body fat',
                    'metric'   => 'body_fat_percentage',
                    'unit'     => '%',
                    'digits'   => 1,
                    'min_span' => 2.0,
                ],
                [
                    'key'      => 'muscle',
                    'label'    => 'Muscle',
                    'metric'   => 'muscle_mass',
                    'unit'     => 'kg',
                    'digits'   => 1,
                    'min_span' => 2.0,

                    /*
                     * THE ONE SERIES NOTHING SYNCS. HealthKit has no
                     * muscle-mass type (FitageColumns), so every reading here
                     * came from a hand-run `fitage:import`; the chart just
                     * stops when exporting stops, looking identical either
                     * way. The user asked for the missing half: "tell me the
                     * last scale so that i know from when should i manually
                     * export again."
                     *
                     * The key's presence marks a series as hand-fed; `label`
                     * names the act. BodyTrend knows nothing of scales or
                     * FITAGE — the stated date is MAX(local_date) of whatever
                     * series carries the key.
                     */
                    'export' => [
                        'label'      => 'Scale export',
                        'stale_days' => 14,
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplements (tier 1)
    |--------------------------------------------------------------------------
    |
    | The one judgement call: when does the day view start looking like it
    | wants something? 18:00 because these are taken with or after the
    | evening meal, and the emphasis must land while there's still time to
    | act — earlier looks impatient since mid-afternoon.
    |
    | Only ever an appearance — no notifications here, none planned. See
    | SPEC.md's "Supplements (tier 1)" for why push is deliberately out of
    | scope.
    |
    */

    'supplements' => [
        'evening_hour' => (int) env('SUPPLEMENTS_EVENING_HOUR', 18),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stress monitor (HRV against the user's OWN baseline)
    |--------------------------------------------------------------------------
    |
    | The commercial app this replaces scores stress 1-99 against a
    | population. This one scores it against the person: every HRV sample is
    | compared with what THIS user's HRV usually is at that hour, and the
    | day's score is how far it sits from their own last two months.
    |
    | The whole computation:
    |
    |   1. work in ln(HRV)              — right-skewed; log residuals are
    |                                     near-symmetric (sd 0.375 log units)
    |   2. subtract a circadian offset  — c[h], median ln-HRV of hour h vs
    |                                     that day's own median
    |   3. day level = median of the deseasonalised samples
    |   4. z = (level − median of last `baseline_days` levels)
    |          / (1.4826 × MAD of those levels)
    |   5. score = a smooth 1-99 squash of z, anchored (see `scale`)
    |
    | NOTHING here is a fact — every number is a judgement, which is why it's
    | config: `stress_daily` is fully derived and `php artisan
    | stress:rebuild --all` is the entire procedure for changing one.
    |
    */

    'stress' => [

        // Named metric rather than assumed — the same machinery works on any
        // circadian, log-normal, hourly-bucketed signal, so the next one
        // (respiratory rate) shouldn't need a code change to try.
        'metric' => 'heart_rate_variability',

        /*
         * THE CIRCADIAN PROFILE — the honest half of this feature. HRV swings
         * ~50% between morning and night for reasons that have nothing to do
         * with stress; this subtracts an hour-of-day offset (c[h]) before
         * anything is scored, so the app doesn't cry "pay attention" every
         * evening. `profile_days` is a year, not the whole history, so a
         * shift-pattern or season change surfaces in a season. See
         * docs/rationale-data.md § "Stress — circadian profile and rolling
         * baseline" for the measured swing and the two minimums' reasoning.
         */
        'profile_days'             => 365,
        'profile_min_day_samples'  => 3,
        'profile_min_hour_samples' => 10,

        // THE ROLLING BASELINE: 60 days ending the day BEFORE the one scored
        // (today vs the past, never itself) — long enough that the spread
        // isn't itself noise, short enough to track real drift. See
        // docs/rationale-data.md § "Stress — circadian profile and
        // rolling baseline" for the gates and floor.
        'baseline_days'     => 60,
        'min_baseline_days' => 21,
        'min_day_samples'   => 3,
        'min_spread_log'    => 0.03,

        /*
         * THE SCORE: 1-99, HIGHER = LESS STRESS (matches the app this
         * replaces). score = 1 + 98 × Phi((z - z0) / s); s and z0 derive from
         * two anchors: `baseline_score` 65 puts an on-baseline day mid-Normal
         * rather than on the Normal/Pay-attention boundary (z=0→50 would flag
         * half of an ordinary life); `great_z` 0.85 is where Great starts —
         * roughly the top fifth of days. See docs/rationale-data.md §
         * "Stress — score anchors" for the measured 21/55/21/3% band split.
         */
        'scale' => [
            'min'            => 1,
            'max'            => 99,
            'baseline_score' => 65,
            'great_z'        => 0.85,
            // Band floors, highest first. Read by StressBand; changing one
            // here changes the label, colour and legend together.
            'bands' => [
                'great'     => 80,
                'normal'    => 50,
                'attention' => 20,
                'overload'  => 1,
            ],
        ],

        /*
         * COVERAGE. The Watch isn't worn 24/7; a 4-reading day mustn't
         * present like a 24-reading one. These two numbers only decide what
         * the badge SAYS — the score is computed identically above
         * `min_day_samples`, so shrinking a sparse day toward 65 would hide
         * a thin day inside a confident number, the exact trade this
         * feature refuses.
         */
        'confidence' => [
            'high_samples'   => 16,
            'medium_samples' => 8,
        ],

        /*
         * THE HOUR × WEEKDAY HEATMAP. 90 days: long enough for ~13
         * observations/cell, short enough that "my Monday mornings" still
         * means this season's. The window is printed on the card — a
         * heatmap without one claims all of history.
         *
         * `min_cell_samples` 3 — below it a cell draws EMPTY, not
         * interpolated: an hour of the week the Watch was never on for is a
         * hole and should look like one. Cells are deseasonalised like days,
         * so the grid shows the WEEK, not the clock.
         */
        'heatmap_days'     => 90,
        'min_cell_samples' => 3,

        // Trailing days the nightly rebuild recomputes. A score depends on
        // the 60 days behind it, so a late export can move days after it;
        // 90 covers that for one query's cost.
        'rebuild_days' => 90,

        /*
         |----------------------------------------------------------------------
         | THE WEEKLY DIGEST — the analysis section under the chart
         |----------------------------------------------------------------------
         |
         | The commercial app being replaced prints a most/least stressful
         | day, HRV and stress "dynamic" percentages, and a few "moments" —
         | then compares against "the average range for your age and gender"
         | and guesses causes ("alcohol, water, illness").
         |
         | The first half is rebuilt here. The second is not in this app at
         | all: no population range anywhere, and nothing proposes a cause. A
         | number and its window; the reader does the rest.
         */
        'digest' => [

            /*
             * SCORED days a week needs before entering a week-on-week
             * comparison, applied to both weeks. 4 of 7 is a majority; below
             * that a "mean" is two or three days wearing the word, and "not
             * enough scored days to compare" beats a delta someone would
             * quote. Same gate governs the 7-day HRV percentages.
             */
            'min_week_days' => 4,

            /*
             * NOTABLE MOMENTS: a reading scanned against the user's own
             * deseasonalised hourly distribution (same z as the daily
             * score). 2.5 sigma / 3 per day-direction produces two or three
             * a week on real data, never daily noise. See
             * docs/rationale-data.md § "Stress digest — notable moments
             * threshold" for the derivation table.
             */
            'moment_min_z' => 2.5,
            'moment_max'   => 3,

            /*
             * Resting-heart-rate tile: one value/day from the Watch (930
             * since April 2023), a lookup not a rollup. 90 days read: enough
             * for a stable personal median beside today's number, short
             * enough to be this season's rather than this year's. Not a
             * population range, and deliberately not a word like "good" —
             * the only comparison is with the same person's own last three
             * months.
             */
            'resting_metric'      => 'resting_heart_rate',
            'resting_window_days' => 90,
        ],

        /*
         * Stamped on every row. Bump on a METHOD change, not a threshold
         * move (a re-run, not a new answer). Unlike `tdee_estimates`, NOT
         * part of row identity — see the migration for why a stress row is
         * keyed on date alone.
         */
        'method_version' => 'v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | The written health report
    |--------------------------------------------------------------------------
    |
    | One Claude call per report, over a range of local days, from facts
    | this app assembles first — the model is never asked to do arithmetic.
    | Almost everything below is about the SHAPE OF THE QUESTION, not the
    | answer.
    |
    | `max_tokens` is the one genuine judgement call, reasoned out below:
    | getting it wrong doesn't degrade the report, it destroys it (truncated
    | JSON is unparseable).
    |
    */

    'report' => [

        // Recorded on every health_reports row — changing this doesn't
        // rewrite what old reports say produced them.
        'model' => env('REPORT_MODEL', 'claude-opus-5'),

        // Ceiling on the whole response — thinking and JSON together, both
        // ON BY DEFAULT on claude-opus-5. ~3x headroom over a MEASURED
        // 4,698-token live report; failure here is total (truncation is
        // unparseable, not shorter). See docs/rationale-data.md §
        // "Report — token budgets".
        'max_tokens' => (int) env('REPORT_MAX_TOKENS', 16000),

        /*
         * Effort, stated explicitly rather than left to the API default.
         * `high` IS today's default — this changes nothing now, but a
         * silent default change would move report quality with no diff to
         * point at. Reading a cross-referenced fortnight and finding the
         * pattern is the task this level exists for.
         */
        'effort' => env('REPORT_EFFORT', 'high'),

        /*
         * Seconds, enforced by the HTTP client handed to the SDK — its own
         * `timeout` option is advisory and never read in its source.
         *
         * FIVE MINUTES against vision's two: 16,000 tokens of
         * thinking-plus-answer at opus-5's rate is minutes, not seconds, and
         * a report cut off at 120s is a bill paid for nothing. It's queued
         * with nobody watching a spinner, so the latency only costs
         * patience.
         */
        'connect_timeout' => (float) env('REPORT_CONNECT_TIMEOUT', 5),
        'timeout'         => (float) env('REPORT_TIMEOUT', 300),

        // ONE retry inside the SDK (429/5xx/connection). Job is `tries = 1`
        // too, same as vision: a refusal or bad answer will refuse again in
        // 30s, and Generate is right there.
        'max_retries' => (int) env('REPORT_MAX_RETRIES', 1),

        /*
         * What a report may cover — a ceiling, not a preference. Facts are
         * one row/day and the aligned day table dominates the prompt, so an
         * unbounded range is an unbounded bill. 92 days is a quarter, past
         * anything the presets offer and still well inside context.
         */
        'max_range_days' => (int) env('REPORT_MAX_RANGE_DAYS', 92),

        // How the day table folds into weekly/monthly buckets (DayBuckets)
        // past these lengths — a row/day fits a fortnight, not a year. Only
        // a focused report reaches past daily/92 days. See
        // docs/rationale-data.md § "Report — token budgets" for the 365-day
        // failure this prevents.
        'buckets' => [
            'daily_max_days'  => (int) env('REPORT_BUCKET_DAILY_MAX_DAYS', 92),
            'weekly_max_days' => (int) env('REPORT_BUCKET_WEEKLY_MAX_DAYS', 210),
        ],

        /*
         * FOCUS: what the report was asked to be about. A focused report
         * genuinely omits the blocks outside its focus (ReportFocus), which
         * is what makes a longer range safe:
         *
         *   full   Any focus including Food, and every unfocused report.
         *          The food ledger is ONE ENTRY PER ITEM, not per day, so
         *          it's the only block not bounded by day count — keeps the
         *          quarter it always had.
         *   lean   Everything else (stress, sleep, training, weight, any
         *          mix). Every block is linear in days, so a year is
         *          arithmetic, not a gamble. 366 so "the last year" is
         *          expressible on a leap year.
         */
        'focus' => [
            /*
             * ONLY the lean ceiling lives here — the full one is
             * `max_range_days` above, still the ceiling for any report that
             * assembles the food ledger. A copy here would be a second
             * number to keep in step.
             */
            'max_range_days_lean' => (int) env('REPORT_FOCUS_MAX_RANGE_DAYS', 366),

            // Extra chips the form offers once focus makes them legal —
            // greyed with the reason otherwise, rather than appearing and
            // disappearing under a thumb.
            'presets' => [90, 182, 365],
        ],

        // Defensive ceiling on the assembled DOCUMENT, not the day count — a
        // fuse against a snapshot that's grown an order of magnitude, refused
        // cheaply rather than discovered as a 400 or a truncated paid-for
        // answer. See docs/rationale-data.md § "Report — token budgets".
        'max_input_tokens' => (int) env('REPORT_MAX_INPUT_TOKENS', 150_000),

        // Chips on the generate form; free custom ranges are also allowed —
        // these are the four somebody actually taps.
        'presets' => [3, 7, 14, 30],

        /*
         * PRICES, USD/MILLION TOKENS, FROZEN ONTO EACH ROW. claude-opus-5
         * list rates — kept here because they change, and
         * `health_reports.cost_usd` is computed at write time from whatever
         * they were then, so an old report keeps its real cost rather than
         * being re-priced by today's table.
         */
        'price_per_mtok' => [
            'input'  => (float) env('REPORT_PRICE_INPUT', 5.00),
            'output' => (float) env('REPORT_PRICE_OUTPUT', 25.00),
        ],

        /*
         * The baseline-drift block: answers the daily stress score's known
         * blind spot. That score is RELATIVE (a day vs the 60 days behind
         * it) — right for "was yesterday unusual?", blind to slow decline:
         * an HRV sinking 15%/year drags the baseline with it and every day
         * still scores 50. Three long-window comparisons are computed
         * deterministically here and handed over as facts.
         */
        'drift' => [
            // Same calendar window one year earlier — season-for-season,
            // since a 90-day lookback would report this user's visible
            // summer swing as a trend every autumn.
            'year_offset_days' => (int) env('REPORT_DRIFT_YEAR_DAYS', 365),

            // Rolling-baseline trend: least-squares slope through daily HRV
            // over the last N days, as %/30 days. 56 = eight full weeks, so
            // every weekday is equally represented.
            'trend_days' => (int) env('REPORT_DRIFT_TREND_DAYS', 56),

            // Below this many scored days in the trend window, no slope is
            // reported — two thirds of eight weeks.
            'trend_min_days' => (int) env('REPORT_DRIFT_TREND_MIN_DAYS', 37),

            // Resting-HR comparison: this window's median against the last
            // year's.
            'resting_median_days' => (int) env('REPORT_DRIFT_RESTING_DAYS', 365),

            // Below this many readings on either side, no comparison is
            // made — a "year median" from nine readings isn't one.
            'min_readings' => (int) env('REPORT_DRIFT_MIN_READINGS', 20),
        ],

        /*
         * Cross-cut anchor statistics computed HERE so no numeric claim in
         * the report is the model's own arithmetic. `min_group` is the
         * honesty gate — a "mean stress after a short night" from two
         * nights is noise with a decimal point; the cheapest fix is to not
         * compute it.
         */
        'anchors' => [
            'min_group'         => (int) env('REPORT_ANCHOR_MIN_GROUP', 3),
            'short_sleep_hours' => (float) env('REPORT_SHORT_SLEEP_HOURS', 6.0),
            'long_sleep_hours'  => (float) env('REPORT_LONG_SLEEP_HOURS', 7.0),
            // "Late" for the day's last meal, local clock hour — this
            // household's ordinary-vs-late boundary, not a clinical one.
            'late_meal_hour' => (int) env('REPORT_LATE_MEAL_HOUR', 21),
        ],

        /*
         * `prompt_version` makes a prompt change evaluable: the stored
         * `input_snapshot` can be replayed through a new prompt and diffed.
         * Version lives on PromptV1Report::VERSION, not here — it changes
         * with the text, same commit, so a config key isn't a second thing
         * to remember.
         */
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical units — NOT CONFIGURED HERE
    |--------------------------------------------------------------------------
    |
    | Step 1 sketched a `canonical_units` map here; step 2 implements it in
    | App\Services\Ingest\MetricCatalog instead, and this key is deliberately
    | gone rather than duplicated.
    |
    | It's not configuration — it's a per-metric fact about the export
    | format, asserted against in tests, read on every datapoint's hot path.
    | A second copy here would be a second answer to "what unit is
    | active_energy in?", and unlike a wrong config value, a divergence here
    | is invisible: the numbers still look like numbers.
    |
    | For the record, the conversions that actually run:
    |   active_energy, basal_energy_burned   kJ -> kcal   (÷ 4.184)
    |   sleep stages                         hr -> min    (× 60)
    | Everything else is stored exactly as delivered.
    |
    */

];
