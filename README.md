# circa

A health tracker honest enough to say "about".

circa tracks meals (photographed and read by a vision model), sleep, movement,
stress and energy as honest ranges rather than false precision — an estimate is
shown as the span it actually is, and a day with no data is shown as a gap
rather than as a zero.

A single-user PWA, self-hosted with Docker Compose. Full design is in
[`SPEC.md`](SPEC.md). This repository implements the **whole build order,
steps 0–8**: raw capture, schema, the ingestion pipeline, the
Inertia/Vue UI with the daily-summary rollup behind it, the barcode path
(in-browser scanning + Open Food Facts lookup with a local cache), the photo path
(camera → Claude vision → proposed items with ranges → confirm), meal memory,
TDEE back-calculation, and the PWA layer (installable, an offline IndexedDB
queue, persistent storage, a last-sync indicator).

## Routes

| Route | Method | Auth | Purpose |
| --- | --- | --- | --- |
| `/` | `GET` | session | Daily view. `?date=YYYY-MM-DD`, defaults to today |
| `/trends` | `GET` | session | 7 / 28-day charts. `?range=7\|28` |
| `/stress` | `GET` | session | Stress monitor. `?week=YYYY-MM-DD` (any date inside the week) |
| `/login` `/logout` | `GET`/`POST` | — / session | The whole auth surface |
| `/meals` | `POST` | session | Log a meal (client-generated `uuid`, idempotent) |
| `/meals/{uuid}` | `PUT`/`DELETE` | session | Edit / delete |
| `/meals/{uuid}/repeat` | `POST` | session | One-tap re-log onto another day |
| `/meals/{uuid}/proposal` | `PUT` | session | Confirm (and edit) what the model proposed — photo or text |
| `/api/meal-memory` | `GET` | session | The "log it again" picker: frecency-ranked, `?q=` searches with `pg_trgm` |
| `/meal-memory/{id}/log` | `POST` | session | One tap: log a remembered meal onto a day |
| `/api/products/{barcode}` | `GET` | session | Barcode lookup, cache-first. `?refresh=1` re-fetches |
| `/api/meals/photo` | `POST` | session | Upload one plate of a meal, start its analysis |
| `/api/meals/{uuid}/vision` | `GET` | session | Poll: what is still analysing or awaiting a tap |
| `/api/meals/{uuid}/photo` | `GET` | session | The first photograph, off a non-public disk |
| `/api/meals/{uuid}/photos/{client_id}` | `GET` | session | One specific plate |
| `/api/meals/{uuid}/photos/{client_id}` | `DELETE` | session | Remove one plate (`remove_items` decides its confirmed food) |
| `/api/meals/{uuid}/photos/{client_id}/vision` | `POST` | session | Analyse that plate again |
| `/api/meals/estimate` | `POST` | session | Save a typed meal and estimate its blanks from the words |
| `/api/meals/{uuid}/estimate` | `POST` | session | Estimate the blanks on a meal that is already saved |
| `/api/ingest` | `POST` | `X-API-Key` | Health Auto Export. **No session** |
| `/api/health` | `GET` | none | Liveness + last-successful-ingest indicator |
| `/manifest.webmanifest` | `GET` | none | Web app manifest. **No session** (see `routes/pwa.php`) |
| `/sw.js` | `GET` | none | Service worker, precache list injected from the Vite manifest |
| `/offline` | `GET` | none | Offline fallback page, precached by the worker |
| `/up` | `GET` | none | Laravel's built-in health route |

There is **no registration or password-reset route** — single user, by design.

## Step 0 — raw payload capture

The schema comes from real bytes, not from documentation. Before any metrics
model is designed, Health Auto Export points at a single endpoint that
authenticates, stores the POST body verbatim, and answers `202`. After a few
days of real payloads there is something concrete to design against — and the
14-day TDEE data clock has already started.

| Route | Method | Purpose |
| --- | --- | --- |
| `/api/ingest` | `POST` | Authenticated raw capture. `202 {"status":"accepted"}` |
| `/api/health` | `GET` | Liveness + last-successful-ingest indicator. `200` |
| `/up` | `GET` | Laravel's built-in health route |

`raw_ingest_payloads` is **permanent**. It is the replay source for every
future reprocessing run and backfill — see the note in the migration.

### Auth

`POST /api/ingest` requires an `X-API-Key` header matching `INGEST_API_KEY`,
compared with `hash_equals` (constant time). A mismatch returns `401` with no
detail and no echo of the supplied value. A body that is not a JSON object or
array returns `422`.

## Stack

- Laravel 13 on PHP 8.5 (+ **ext-gd** for the photo re-encode and thumbnails)
- Postgres 18 (`postgres:18-alpine`)
- Redis + Laravel Horizon — the ingest parser and the vision job run on the
  queue (`horizon` compose service, one worker, non-root)
- `scheduler` compose service (`schedule:work`) — photo retention, daily
- Anthropic PHP SDK (`anthropic-ai/sdk`), `claude-opus-5`, structured outputs
- Docker Compose — **every container runs as a non-root user from day one**
- `docs/rationale-app.md`, `docs/rationale-data.md`, `docs/rationale-frontend.md`
  — extracted design rationale; in-code `See docs/... §` comments point here
- `docs/DECISIONS.md` — the engineering *why*: how this repository is gated and
  kept honest, as opposed to why the domain code is shaped the way it is
- `CLAUDE.md` — contributor notes: how to run the gate, comment style, and the
  rule that the PHPStan baseline stays empty

### Container hardening

Beyond the non-root user, every service in `docker-compose.yml` carries:

| directive | where | why |
|---|---|---|
| `security_opt: no-new-privileges:true` | **every** service | a process can never gain privileges it did not start with; a setuid binary in some future base image loses its bit's effect |
| `cap_drop: ALL` | `app`, `horizon`, `scheduler`, `web`, `assets` | they start as a non-root user, bind nothing under 1024 and chown nothing — the kernel's 14 default capabilities are all unused |
| `read_only: true` + `tmpfs: /tmp` | `web` only | `docker/web/nginx.conf` already keeps the pid file and every `*_temp_path` under `/tmp`, and both its mounts are `:ro` |

`postgres` and `redis` deliberately **keep** their capabilities: both official
entrypoints start as root and drop privileges, which needs `CHOWN`, `SETUID`,
`SETGID`, `DAC_OVERRIDE` and `FOWNER`. Trimming them to exactly that list is a
follow-up with a smoke-test in front of it, not a one-line edit.

### Redis authentication

`REDIS_PASSWORD` in `.env` is read twice — by Laravel, and by the `redis`
service, which passes it to `--requirepass`. One value, so the two cannot drift.

**Empty (or the literal `null`) means no password**, in both places: the compose
services special-case both, because Laravel's `env()` reads `null` as PHP null
and compose would otherwise set the four characters `n-u-l-l` as the password
while the app went on connecting without one.

Turning it on is a two-part restart and the order matters:

```bash
# 1. put a value in .env  (openssl rand -hex 32)
# 2. restart redis AND every client, together:
docker compose up -d redis
docker compose restart app horizon scheduler
```

Redis starts demanding the password the moment it is recreated, and a php-fpm
holding the old config answers every request with `NOAUTH`. Restarting redis
alone is the one way to break this.

## Running it locally

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f app
```

### Building the front end (required — the site serves built assets)

There is no dev server in this setup. `public/build` must exist, and it is built
by a one-shot, profile-gated compose service so no node is needed on the host:

```bash
docker compose --profile build run --rm assets      # npm ci && vite build
```

It runs as the same non-root user as the app, so `node_modules/` and
`public/build/` come out correctly owned. It is NOT a multi-stage layer in the
app image on purpose: that image bind-mounts the whole project, so anything
COPYed there is shadowed at start-up and nginx would never see it.

After changing Blade or PHP, clear the compiled views — a Blade file whose
mtime has not changed keeps its cached compilation, including one made before a
package's directives were registered:

```bash
docker compose exec app php artisan view:clear
```

### Step 4 — the barcode path

Tap **Scan barcode** in the meal sheet. The camera opens, a hit stops it
immediately, the product is looked up and logged as a per-100 g item with the
grams you enter.

The decoder is `zxing-wasm` via Sec-Ant's `barcode-detector` wrapper, and it is
**lazy**: nothing about it is downloaded until that button is tapped.

```
public/build/assets/app-*.js               299.8 kB   (was 295.1 kB — +4.7 kB)
public/build/assets/BarcodeScanner-*.js      6.7 kB   ┐
public/build/assets/ScannedProduct-*.js      8.2 kB   ├ on first tap only
public/build/assets/barcode-reader-*.js     43.4 kB   │
public/build/assets/zxing_reader-*.wasm  1,065.6 kB   ┘  (453 kB gzip)
```

The `.wasm` is served from **this** origin. zxing-wasm's stock `locateFile`
points at fastly.jsdelivr.net; `resources/js/lib/barcode-reader.js` overrides it
with a Vite-emitted asset URL, so scanning does not depend on a third-party CDN
being reachable. nginx serves `/build/` with `Cache-Control: immutable` — the
filenames are content-hashed, and re-validating a megabyte on mobile data every
time the meal sheet opens is the cost the lazy chunk exists to avoid.

Open Food Facts needs **no key**, allows 15 req/min/IP and requires an
identifying User-Agent (`config/health.php` → `food.user_agent`). Everything
fetched is cached in `food_products` forever; nothing expires.

```bash
# What has been scanned, and when it was last read from OFF
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select barcode, name, brand, kcal_per_100g, fetched_at from food_products
        order by fetched_at desc limit 20;"

# Re-read one product (the only thing that bypasses the cache)
curl -s 'http://localhost:8080/api/products/3017624010701?refresh=1' -b cookies.txt
```

### Step 5 — the photo path

> Superseded in places by **Step 10 — a meal is a series of plates** below,
> which turns one photograph per meal into several. Everything here about the
> file inputs, the re-encode, the audit row and the ranges still holds; the state
> machine and the review screen have moved on.

Tap **Photo** on the daily view, then either **Take photo** or **Choose photos**.

Both are plain file inputs — Apple's own sheets, not `getUserMedia`, so there is
no permission prompt before you have framed the shot. They are two inputs rather
than one because `capture="environment"` is a one-way door: it opens the rear
camera *and makes the photo library unreachable*, which is right for the plate in
front of you and useless for the one you photographed at lunch and are logging on
the train home. The second input simply omits the attribute, and iOS offers the
library. From there it is one code path.

The photo goes onto **the day you are looking at**, not today — the sheet says so
when they differ. The browser shrinks the shot to 1024 px through a canvas,
uploads it, and the meal appears immediately as **Analysing…**; when Claude
answers it becomes **Needs your OK** with editable ranges, and nothing reaches the
day's totals until you tap **Confirm**.

```
photo ──► meals.status: analyzing ──► proposed ──► confirmed
                       │                 │
                       └──► failed ◄─────┘   (Re-analyze / Discard)
```

The buttons on the review screen are the state machine: **Confirm**
(`proposed → confirmed`, items get `confirmed_at`), **Analyse again** (a NEW
`vision_requests` row against the same photo), **Discard** (the meal and its
images go).

- Model `claude-opus-5`, prompt `v3-photo` (was `v2-photo`, was `v1`), structured outputs (`output_config.format`
  with a JSON schema) — so the first text block *is* the JSON, with no prose to
  strip. Everything the model returns is a **range**.
- Every call is logged in `vision_requests` — model, prompt version, image
  sha256, raw response, token counts, latency, status, error. That is what makes
  a prompt change evaluable instead of a matter of opinion.
- `idempotency_key` is generated at capture: a double-tap, or an offline queue
  replaying the upload, costs **one** API call.
- The server re-encodes every upload. That is what strips EXIF (an iPhone photo
  of a plate carries the GPS coordinates of your kitchen) and what enforces the
  1024 px ceiling, rather than trusting the browser to have done it.

```bash
# What has been analysed, what it cost, and how long it took
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select id, meal_id, model, prompt_version, status,
             input_tokens, output_tokens, latency_ms, error
        from vision_requests order by id desc limit 20;"

# Photos on disk (a dedicated, non-public filesystem disk)
docker compose exec app ls -R storage/app/private/meal-photos | head
```

**The API key.** `ANTHROPIC_API_KEY` in `.env`, read through
`config('services.anthropic.api_key')`. It is this app's **own dedicated key**,
swapped in on 2026-08-09 and validated against a live call; the borrowed
Reflection key it replaced was removed the same day. It lives in `.env` on the
server and is not in this repository — nothing here, including this file, should
ever carry the value.

### Step 9 — "describe it" (text estimation)

The fourth entry mode, and the one for a meal you did not photograph and cannot
weigh. **Every number in the meal form is now optional** — only the item name is
required. Type "Chicken curry", leave the boxes empty, and the primary button
becomes **Save & estimate**, with a quiet **Save as-is** beside it.

```
typed meal ──► meals.status: analyzing ──► proposed ──► confirmed
     (blank)              │                   │
                          └──► failed ◄───────┘   (Estimate again / Confirm as typed)
```

It is the photo pipeline with a sentence where the picture goes: same model, same
JSON schema, same `vision_requests` audit row, same review sheet — which is why
`PhotoReview.vue` is now `ProposalReview.vue`. What it reviews is a meal in
`proposed`; how the meal got there changes four sentences and one button.

- Prompt `v1-text` (`App\Services\Vision\PromptV1Text`), versioned separately
  from the photo prompt so improving one does not invalidate the other's history.
- **A number you typed is never changed.** Not by the model, not by meal memory.
  The prompt asks; `TypedMeal::preserve()` guarantees it, and the tests assert it
  against a fake that deliberately answers with contradicting figures. Give the
  rice 210 kcal and it is still exactly 210 after the estimate — the model only
  gets to say how much rice there was.
- **Nothing you listed disappears.** If the model forgets an item, it is put back
  as the row an ordinary save would have written.
- The ranges are wider than a photo's, deliberately. The model cannot see the
  bowl, so the prompt tells it to widen rather than assume a standard serving.
- Already saved a meal with blanks? Open it and the same button is there. That
  creates a NEW `vision_requests` row with a new idempotency key — "ask again"
  and "the same request arrived twice" are different intentions.
- Offline, **Save & estimate** queues like any other write and the estimate runs
  when it syncs. Asking on an already-saved meal needs a connection, and says so.

Two additive columns carry it: `vision_requests.request_kind` (`photo` | `text`)
and `vision_requests.input_payload` (the meal as typed). The kind is stated
rather than inferred from a null `image_sha256`, because re-analysing a photo
also has a null sha256 — the two would be indistinguishable in exactly the query
the audit table exists for. `input_payload` later took a second tenant: on a
`photo` row it holds `{"hint": "…"}`, the note the user wrote about that plate
(step 12).

```bash
# What the text path has cost, next to the photo path
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select request_kind, count(*), avg(input_tokens)::int, avg(latency_ms)::int
        from vision_requests group by request_kind;"
```

### Step 10 — a meal is a series of plates

A dinner is often more than one photograph: the main course, the second helping,
the pudding that turns up twenty minutes later. A meal now holds **several
photos**, and each one is its own answer.

Tap **Photo**, then **Take photo** (again, and again — each tap adds a course) or
**Choose photos** (multi-select straight out of the camera roll). Each plate is
uploaded and analysed **the moment it is shrunk**, so by the time you have framed
the dessert the main course has already come back. There is no batch step and no
"analyse" button: waiting to batch them would trade responsiveness for nothing.

To add a course later — including to a meal you already confirmed — open it and
tap **Add another course**, or the **+** on the photo strip.

```
plate 1 ──► analysing ──► proposed ──► confirmed ─┐
plate 2 ──────────► analysing ──► proposed ──► confirmed ─┤──► one meal
plate 3 ─────────────────► analysing ──► proposed ────────┘
```

**Confirming a plate appends.** It never replaces another plate's items, or
anything you typed or scanned by hand. Each `meal_items` row remembers which
photograph it came off (`meal_photo_id`; NULL for typed, scanned and
text-estimated items), which is what makes that possible.

**The state machine has two halves**, and the split matters:

- **`meals.status`** is the meal's commitment, and **never goes backwards out of
  `confirmed`**. Photographing the pudding must not take the main course out of
  the day's totals.
- **Each plate** has its own state — analysing, failed, proposed, settled —
  derived from its own `vision_requests` row. That is what the review sheet shows
  and what the poller watches. The day card says "1 more photo to review".

**The model is told what is already logged.** Photograph the second helping with
the same salad still in shot and a model that knows nothing about the first
course would list the salad again — and since Confirm appends, the day would gain
a salad you never ate. So each call carries the names already on the meal, in
their own content block, with the instruction to list what is visible in *this*
photograph and to say so in the name if something already logged is genuinely
there again.

**Removing a plate.** *Remove this photo* takes the photograph and its
unconfirmed proposal. Anything you already confirmed off it **stays** (deleting a
picture is a statement about the picture) and simply loses its provenance.
*Discard the whole meal* is offered only while nothing on the meal has been
confirmed.

**One image per call, deliberately.** The API would take three in one message.
A combined answer could not be confirmed in pieces, re-analysed in pieces, or
audited in pieces — and all three are things you actually do to a dinner. So it
is one plate, one `vision_requests` row, one call. The bill scales with courses
rather than meals: a three-plate dinner is three calls at roughly the cost of one
photo each (~1.0–1.6k input tokens for a 1024 px JPEG plus the prompt).

```bash
# The plates of a meal, in order
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select p.position, p.client_id, p.path, r.status, r.prompt_version
        from meal_photos p
        left join vision_requests r on r.meal_photo_id = p.id
       where p.meal_id = 2 order by p.position;"

# Where every item came from
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select i.name, i.meal_photo_id, i.confirmed_at is not null as confirmed
        from meal_items i where i.meal_id = 2 order by i.id;"
```

### Step 11 — the shared plate ("I ate half of this")

The review sheet has a chip row above the items — **All · ¾ · ½ · ⅓ · ¼ · %** —
and every item row has the same control, compact, beside it. The plate's chip
moves every item that has not been set on its own; an item set on its own stops
following and keeps its value when the plate changes. Tapping an item's chip
back onto the plate's fraction releases it again.

Claude is never asked how much you ate — it estimates what is **on the plate**,
which is the only thing a photograph can settle, and the app multiplies
afterwards. The prompt is unchanged.

The same per-item control is on the meal edit sheet, so anything can be
fractioned after the fact: a typed line, a photographed plate, or a barcode
scan ("I ate half the chocolate bar" — the packet declares the whole bar).

```bash
# What was eaten, what was on the plate, and the fraction between them
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select i.name, i.portion_g_min, i.portion_full_g_min, i.share_fraction
        from meal_items i where i.share_fraction < 1 order by i.id;"

# The chip each plate is set to (what a re-analysis re-applies)
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select p.meal_id, p.position, p.share_fraction from meal_photos p
       where p.share_fraction < 1;"
```

`meal_items.portion_g_*` is what was **eaten** — every reader that already
existed keeps working untouched. `portion_full_g_*` is what was on the plate,
stored rather than divided back out, so changing ½ → ⅓ → All returns exactly
the estimate you started with instead of shaving a little off each time.
Densities are per 100 g and are never scaled: half a bowl of pho is still pho.

### Step 12 — the photo hint ("it was 3 eggs and 120 g of tuna")

**The recommended way to tighten a range on a photograph.** The camera sheet has
one optional line above the shutter — *"Tell Claude what you know — 3 eggs,
120 g drained tuna"* — and whatever is in it rides with that plate. Skip it and
nothing changes: no block is sent, no column is written, and the photo is asked
exactly the question it was asked before.

Fill it in and **what you say the food IS, and any amount you counted or
weighed, is used as given**. A weight you state fixes the portion at both ends
rather than being widened into a band; a count ("3 eggs") fixes how many, and
the grams of exactly that many are still estimated. Everything you did not
mention is estimated as usual, and everything visible that you did not mention
is still listed — the note is what you happened to know, not an inventory.

**It is editable afterwards, and that is the point.** The hint that actually
occurs to you is the one you think of at the sink. Open any photographed plate
and the note is under the picture: on the review sheet before you confirm, and
on a plate you confirmed yesterday. Editing it **does not re-analyse anything**
— it saves, and it promotes the button to *"Analyse this photo again with your
note"*. Asking again costs a call and replaces numbers you may have corrected by
hand, so it stays a tap you take. The note is kept either way; Confirm carries
it too.

One note per PLATE, not per meal (`meal_photos.hint`, nullable text): a dinner
is a series of courses, and what you know about the eggs is not what you know
about the pudding.

- **Prompt `v3-photo`.** The note is its own content block between the image and
  the instruction, never concatenated into either — the same rule the text path
  applies to a typed meal. The instruction itself moved to a new version because
  it had to: `v2-photo` says *"RANGES, ALWAYS. Never a point estimate"*, and it
  is sent last, so a note saying "120 g" would have been overruled by our own
  next sentence. v3 carves the exception ("RANGES, ALWAYS, **for everything you
  are estimating**"), exactly as `v1-text` has always done for a typed figure.
  `v2-photo` is untouched, so every row that says `v2-photo` was produced by it.
- **The audit row records it.** `vision_requests.input_payload` holds
  `{"hint": "…"}` on a hinted photo call — the same column the text path uses
  for the typed meal. `image_sha256` used to be the whole input to a photo
  analysis; it is not any more, and without this a hinted answer would look like
  the model changing its mind about the same photograph. The job sends what the
  ROW says rather than what the plate says, so editing a note while an analysis
  is in flight cannot rewrite the question after the fact.
- `vision:reproject` is unaffected: it rebuilds from `raw_response`, which is
  already an answer to the question the hint was part of.

```bash
# Which plates were hinted, and what was said
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select meal_id, position, hint from meal_photos where hint is not null;"

# What each hinted call was actually told (the audit half)
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select id, prompt_version, input_payload->>'hint' as hint
        from vision_requests
       where request_kind = 'photo' and input_payload is not null
       order by id desc limit 20;"
```

### Photo retention

Originals are deleted after `config('health.vision.photo_retention_days')` (90).
The 256 px thumbnail is kept and `meal_photos.path` is repointed at it, so the
meal keeps a picture you can recognise it by while the full-resolution image of
your kitchen does not sit on a VPS forever. `meal_items` is the record of what
was eaten; the photograph is evidence for a decision already made.

```bash
docker compose exec app php artisan photos:prune --dry-run
docker compose exec app php artisan photos:prune
docker compose exec app php artisan photos:prune --days=30
```

It runs itself daily at 03:20 local. **The scheduler is a compose service**
(`scheduler`, running `php artisan schedule:work`) rather than a host crontab: a
crontab would have to `docker compose exec` its way in, would run as whoever
owns it — the exact route by which Scribly's cache files ended up root-owned —
and would be silently missing from a fresh `docker compose up` on a new box.

```bash
docker compose logs scheduler --tail=20
docker compose exec app php artisan schedule:list
```

### Step 6 — meal memory

Accuracy compounds with use. Every confirmed meal is fingerprinted by the
**sorted, slugified set of its item names** (`sha256` of
`chicken-breast|white-rice`), and that fingerprint is the key to `meal_memory`:
how often the shape has been eaten, when it was last eaten, and a snapshot of
what was in it. Nothing hashes the photograph — the same dinner shot twice from
two angles produces unrelated bytes, and the item names are the stable part.

Three things use it:

- **The picker** (`/api/meal-memory`) replaces step 3's "recent meals" row.
  Ranked by frecency — `ln(1 + times_logged) * 0.5 ^ (days_since / 14)` — and
  searched with `pg_trgm`, against the meal's name *and* the names of the foods
  in it, so "salmon" finds "Tuesday bowl" and "chicken brest" finds dinner. One
  tap logs it onto the day as an ordinary confirmed meal.
- **Vision pre-fill.** When a photo's proposed items match a remembered meal —
  exactly, or by containment ≥ 0.60 — the remembered portion replaces the
  model's range *if it falls inside it*, and the remembered density replaces the
  model's *if it is narrower*. The review screen says "you have had this
  before · logged 7×" and marks the adjusted rows `from history`.
- **The daily view**, which ships the ranked list with the page.

The model's own answer is never rewritten: `vision_requests.raw_response` is the
audit row, `meal_items.memory_adjusted` is what memory changed, and
`meals.memory_match_id` / `memory_match_score` record what a proposal was
compared against.

**Memory is never seeded** — it starts empty and fills only from confirmed
meals. `times_logged` counts meals eaten, not forms submitted: editing a meal
without changing its items changes nothing, and changing its items counts the
new shape while the old aggregate keeps its count as history.

```bash
# What the app thinks you eat
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select id, canonical_name, times_logged, last_logged_at
        from meal_memory order by times_logged desc, last_logged_at desc limit 20;"

# What memory changed about a photo's proposal
docker compose exec postgres psql -U health_tracker -d health_tracker \
  -c "select m.id, m.memory_match_score, i.name, i.memory_adjusted
        from meals m join meal_items i on i.meal_id = m.id
       where m.memory_match_id is not null order by m.id desc limit 20;"
```

`pg_trgm` is a database-level extension, created by migration
(`CREATE EXTENSION IF NOT EXISTS pg_trgm`) with GIN trigram indexes on
`meal_memory.canonical_name` and `meal_memory_items.name`. The `down()`
deliberately drops the indexes and **not** the extension.

### The account

One user, no signup. Create (or rotate) it with:

```bash
docker compose exec app php artisan db:seed --class=SingleUserSeeder --force
```

With `SEED_USER_PASSWORD` unset the seeder **generates** a strong password and
prints it once; nothing but the bcrypt hash is stored. Re-running it with an
existing user and no `SEED_USER_PASSWORD` leaves the password alone, so it is
safe in a deploy script.

### Daily summaries

`daily_summaries` is fully derived and safe to drop and rebuild. Rebuilds are
queued automatically after every ingest and every meal change; this is the
manual entry point (and the way to apply a config change to history):

```bash
docker compose exec app php artisan summaries:rebuild                  # every day with data
docker compose exec app php artisan summaries:rebuild --date=2026-08-06
docker compose exec app php artisan summaries:rebuild --from=2026-08-01 --to=2026-08-07
docker compose exec app php artisan summaries:rebuild --queue          # hand it to Horizon
```

### Fitage body-composition import

The scale's own app exports history as `.xlsx`, and that history never reached
HealthKit in full. Health Auto Export carried four metrics, **hour-bucketed** —
a 15:38 weigh-in is stored at 15:00, and an hour holding two readings arrives as
their mean — and only for the days the phone happened to sync. The export has
the real instants, the un-averaged values, and five metrics HealthKit never had
at all: muscle mass, visceral fat, body water %, bone mass and the scale's own
BMR estimate.

Drop the files in `storage/app/imports/` (gitignored — they are personal data
and must never be committed) and run:

```bash
docker compose exec app php artisan fitage:import --dry-run   # say what would happen
docker compose exec app php artisan fitage:import             # do it
docker compose exec app php artisan fitage:import --dir=/tmp/exports
docker compose exec app php artisan fitage:import --file=one.xlsx
```

**The directory is the unit, not the file.** Fitage exports per period, so the
history arrives as overlapping windows and more keep coming. Re-running over the
whole directory after adding a file imports only that file's measurements: the
upsert key is the same six columns HAE ingest uses, so a measurement seen in two
windows has one identity and the second sighting writes nothing. `--dry-run`
prints exactly the numbers the real run will.

Two things worth knowing:

- **Nothing is suppressed.** A weigh-in that also came through HealthKit is
  imported again, at the instant it really happened, and both rows are kept.
  Nothing downstream double-counts because nothing downstream counts *samples*:
  `daily_summaries.weight_kg` is `BucketSelector::pickInstant` (one sample per
  day), `WeightEma` and `TdeeEstimator` read days, not rows. Both sources are
  `DeviceKind::Scale`, so they tie on priority and the documented tiebreak — the
  latest sample of the day — hands the day the export's real reading instead of
  HAE's hour-anchored average. See `App\Services\Fitage\FitageImporter`.
- **`basal_metabolic_rate` is not `basal_energy_burned`.** The scale's
  bioimpedance BMR estimate is reference-only and deliberately carries its own
  metric name; the energy rollups and TDEE read Apple's measured basal energy
  and never see it.

It does **not** write `ingest_runs`: that table is the audit trail for the HAE
HTTP endpoint, keyed on `(session_id, sha256(body))`, and a row in it that came
from a file would make "did the phone's export land?" unanswerable from the one
table whose job is to answer it.

**This command needs `ext-zip`** (an `.xlsx` is a zip container), which was added
to `docker/app/Dockerfile` alongside it — so it needs the image rebuild below.

### TDEE (step 7)

Expenditure is **fitted from what happened**, not predicted from a formula:

```
TDEE = mean(intake over complete-log days) − 7700 × OLS slope of the EMA-smoothed weight
```

over the last 28 days (front-trimmed to the first day with data, floor 14). It is
published as a **range** — the regression's standard error and the intake band
combined in quadrature — never as a point. Below **14 complete-log days** or
**8 weigh-ins** in the window it refuses to answer, shows "collecting data" with
real progress counts, and writes nothing.

```bash
docker compose exec app php artisan tdee:estimate              # the current window
docker compose exec app php artisan tdee:estimate --dry-run    # compute, write nothing
docker compose exec app php artisan tdee:estimate --date=2026-09-01
```

Runs nightly at 03:40 Europe/Amsterdam, and again (queued, coalesced) whenever a
daily-summary rebuild touches a date inside the window. Idempotent: a recompute
that does not move the answer does not even touch `computed_at`.

`tdee_estimates` is fully derived — safe to truncate and re-run. Everything
tunable is `config/health.php` → `tdee` (energy density, window length, both gate
thresholds, `method_version`); a change there is a re-run, not a migration.

**As of this deploy the table is empty and that is correct**: the real history has
two weigh-ins and no complete food logs, so the app is in the "collecting data"
state. The estimator's correctness is established by synthetic fixtures in
`tests/Feature/Tdee/` — a 28-day scenario whose answer (2 110 kcal) is arithmetic,
including one showing that endpoint-minus-endpoint would be out by 570 kcal on the
same rows.

### Stress monitor

A 1–99 score per local day, four bands, **higher means less stress** — the same
scale as the commercial app it replaces, so nothing has to be relearned. What is
different is underneath: it is scored against **this person's own baseline**, not
a population's.

```
1. ln(HRV) for every accepted hourly reading      (right-skewed; log makes ratios differences)
2. − the circadian offset for that hour of day    (this user swings 46% between 05:00 and 18:00)
3. day level  = median of what is left            (one 213 ms artefact must not move a day)
4. z          = (level − median of the last 60 days' levels) / (1.4826 × their MAD)
5. score      = 1 + 98 × Φ((z − z₀)/s)            (z₀, s solved from two anchors)
```

The two anchors are the only real judgements and they live in
`config/health.php` → `stress.scale`: a day **on** your baseline scores **65**
(the middle of Normal, not its floor — mapping the median day to 50 would put
half of an ordinary life into "Pay attention"), and **0.85 personal σ above**
baseline is where **Great** starts. Everything else is solved from those.

Step 2 is the point of the whole feature. Raw HRV runs ~41 ms at 05:00 and ~28 ms
at 23:00 for this user — a 45% swing that is a clock, not a mood — so a flat
baseline reports *when the Watch was worn* rather than how the day went. See
`tests/Feature/Stress/CircadianBaselineTest.php`: two days identical except for
which six hours were measured, scored the same here and ~50 points apart without
the correction.

**Coverage is never implied.** A day needs ≥ 3 readings of its own and ≥ 21 days
of history behind it, or it has no score and says which of the two is missing.
Days that do score carry `samples`, `covered_hours` and a low/medium/high
confidence tier into the stored row and onto the screen. Heatmap cells below 3
observations are drawn as holes — never interpolated.

```bash
docker compose exec app php artisan stress:rebuild            # trailing 90 days
docker compose exec app php artisan stress:rebuild --all      # every day with HRV
docker compose exec app php artisan stress:rebuild --date=2026-07-28
docker compose exec app php artisan stress:rebuild --dry-run
```

Nightly at 03:50 Europe/Amsterdam, ten minutes behind `tdee:estimate`. The window
is trailing rather than a single day because a late export shifts the baseline of
everything after it. Idempotent: an unchanged recompute does not touch
`computed_at`. `/stress` also computes the shown week **live** on render and
upserts it, so today's card is never last night's answer.

`stress_daily` is fully derived — safe to truncate and re-run — and is keyed on
`local_date` alone (NOT `(date, method_version)` like `tdee_estimates`) precisely
so the coming health report can `join … using (local_date)` without duplicating a
day. Everything tunable is `config/health.php` → `stress`; a change there is
`stress:rebuild --all`, not a migration.

On the real 859 days of history: **820 scored**, mean 62.8, and the bands come out
21.3% Great / 55.0% Normal / 21.0% Pay attention / 2.7% Overload. The score
correlates **−0.42** with resting heart rate across 787 days — a metric that is
nowhere in the computation, and the direction physiology predicts.

### Step 8 — the PWA

#### Installing it on the phone

There is **no install prompt on iOS** — `beforeinstallprompt` does not exist in
WebKit, and every browser on an iPhone is WebKit. Installing is manual, and works
from Chrome exactly as it does from Safari:

1. Open the site in Chrome on the iPhone and sign in.
2. Tap the **share** button (the box with the arrow — in Chrome it is in the
   `⋯` menu, or the toolbar depending on version).
3. Scroll to **Add to Home Screen**, then **Add**.
4. Launch it from the new icon. It opens with no browser chrome, in its own
   storage container, and asks the browser to make that storage persistent.

The installed app runs in the same standalone WebKit container whichever browser
added it. Once installed, the footer line "Offline log is not protected…"
disappears — that is `navigator.storage.persist()` having been granted, and it is
what stops WebKit's seven-day eviction of unused script storage from taking the
offline queue with it.

#### The offline queue

Meals do not need a connection. When there is no network — or when a request dies
mid-flight — the action is written to IndexedDB and sent later:

| Queued | Not queued |
| --- | --- |
| Logging a meal, editing one | **Deleting** a meal |
| One-tap re-log from the picker | Re-analysing a photo (it starts a paid API call) |
| Confirming a photo proposal | Barcode lookups (they need Open Food Facts) |
| A meal photograph (the downscaled JPEG, as a Blob) | |

What you see: an **Offline** bar under the header, the queued meals as dashed
cards on the day they belong to (marked *not counted in today's totals until it
sends* — the balance above is built from what the server has), and a **"N queued"**
badge in the header. Tapping the badge flushes immediately; otherwise it flushes
when the network returns, when the app is foregrounded, and at launch.

Replay is safe to run twice: every endpoint it uses was made idempotent in step 3
(client-generated meal `uuid`) or step 5 (`Idempotency-Key`), so a double flush
costs an HTTP request and nothing else — a photo replayed twice still buys exactly
one Anthropic call.

Something the server *rejects* — a validation error, an expired session — is kept,
marked **Needs attention** with the reason, and given Retry and Discard buttons.
It is never retried in a loop and never thrown away on its own. The queue caps at
50 actions and refuses new ones rather than evicting the oldest.

To watch it work: log a meal in Airplane Mode, then turn the radio back on with
the app open.

#### Updates

The worker takes over as soon as it installs (`skipWaiting` + `clients.claim`)
and shows a small **"Updated in the background — Reload"** prompt. It does not
reload the page itself: that would be correct until the first time it happened
with a half-typed dinner in the meal sheet. Reload when the prompt appears —
`vite build` empties `public/build`, so a page left open across a deploy will
eventually ask for a lazy chunk that no longer exists.

#### Icons

```bash
docker compose exec app php artisan pwa:icons     # -> public/icons/
```

One command draws the SVG masters and all five PNGs from one set of geometry
constants (`GeneratePwaIconsCommand`): a white plate with a heart-rate trace, on
teal-600. It uses **ext-gd** at 4× with a downsample, because no SVG rasteriser is
installed on this host and adding one for six small files would be a new system
dependency. Plain and maskable are separate croppings — the maskable glyph has to
survive being cropped to the circle inscribed in 80% of the square.

Re-run it if the geometry changes; the committed PNGs are the output and the diff
should be empty otherwise.

#### What is deliberately not here

- **No Background Sync.** The API does not exist in WebKit. The queue is in the
  page and flushes on `online` / foreground / launch — see `resources/js/lib/queue.js`.
- **No cached HTML, ever.** The worker is network-only for navigations, Inertia
  XHR and `/api/*` (which includes `/api/ingest`). Only content-hashed `/build/`
  assets and the data-free `/offline` page are cached. A stale daily view would
  show yesterday's calories as today's with nothing to say so.
- **No JS test runner.** There is none in this project and step 8 did not add one.
  `resources/js/lib/queue.js` is verified by hand (Airplane Mode → log → re-enable)
  plus the server-side half of its contract in `tests/Feature/Pwa/OfflineReplayTest.php`,
  which asserts the responses the queue reads: idempotent replay, 401 rather than a
  followed 302, 422 with a message rather than a redirect.

#### Last-sync indicator

The daily view carries "Health data: last sync Xh ago", computed server-side in
`DailyView` (the server's clock against the last arrival — a phone whose clock has
drifted forward would hide the gap). It turns amber past
`config('health.ingest.stale_after_hours')`, default **36 hours**: a locked iPhone
has no HealthKit access, so an overnight gap is normal and a shorter threshold
would be amber most mornings.

### Deploying

Build the front-end assets (`docker compose --profile build run --rm assets`) and
run `php artisan migrate` — the app serves built assets, so a deploy that skips
the build serves the previous one.

## Health Auto Export settings

Requires the **Premium** unlock (REST API automations; the cheaper "Basic"
unlock is manual export only and will not run automations).

- **URL** `https://<your-host>/api/ingest`
- **Header** `X-API-Key: <INGEST_API_KEY from .env>`
- Format **JSON**, **API Export format version 2**
- **Since Last Sync**, **Summarize ON**, **Hourly** grouping
- **Batch Requests ON** — Cloudflare's free plan rejects any single request
  over 100 MB with a 413, and batching is what keeps real payloads under it
- Only the ~11 chosen metrics: steps, walking/running distance, active +
  resting energy, heart rate, HRV, sleep, weight, body fat %, skeletal muscle
  mass, visceral fat index
- Add the Automations widget to the Home Screen; keep the phone charging
  overnight so background restrictions loosen

## Testing

`scripts/ci.sh` runs the whole gate in a throwaway stack of its own — its own
Postgres, its own `vendor/`, torn down when it finishes — so a run never shares
state with anything else you have up. It needs Docker and nothing else.

```bash
scripts/ci.sh                 # npm audit, eslint, the node tests, pint,
                              # composer audit, phpstan, then the PHP suite —
                              # against Postgres, in its own stack
scripts/ci.sh --checks-only   # just the linters and the advisory scans
```

Pint runs in that default pass, so formatting is checked by the gate rather than
by anyone remembering to run it.

Before hand-rolling a `setUp`, look in `tests/Concerns/`: three traits carry the
wiring most feature tests need. `ReadsSource` reads a `.vue`, `.js` or `.css`
file's source and refuses an empty read, optionally stripping the comments first
so that prose cannot satisfy a claim about code; `InteractsWithVision` binds
`FakeVisionAnalyzer` so a test cannot reach Anthropic; `ActsAsFreshUser` creates
a user, signs them in, and leaves them on `$this->user`.

Postgres, not sqlite: the schema uses stored generated columns, `jsonb` and enum
CHECK constraints, none of which sqlite can hold. `ci.sh` creates its own
database inside its own stack. The one below is the *legacy* test database
alongside production, kept because `.env.testing` still points at it:

```bash
docker exec health-tracker-postgres-1 sh -c \
  'psql -U $POSTGRES_USER -d postgres -c "CREATE DATABASE health_tracker_test OWNER health_tracker;"'
```

The front end has a small suite of its own, under `tests/js`, run by Node's
built-in test runner — no vitest, no jsdom, no dependency to keep current:

```bash
npm test
```

It covers the two modules whose whole job is to survive a browser that is
already misbehaving: `resources/js/lib/report.js` (the crash reporter, against
every value a `throw` can carry — `undefined`, a string, a cross-realm Error, a
circular object) and `resources/js/lib/inertia-guard.js` (a response that claims
to be an Inertia page and is not). `scripts/ci.sh` runs it before the PHP suite,
because a failure there is a two-second answer rather than a two-minute one.

**After changing a validation rule, a message or a ceiling, re-run the export:**

```bash
php artisan validation:export        # --check to be told, and write nothing
```

The forms validate on blur out of `resources/js/lib/validation/rules.generated
.json`, which is generated from `app/Http/Requests` and committed. Both halves of
the agreement test fail the gate while it is stale — `RulesAgreementTest`
compares the file with a fresh export byte for byte, and the node suite replays
~2000 cases the real validator answered — and each says to run this command. It
needs no database.

#### `.env.testing` — and the footgun it closes

`--env=testing` is a flag any artisan command accepts, and the trap below is
about artisan, not about phpunit.

**`--env=testing` used to mean production.** Laravel falls back to `.env` when
the file for the named environment is missing, and there was no `.env.testing`
here — so `php artisan migrate --env=testing` did not run against a test
database at all. It ran against `.env`, the real environment. It
looked like a safe command, it read like a safe command, and it burned three
sessions.

`.env.testing` now exists and points at `health_tracker_test`. It is **not
committed** (`.gitignore`, beside `.env`); `.env.testing.example` is the
template. On a fresh checkout:

```bash
cp .env.testing.example .env.testing
# fill in DB_PASSWORD to match .env, then:
docker compose exec app php artisan key:generate --env=testing
```

`phpunit.xml` sets `DB_DATABASE=health_tracker_test` too, and its `<env>` values
win over the file — the redundancy is the point, because artisan commands do not
read `phpunit.xml` and the file is the only thing standing between a stray
`--env=testing` and production data.

Check it before trusting it:

```bash
docker compose exec app php artisan tinker --env=testing \
  --execute="echo config('database.connections.pgsql.database');"
# => health_tracker_test
```

The generated `APP_KEY` is fresh and decrypts nothing in production, and
`ANTHROPIC_API_KEY` is a dummy on purpose: the suite binds `FakeVisionAnalyzer`
over the real one, so a key that cannot work is a second lock on the door
rather than a missing feature.

**There is no JavaScript test runner**, by choice — nothing in this project has
one, and step 8 did not add a whole toolchain for one module. The offline queue is
covered from two sides instead: `tests/Feature/Pwa/OfflineReplayTest.php` pins the
server responses it reads (idempotent replay, 401 rather than a followed 302, 422
with a message), and `tests/Feature/Pwa/ServiceWorkerTest.php` asserts the served
worker's headers and the early returns in its fetch handler. The in-browser half —
IndexedDB, `online`/`visibilitychange`, the Blob round-trip — is verified by hand:

1. Sign in on the phone, turn on Airplane Mode.
2. Log a meal, tap a "log again" card, take a meal photo. Each becomes a dashed
   card on the day and the header shows the count.
3. Force-quit the app and reopen it. The cards are still there (IndexedDB survived).
4. Turn Airplane Mode off. The badge says "syncing…", the queue empties, and the
   dashed cards become real ones with the day's totals updated.
5. Navigate while offline (pull to refresh) — the branded `/offline` page appears
   rather than Safari's error.
6. Step 9: offline, type a meal and tap **Save & estimate**. It becomes a dashed
   card reading "will estimate on sync"; back online it syncs and *then* goes to
   **Analysing…**, because the job runs server-side and cannot start until the
   meal arrives.
7. Step 11: online, open a photo proposal and tap **½** on the chip row. Every
   number halves on screen. Confirm, then reopen the meal — the chip is still on
   ½ and the card shows the fraction beside the portion. Offline, the same
   confirm becomes a dashed card and carries the share when it syncs: the queue
   replays the payload, and the share is in the payload.

## Not yet built

The build order in `SPEC.md` is complete (steps 0–8), plus step 9 — the
post-launch pass from first real-world use: photo-library upload, optional
nutrition fields, and text estimation. What is deliberately out of scope stays
out: multi-user / public signup, micronutrients, writing back into Apple Health,
and a native iOS app.

Known follow-ups, none of them blocking:

- `is_complete_log` has no user override yet — the column is rewritten by every
  rebuild, so an override needs a column of its own (noted in `SPEC.md` step 3).
- Photo-similarity meal matching via pgvector is deferred; the fingerprint is the
  sorted item-name set (`SPEC.md`, "Meal memory").
- Server-side gap ALERTING is still just the on-screen indicator — nothing emails
  or pushes when the phone stops exporting.
