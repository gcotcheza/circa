# app/ rationale

Full-length design rationale extracted from `app/` comments during the
2026-08 comment-compression pass. Each in-code comment that used to carry
this reasoning now holds a compact one-sentence constraint plus a pointer
here — this file is the durable record of the "why," so read it alongside
the class it names rather than as a replacement for reading the code.

## Table of contents

- [GeneratePwaIconsCommand: one drawn geometry, not six exported files](#generatepwaiconscommand-one-drawn-geometry-not-six-exported-files)
- [PruneMealPhotosCommand: what prune repoints, and why per plate](#prunemealphotoscommand-what-prune-repoints-and-why-per-plate)
- [ReprojectProposalCommand: what a rebuild carries across, and what it doesn't](#reprojectproposalcommand-what-a-rebuild-carries-across-and-what-it-doesnt)
- [RetainBuildsCommand: why deploys need a ledger, not just files](#retainbuildscommand-why-deploys-need-a-ledger-not-just-files)
- [Static analysis](#static-analysis)
- [FitageImporter: why near-duplicates aren't suppressed](#fitageimporter-why-near-duplicates-arent-suppressed)
- [TDEE Range: Equation, Uncertainty Combination, and Known Biases](#tdee-range-equation-uncertainty-combination-and-known-biases)
- [BucketSelector: bucket overlap and rate-ceiling refusal](#bucketselector-bucket-overlap-and-rate-ceiling-refusal)
- [PromptV2Report Version History](#promptv2report-version-history)
- [RelinkPhotoProvenanceCommand: what broke and why this is a command, not a migration](#relinkphotoprovenancecommand-what-broke-and-why-this-is-a-command-not-a-migration)
- [AnalyzeMeal: why a superseded answer is recorded, not applied](#analyzemeal-why-a-superseded-answer-is-recorded-not-applied)
- [DocumentCall: the rules every structured-output call has to get right](#documentcall-the-rules-every-structured-output-call-has-to-get-right)

---

## GeneratePwaIconsCommand: one drawn geometry, not six exported files

`app/Console/Commands/GeneratePwaIconsCommand.php`

**Why a command, not committed PNGs.** Six hand-exported files at six sizes
and two croppings (plain, maskable) drift — one re-cut with a slightly
different margin. Geometry is written down once, in the class constants,
and every file, including the SVG master, is drawn from it: regenerating is
`php artisan pwa:icons`, and the diff is either empty or intentional.

**Why GD, not an SVG rasteriser.** None is installed, and adding one for six
icons would be a new deploy dependency; ext-gd is already required (the
photo re-encode step). PNGs are drawn with GD at 4x the output size and
downsampled with `imagecopyresampled` — that supersampling IS the
antialiasing, since GD's `imageantialias()` doesn't apply to thick lines or
filled ellipses, which is all this icon is made of.

**The design.** A white plate from above, a heart-rate trace, on the app's
teal — two shapes, two colours, sized for a ~60 px home-screen icon, where a
ring goes grey, a fork-and-knife is redundant, and a gradient fights the
OS's own rounding. The trace itself (flat, up, hard down, flat) is
asymmetric on purpose: a symmetric zigzag reads as a chevron, not a pulse.

**Why two croppings, not one `any maskable` file.** A maskable icon crops to
a circle inscribed in 80% of the square, so its glyph must be smaller — one
file means either a wastefully small plain glyph or a maskable plate shaved
off on Android.

---

## PruneMealPhotosCommand: what prune repoints, and why per plate

`app/Console/Commands/PruneMealPhotosCommand.php`

**Why originals go and thumbnails stay.** `meal_items` is the record of
what was eaten; the photograph is evidence for a decision already made and
confirmed, so a year of full-resolution dinners is a liability with no
matching benefit — every one is a picture of somebody's home. The 256 px
thumbnail survives because a list of meals with a picture beside each is
how a human recognises "that Tuesday", and costs nothing to keep forever.

**Why the path is repointed, not nulled.** An earlier design nulled
`meal_photos.path`, which would orphan the thumbnail and leave nothing on
screen. Paths are prefixed (`originals/` vs `thumbs/`), so the column keeps
saying which kind of image it holds — how the re-analyse endpoint knows to
refuse rather than send a postage stamp to the model.

**Why it walks plates, not meals.** A dinner photographed in three courses
is three rows, each expiring on the meal's `eaten_at` rather than its own
`created_at`, so a meal's plates disappear together rather than the
dessert outliving the main course.

---

## ReprojectProposalCommand: what a rebuild carries across, and what it doesn't

`app/Console/Commands/ReprojectProposalCommand.php`

**Why this exists.** `vision_requests` keeps the model's whole reply
(`raw_response`) and, for a text estimate, the meal exactly as typed
(`input_payload`) — kept for evaluating prompt changes against history, but
it also makes repairs possible: when a rule BETWEEN the answer and the
stored rows is wrong, meals written under it can be rebuilt from data
already on disk, no API call, a no-op where the rule hasn't changed. Asking
Claude again would cost money, isn't deterministic, and would replace
numbers the user already saw for reasons they can't see.

**Does not re-run `MemoryPrefill`.** The pipeline is model → mapper →
memory → user, and memory is a live claim about what the user confirmed
SINCE — a confirmed meal is itself part of that history, so re-running it
would feed a meal its own numbers back as evidence. `memory_match_id` /
`memory_match_score` are left exactly as the original run set them.

**Does not decide whether the meal is confirmed.** A proposal stays a
proposal; a confirmed meal stays confirmed with `confirmed_at` carried
across — reopening a decision already made isn't a repair. Different
numbers on a confirmed meal are reported and NOT written unless `--force`.

**Shares are carried across, not rebuilt.** `raw_response` is the model's
answer to the whole bowl; it was never told about the shared plate, so "I
ate half of it" is the user's own claim, layered on after, and no more this
command's to discard than `confirmed_at` is. Per-item overrides survive a
rebuild because it can honestly match them by slug — rows being replaced
came from this same answer, so a slug on both sides is the same food; a
re-analysis can't claim that (its answer is new), so it re-applies only the
plate's share instead (see `AnalyzeMealPhoto`).

**`ConsumptionShare` is applied twice, deliberately.** `MealItem::creating`
guarantees the invariant into the database regardless — but the diff
compares rebuilt rows against stored ones, and until this runs the rebuilt
portions are the whole plate while stored ones are the eaten half, so every
shared item would falsely report as "changed" and refuse the write on a
confirmed meal. Applying twice is free: the transform is idempotent.

**Scope is the one plate the request looked at.** A meal is a series of
plates; "stored now" means that plate's items, and the rebuild replaces
only them — diffing against the whole meal would report the main course as
rows the dessert's answer "lost", and applying it would delete them. A null
`meal_photo_id` is the text path: items from a description, not a
photograph.

---

## RetainBuildsCommand: why deploys need a ledger, not just files

`app/Console/Commands/RetainBuildsCommand.php`

**The bug this answers.** `vite build` empties `public/build`, and this app
is a client-rendered PWA whose HTML does nothing but name one of its
chunks — so every deploy deletes the previous build's files, and anything
still pointing at the old one is not degraded, it's dead. A page left open
across a deploy runs fine until its first lazy import (photo capture,
review sheet, barcode scanner — not corners of the app, how a meal gets
logged): the chunk 404s and the button does nothing forever. A document
served from any cache names a deleted entry chunk, which for a
client-rendered app is a blank white page with no server-side symptom.
(`NoStoreHtmlResponses` attacks that one from the other end — both, because
they fail independently.) Neither showed up in the nginx log because
`location ^~ /build/` had `access_log off`, hiding the 404 that would have
named the problem for a day; fixed in `docker/web/nginx.conf`.

**How it works: a ledger, because a file's mtime is not its build.**
`build.emptyOutDir` is `false` in `vite.config.js`, so a build adds files
rather than replacing the directory — something has to delete the old
ones, and that something needs to know which file belonged to which
build, which the filesystem can't answer (an unchanged chunk keeps its
name and timestamp across builds). So each run writes a snapshot,
`public/build/builds/<version>.json`, listing exactly the files the
manifest referenced. `<version>` is the md5 prefix of `manifest.json`, the
same string `BuildAssets` uses for the service worker's cache name, so "a
build" means the same thing in both places. Retention keeps the newest N
snapshots, keeps the union of files they name, deletes everything else in
`assets/` — a chunk shared by three builds survives until the last of them
is dropped.

**Why it runs twice.** In the deploy, straight after the asset build (the
moment the new snapshot has to be written and the pruning is wanted), and
on a schedule (`routes/console.php`), because `emptyOutDir: false` turns a
forgotten deploy step into a filling disk rather than a no-op — worst case
is a day of extra chunks. Idempotent: a run with no new build re-reads the
same manifest, finds its snapshot already there, deletes nothing still
referenced.

**Why three.** The window that matters is how long a phone can hold a
reference to an old build and still be rescued, bounded by how often we
deploy on a bad day — three times, on the day this was written. Beyond
three the returns are nothing: a device that missed four deploys has been
asleep for days and fetches fresh HTML the moment it wakes.

**A kept file keeps its source map.** `vite.config.js` sets
`build.sourcemap: true`, but Vite doesn't list `app-XYZ.js.map` in the
manifest, only `app-XYZ.js` — so a prune keyed purely off the manifest
deleted every map, on every deploy, immediately, with no visible symptom
until an inspector needed one months later. Each retained name is now kept
with `.map` appended, whether or not that file exists. Pruning is also
scoped to `assets/` alone, on purpose: `public/build` also holds
`manifest.json` and the ledger, and pruning the whole tree would be one
glob away from deleting its own bookkeeping.

---

## Static analysis

`phpstan.neon`

**Why Larastan, not plain PHPStan.** Eloquent is built out of `__call`,
`__get` and static proxies, so to plain PHPStan `User::query()->where(...)
->first()` is a call on a class that has no such method, returning
something it cannot name. Larastan is the PHPStan extension that teaches it
the framework: model properties from the migrations/casts, builder chains,
facades, container binds. Without it every model line in this app is a
false positive and the baseline is noise rather than an inventory.

**Why a baseline, and why it is empty.** `phpstan-baseline.neon` was the
ratchet that let analysis be switched on at all: it listed the 579
findings that already existed, so CI was green from day one and any NEW
error failed the build. It was an inventory of debt, checked in and
readable, not a silencer — and it has since been paid off, so it is empty
and stays included. An empty inventory is still the mechanism: a finding
that shows up here has to be fixed or deliberately added back. To
regenerate: `vendor/bin/phpstan analyse --generate-baseline
--allow-empty-baseline`.

**Why level 8.** It's the highest level that is about correctness rather
than annotation completeness: it checks nullability on top of levels 0-7's
unknown methods, wrong argument types, dead conditions and bad returns.
Level 9 ("no mixed") and 10 are largely a docblock-writing exercise on a
codebase this size, and would bury the real findings in the baseline.

**Why one process, deliberately (`parallel.maximumNumberOfProcesses: 1`).**
PHPStan parallelises by forking a worker per core, and each worker boots
the whole framework through Larastan. The CI app container is capped at
768 MB (`docker-compose.ci.yml`) and this box runs two of those stacks plus
the live site, so four workers at ~250 MB each do not fit; when they don't,
the workers die and PHPStan reports what the survivors found — three
consecutive runs on this codebase returned 487, 358 and 527 errors before
this line existed. A single process is slower (~90s) but gives the same
answer every time, which is the only kind of answer a baseline can be
built from.

**`checkModelProperties: true`.** Larastan reads the model's `$casts`,
`$fillable` and the migrations to know what `$meal->eaten_at` is. Left on
(the default) because this app leans on generated columns and
`immutable_datetime` casts, which is exactly where a wrong assumption
costs an afternoon.

**`report_unmatched_ignored_errors` (default `true`).** What keeps the
baseline honest: once a baselined error is actually fixed, the stale entry
fails the build and has to be removed.

---

## FitageImporter: why near-duplicates aren't suppressed

`app/Services/Fitage/FitageImporter.php`

**The directory is the unit, not the file.** Fitage exports per period, so
the user's history arrives as a pile of overlapping windows and more of them
keep appearing. The command therefore processes whatever is in the
directory, every time, and is safe to re-run: adding a fifth file and
running again imports the fifth file's measurements and rewrites nothing
else. Nothing about the class knows how many files there are or what they
are called.

Idempotence is not a new mechanism. It is the same six-column unique key HAE
ingest upserts on — (metric, aggregation, period, started_at, ended_at,
source_id) — and the same `HealthMetricWriter`. A measurement that appears
in two overlapping windows has one instant and therefore one identity, so
the second one conflicts and the writer's `WHERE` clause skips the update
entirely.

**The double-counting question, and why nothing is suppressed.** Most of
these weigh-ins also reached the database through HealthKit, under source
"FITAGE". The obvious worry is that importing them again doubles the user's
weight history. It does not, and the reason is worth stating because the
alternative — dropping export rows that look like an existing one — is the
tempting fix and it is the wrong one.

What the production data actually shows (verified, 40 weight rows):

```
export     13/05/2025 15:38:23   56.00 kg
database    2025-05-13 15:00:00  56.00 kg   <- HAE, truncated to the hour

export     29/04/2026 14:46:42   55.75 kg
export     29/04/2026 14:47:04   55.45 kg
database    2026-04-29 14:00:00  55.60 kg   <- HAE, the mean of the two
```

Health Auto Export delivers hourly buckets. A body-composition sample is a
bucket containing one reading, anchored at the top of the hour, and when the
hour contained two readings HAE hands over their average. So the HealthKit
copy is systematically less precise than the export in both axes, and 55.60
is a weight the user never actually weighed.

Nothing downstream double-counts, because nothing downstream counts samples:

| Consumer | How it reads |
|---|---|
| `daily_summaries.weight_kg` | `BucketSelector::pickInstant` — one sample per day, highest-priority source, then the latest of that day. Never a sum, never a mean. |
| `WeightEma::series()` | reads `daily_summaries`, one point per day. |
| `TdeeEstimator` | counts days with a non-null `weight_kg` for its `min_weighins` gate, not rows. |
| `TrendSeries` | reads `daily_summaries`. |

Both sources classify as `DeviceKind::Scale` (`Source::kindFor` matches
"fitage" anywhere in the name, so "FITAGE export" lands as Scale like
"FITAGE" and "Fitdays"), so they tie on priority and the documented tiebreak
applies: "the latest sample of that day, because a re-weigh is a
correction." The export's real 14:47:04 therefore wins over HAE's synthetic
14:00:00, and the day reports a weight the user really saw instead of an
average of two.

Suppressing near-duplicates at import time would invert that. It would
throw away the true instant and the un-averaged value in order to preserve
HAE's rounded one, and it would leave weight sitting at 15:00:00 while
visceral fat from the very same weigh-in sat at 15:38:23 — one measurement,
split across two timestamps, for no gain. So the export is imported whole,
at the instant it really happened, and the selection layer stays the single
place that decides what a day weighed.

The collisions are still counted and reported (`sameHourAsHealthKit`),
because "31 of these 48 already existed as an hourly HealthKit sample" is
exactly what makes the argument above checkable rather than merely asserted.

---

## TDEE Range: Equation, Uncertainty Combination, and Known Biases

`app/Services/Tdee/EstimatedTdee.php`

**The equation.** Conservation of energy, read backwards. Over a window in
which the user ate a known average and their EMA-smoothed weight moved at a
known rate, expenditure is whatever makes the books balance:

```
tdee_mid = intake_mean − kcal_per_kg × slope
```

`intake_mean` is the mean of `kcal_in_mid` over the complete-log days in the
window only — incomplete days are excluded, not counted as low, because a
breakfast-only day is not a 400 kcal day, it is a day with no usable intake
number, and averaging it in would drag the estimate down by hundreds of
kcal. `slope` is kg/day from an OLS fit through the EMA-smoothed weight
series at the days that actually have a weigh-in, never endpoint-minus-
endpoint, never interpolated. `kcal_per_kg` is 7700 by config. Losing weight
gives a negative slope, so the second term adds: eating 2000 while dropping
0.1 kg/week means burning 2000 + 7700 × 0.1/7 ≈ 2110.

**The range, and how the two uncertainties combine.**

```
slope_kcal = kcal_per_kg × SE(slope)          // the fit's own error, kcal/day
half_low   = sqrt(slope_kcal² + band_low²)
half_high  = sqrt(slope_kcal² + band_high²)
tdee_min   = tdee_mid − half_low
tdee_max   = tdee_mid + half_high
```

where `band_low = mean(kcal_in_mid − kcal_in_min)` and `band_high =
mean(kcal_in_max − kcal_in_mid)`, over the same complete-log days.

In quadrature, because the two are independent: how uncertain the food log
is has nothing to do with how noisy the bathroom scale was, and adding them
linearly would produce a range wide enough to be useless. Asymmetric,
because the intake band is — a meal-photo estimate that says "600–900 kcal,
probably 700" is lopsided, and forcing the result symmetric would move the
midpoint off the value the equation actually produced, which is why
`tdee_mid` is stored rather than recovered as `(min + max) / 2`.

**Why the intake band is not divided by √n.** Averaging 20 days of ±180
kcal does not give ±40: the uncertainty in "how much rice was in that
bowl" is systematic, it repeats every day the same way, and it does not
cancel. This is deliberately the conservative reading.

**What this range is not: a 95% confidence interval.** `SE(slope)` is one
standard error (~68% for that term alone) and the intake band is a full
plausible spread, so the combination has no single coverage probability to
quote. It is a plausible range, labelled as one, and it is still enormously
more honest than the point estimate a BMR formula would hand over.

**Three known, accepted biases** (documented rather than silently
corrected):

- `kcal_per_kg = 7700` assumes the mass moved is body tissue. A kilogram of
  water carries a fraction of that energy; the EMA is what stops a
  three-day water swing being read as 2300 kcal, but a genuine glycogen
  shift at the start of a diet still leaks into the estimate.
- The EMA's start-up transient biases the fitted slope toward zero by ~7%
  for a window that begins at the user's very first weigh-in (alpha =
  0.25). Because the series is seeded from the full history, that
  transient has decayed to nothing for every window after the first
  month — and 7% of a 0.1 kg/week trend is 8 kcal, well inside the
  published range.
- The intake mean over complete-log days stands in for the whole window,
  which assumes the days that were not fully logged were not
  systematically bigger or smaller. That assumption is exactly why the
  gate demands 14 complete days rather than 4.

---

## BucketSelector: bucket overlap and rate-ceiling refusal

`app/Services/Rollup/BucketSelector.php`

**The problem** (SPEC.md, "Bucket alignment is NOT always hourly"). Two
independent things make a naive SUM wrong: several sources report the same
hour — the Watch and iPhone both count steps for 10:00-11:00 as separate
legitimate rows, so summing roughly doubles the day — and buckets don't tile
the day — a "Since Last Sync" export anchors its hour buckets at the
previous sync instant, so a real day contains ...13:00-14:00, 14:00-15:00,
then 15:42:55-16:42:55 from one source and 14:30:53-15:30:53 from another,
overlapping PARTIALLY. `DISTINCT ON (metric, started_at, ended_at)` only
catches exact collisions. Both are visible in production (2026-08-07:
step_count arrives from the Watch, from "Watch|iPhone", and from the
iPhone, with three different bucket anchors).

**The strategy: greedy non-overlapping selection, priority-first.** Sort
every candidate bucket by (source priority rank, started_at, longer bucket
first, id) and walk the list, accepting a bucket only if it overlaps
nothing already accepted — a set of pairwise-disjoint intervals that can be
summed without double-counting, with the highest-priority source winning
every minute it claims. Two properties make this defensible: on a normal,
fully hour-aligned day it's a no-op (every Watch bucket accepted, every
duplicate rejected, total equals the old `DISTINCT ON` answer exactly), and
it never invents data — a partially-overlapping lower-priority bucket is
rejected WHOLE rather than pro-rated into the gap it leaves, since a
20-minute walk inside a one-hour bucket is exactly the case where "spread
evenly" is most wrong. The cost is a possible slight undercount of
contested minutes, and undercounts are measured, not hidden
(`coveredSeconds()` feeds the UI's coverage flags). Interval-splitting with
pro-rating was considered and rejected: smoother-looking numbers built on
an assumption the data contradicts, and unfalsifiable after the fact — this
approach is reconstructable from the rows.

**And one bucket is refused for what it says.** `isImpossibleRate()`
catches the case no source/overlap rule can: on reconnect the Watch writes
the whole gap's modeled basal into a single sync-hour bucket — 1,283 kcal
in an hour against a 47-65 kcal/h norm — and summing it puts a phantom
half-day into the total. Refused here rather than in the builder, since
this class decides which buckets may safely be added up; the span it
doesn't claim then reads as uncovered. Which caveat surfaces depends on the
metric: for this incident it is NOT the burn-floor badge
(`active_kcal_coverage` is computed from the ACTIVE selection alone,
untouched by a refused BASAL bucket) but `has_full_metric_coverage`, which
needs both halves and renders as "Incomplete metric coverage" — a chip
after `isToday` in a `v-else-if`, so it never appears on the current day.
The total is honest immediately either way; the label arrives the next
day. (A refused ACTIVE bucket would move the burn-floor badge instead.)
Only metrics with a configured ceiling in `config/health.php`
`rollup.rate_ceiling` are judged, and never an instant or empty span — a
bucket with no duration has no rate, only a value.

---

## PromptV2Report Version History

`app/Services/Report/PromptV2Report.php`

**Why a new version, not an edit to v1.** `health_reports.prompt_version`
plus the stored `input_snapshot` is what makes a prompt change evaluable:
the exact facts a past report was written from can be replayed through a
new prompt and the two answers diffed, which only works if the version
moves whenever the text moves. Report 1 says `v1-report`, written by
`PromptV1Report`, which stays exactly as it was — the control the voice
change is judged against, and the fallback if this one turns out worse.

**What changed, and what didn't.** v1 produced a technically excellent
report that read like a research memo — true and checkable, but written
about a person rather than to one ("3 August scored 86 with HRV 40.6 ms
and resting heart rate 66 bpm, the week's highest"). So what changed is
the voice, and only the voice: second person; the plain statement leads
and the figure follows it (numbers all stay, they stop being the
sentence's subject); the app's vocabulary translates once and drops (HRV →
"recovery", an EMA → "your weight trend", a kcal delta → "you ate a little
less than you burned"); genuine wins are named out loud, because a report
that only lists what's missing is one a person stops opening. Every rule
that keeps it honest is untouched — NO ARITHMETIC, NO MEDICINE,
ranges-stay-ranges, supplements-are-exact, coverage-is-load-bearing, "not
enough data" is a finding, the baseline-drift gates — and the schema is
v1's, reused rather than copied.

**The one risk this text is built around.** The obvious failure of "make
it friendlier" is not an unfriendly report, it's a report that gets
friendly by getting vague — "your recovery dipped a bit mid-week, but
nothing to worry about" in place of a number and a date, or a cheerful
gloss over three days of missing food logs. That's a WORSE report than v1
by exactly the measure this app exists for, and harder to notice because
it reads well. So the text is ordered deliberately: the voice comes first,
then a single pivot line (being friendly is not the same as being soft),
then every honesty rule beneath it; the instruction block is sent LAST of
the two blocks in the request, so the final word belongs to the truth
rules rather than the voice ones. The second risk is length — a friendly
register is wordier by default, and the report is already ten prose
sections plus a nutrient table inside a 16,000-token ceiling shared with
thinking — so the per-section limits are unchanged from v1 and the text
says outright that warmth costs words nowhere.

**The schema is reused, not copied.** The shape has to stay identical —
WrittenReport, ReportView, the Vue components, the factory and every
fixture read these exact keys, and a report is displayed by the same
screen whichever prompt wrote it. Building it from
PromptV1Report::schema() rather than copying 170 lines makes that a fact
about the code instead of a promise in a docblock: there is one document
shape, and a field added to it arrives on both versions at once.

**v2.1 — the profile block.** Added `profile` (schema_version 2): age,
sex, height, country, diet, allergies, goal, life stage, and whatever
health context the user volunteered. Before it existed the report wrote
nutritional suggestions to somebody it knew nothing about, and every
reference intake worth naming is keyed on sex and age first. A point
release, not a v3: nothing is replaced, so there's no control to preserve,
and replaying an older snapshot simply finds `profile` absent, the
empty-profile case the text already handles. Two rules: NEVER GUESS A
FIELD (a null is a question the person chose not to answer; inferring a
sex from a nutrient pattern or a goal from a weight trend would be exactly
the confident invention the rest of the prompt forbids, and invisible,
since an inferred demographic reads identically to a stated one) and
AWARENESS IS NOT MEDICINE (`health_notes` is the only field where someone
may write a condition or medication; it buys one kind of sentence — gentle
awareness that something interacts with something, worth raising with
whoever prescribes it — and nothing else; the ban on doses, diagnoses,
tests, and contradicting a clinician is restated around it, not relaxed).

**v2.2 — the training block.** Added `training` (schema_version 3):
workout sessions with activity name, gaps between them, heart rate held
during each. v2.1 taught the report to read an athletic goal and a
returning athlete's history, then handed it only steps and exercise
minutes to read it against — "62 exercise minutes on Sunday" and "a
70-minute martial arts class on Sunday, the first in three weeks" are the
same measurement and two entirely different findings, and only the second
is a sentence about this person's training. Another point release, same
terms. Two risks: double-counting the energy (a session's kcal are already
inside the day's expenditure — the watch records active energy whether or
not a workout is running — so adding a week of session kcal onto the
week's burn produces a plausible-looking number wrong by a third; the
facts block's own `note` says so, and the instruction repeats it, since
this is the one arithmetic error the arithmetic ban doesn't already cover)
and prescribing training (having session data isn't having a coach's
licence — the report may describe the window and its gaps but may not
write a plan, name a load, set a session count, or push a rate of return;
for a returning athlete the honest caution is about RATE, already in v2.1,
and the new data makes it concrete, not stronger).

**v2.3 — the focus block.** Added `focus` (schema_version 4): which of
five areas the reader asked the report to be about, and optionally their
own sentence on what they wanted looked at. Not a hint: a focused snapshot
genuinely omits blocks outside its focus (see `ReportFocus`), which is
what buys a focused report its longer ranges — so the new text's job is
telling the model a missing block is deliberate, not this app failing to
record anything (without it, a stress report over six months would open
its data gaps with "no food was logged in this window", false and exactly
the confident wrongness this prompt exists to prevent). The output schema
does the other half: sections whose facts are absent drop from `required`,
so silence on food is a shape the answer is allowed rather than an
instruction trusted to be followed. Another point release; replaying an
older snapshot finds `focus` absent, reading as the unfocused report it
was. This version carries a new kind of risk: every earlier version of
this prompt was written entirely by the developers, and this one carries a
sentence the READER typed inside the document — a free-text box that
reaches a model will eventually be used to ask for something the rules
forbid. Three structural answers: it's data, not instruction (the reader's
sentence arrives inside the JSON facts block, sent FIRST; the rules are
the second block, sent LAST — the same defence `AnthropicReportWriter`
documents for food names and photo notes, untrusted text never becoming
part of the question); the text says so out loud (steering topic and
emphasis is what the focus note is for, it cannot relax a rule, and a
forbidden request gets one plain refusal sentence before the report it
can write); and it cannot add a fact (a focus note asking about something
absent from the document gets the same answer anything else missing gets —
not enough data is a finding — because there's nowhere else for a number
to come from).

**v2.4 — the day table in periods, for a long range.** The focus block
bought ranges up to a year, which exposed a shape problem shorter ranges
never hit: a 365-day stress report assembled ~136k tokens of daily rows
and came back with a headline, a paragraph, and every structured section
empty — handed a wall of rows, the model consolidated instead of
reporting. The same focus over 182 days was rich. So beyond a quarter the
`days` block folds into weekly or monthly period buckets (schema_version
5, announced by `range.day_granularity`; see `DayBuckets`) — smaller
input, and the month/week structure a "which months were more stressful"
question is actually asking for. The new text's one job is teaching the
model to read a bucketed table: one entry per period, each a pre-computed
summary; the arithmetic ban is unchanged (a bucket mean is a fact, not
something to re-average); the day-level cross-cuts it can no longer
eyeball stay in `anchor_statistics`; a long range is still a full report
of its focus, not a paragraph. A point release on the same terms — a short
report never sees a `weekly`/`monthly` granularity, so its instructions
read exactly as before.

**v2.5 — interpretation over enumeration.** The reader's own verdict on
the first live reports: less of a number, more an analysis. Every earlier
version told the model to keep the numbers and only move them off the
front of the sentence ("all of them stay"), which reads readings back
faithfully but interprets them thinly — a single day arriving with steps,
run distance and duration, average and resting heart rate, and recovery
all in one breath is a database row with spaces in it, not an analysis. So
the register moves from RECITATION to INTERPRETATION: an observation leads
with what's happening and what it means, anchored by at most one or two
figures rather than a string of them; the separate, number-dense evidence
line is reframed to a brief anchor, one figure, only where a number
genuinely pins the claim. The honesty guarantee doesn't move, and holding
it is the whole risk — the numbers were on the page to stop the model
inventing conclusions, so fewer of them must NOT become more freedom to
make things up. The replacing rule isn't "say less," it's "your
interpretation must be supported by the facts you were given, even when
you don't quote them" — and the figures that ARE the honest answer stay as
prose: coverage counts, a range where the truth is a range, a drift or
trend figure when the question was about a trend. "No gratuitous numbers,"
never "no numbers." A point release: the output schema shape is unchanged
(`evidence` stays a required string, so one `WrittenReport` and one screen
serve every version and no snapshot needs a new `schema_version`), and
what moved is how the model is told to write the same facts.

---

## RelinkPhotoProvenanceCommand: what broke and why this is a command, not a migration

`app/Console/Commands/RelinkPhotoProvenanceCommand.php`

**What broke, and why it needs a command rather than a migration.**
`MealWriter::update()` — the ordinary edit sheet, PUT /meals/{uuid} — used
to replace a meal's items wholesale without carrying `meal_photo_id`
across. The edit round-tripped everything the user could see (share, full
portion, barcode link) and dropped the one column the form never shows:
which photograph the line came off. That column isn't decoration —
`MealPhoto::entryState()` answers "has anything been confirmed off this
plate" by looking for items that point at it, so a dinner of three
photographed courses, every item confirmed, came back from a single edit
reading as three plates nobody had reviewed: an orange "3 more photos to
review" banner on a meal that was completely settled, with the numbers
underneath right the whole time. The write path is fixed; this command
repairs the rows it already wrote. It's a command rather than a migration
for two reasons: the damage is per-meal and ongoing until every affected
meal is repaired or re-confirmed, and a repair that reads model output
should be looked at before it's written — so the default is a dry run
(`php artisan vision:relink <uuid>` to preview, `--apply` to write).

**It moves one column and nothing else.** Not the portions, not the
shares, not `confirmed_at`, not the meal's status, not the day's totals —
`vision:reproject` is the command that rebuilds numbers; this one restores
a lost link, and a repair that also "improved" a figure the user had
agreed to would be indistinguishable from the silent change this app
exists not to make. The write is a query-builder update rather than a
model save, keeping it clear of `MealItem::creating` and its portion
arithmetic — repointing a row must never recompute what's in it.

**Identity is the slug, and ambiguity is refused.** Each plate's stored
answer is a list of names, and `meal_items.slug` is the same `Str::slug`
of the same name, so a slug appearing in exactly ONE entry's answer
identifies that entry. Anything else is left alone and reported: a slug in
two plates' answers (two courses of one dinner both had rice — guessing
would put the wrong picture behind the food half the time, and the banner
it fixes is a smaller problem than a wrong provenance record); a slug also
in a text answer (the meal has a described entry too, whose items are
SUPPOSED to have no plate — claiming one would invent evidence); a slug in
no answer at all (a line the user typed by hand, or renamed past
recognition — null is the truth). Only items with NO current plate are
considered, so a second run has nothing left to do, and a run over an
undamaged meal writes nothing at all.

---

## AnalyzeMeal: why a superseded answer is recorded, not applied

`app/Jobs/AnalyzeMeal.php`

**Two analyses of one plate can be in flight at once.** A user types a note
after the shutter, and `MealPhotoController::reanalyze` lets a re-ask that
changes the question overtake an in-flight call rather than making them
wait ten to thirty seconds to be allowed to say it. The second call was
asked the right question, so it's the one whose answer the user must end
up looking at — but nothing orders the two: they're separate jobs on
separate workers against a network, and "dispatched second" doesn't mean
"finishes second." If the first call is the slow one, the OLD answer would
land last and overwrite the hinted one, which is worse than the bug this
whole feature fixes — the user would watch the note they typed be visibly
ignored.

**The rule is the same one the review sheet reads by**
(`MealPhoto::latestVisionRequest`, `MAX(id)`): the newest row for a plate
owns that plate's items. A row that's no longer the newest still completes
fully as an AUDIT — status, tokens, latency, and the verbatim response are
all written, because the call happened and it cost money — it simply
doesn't write items. Nothing is lost that can't be recovered:
`vision:reproject` rebuilds a proposal from a stored response, which is
precisely what that command is for.

**The check and the write are one transaction, over a lock on the plate.**
Reading "am I still the newest" and then writing without holding anything
leaves a window — the newer job could commit in between and be overwritten
by this one a millisecond later, the exact failure this guard exists to
prevent, merely made rarer. Holding the plate row makes the two
completions queue up behind each other, and whichever gets in second
re-reads the truth under the lock. The text path takes the same route with
nothing to lock and nothing to supersede — one code path rather than two
that are supposed to agree.

---

## DocumentCall: the rules every structured-output call has to get right

`App\Services\Anthropic\DocumentCall`.

Two paths in this app ask Anthropic a question with a JSON schema attached:
the health report, and the vision analyzer (meal photo, typed meal,
supplement label). They ask completely different questions. Everything
between sending the request and holding a decoded document is nevertheless
identical, because it is a property of the API rather than of the question —
and each path carried its own copy of it until they were merged here.

Copying was the wrong move here for one specific reason: every rule below
fails SILENTLY when it is forgotten. A copy that drops one of them does not
fail a test, it produces a wrong answer or a crash in a queue worker on the
day the rare case finally happens. Two copies also drift: a fix applied to
one is not applied to the other, and nothing points that out.

### The rules

**`stop_reason` is read before `content` is touched.** A refusal is a
successful HTTP 200 whose content array is empty or partial. Code that
indexes `content[0]` before looking at the stop reason therefore crashes on
exactly the case it most needed to explain to somebody.

**A truncation is a failure, not something to parse.** `max_tokens` means the
JSON stopped mid-document. Partial JSON is not a small problem — it is
unparseable, and treating it as an ordinary decode failure loses the one
piece of advice that helps ("ask for less").

**The whole message is captured, not the answer.** `vision_requests.raw_response`
and the report's audit row exist so a NEWER prompt can be replayed against an
OLD question and the two answers diffed. Usage, stop reason and model all
matter to that comparison; a column holding only the answer throws away the
outcome of the question.

**Usage travels on the failure path too.** A refusal after a full read of the
evidence has been paid for; a truncation twice over. Failures reporting no
tokens would make the audit table under-count spend by exactly the calls
somebody most wants explained.

**An SDK error becomes an outcome, never an exception.** Both callers run in
queue workers where an escaping exception is a retry, a stack trace and no
explanation.

**And the outcome is said in plain words, not the SDK's.** Every message this
SDK raises opens with a description of the exception class — a dead network
reached the reader as the literal "Anthropic API Connection Error" — and a
status error continues into a pretty-printed JSON dump of the response body.
One person reads these, on a phone, from a list of failed reports, so what
they are told is composed here instead: what happened, and what to do about
it. The SDK's text is not lost, it is moved: it goes to the log under the
caller's label, with the exception class and the HTTP status as keys of their
own, because grepping a JSON blob for "529" finds the body of a 400 just as
happily.

The ladder reads the STATUS, not the exception class, because the two are not
interchangeable: the SDK maps every 5xx to `InternalServerException`, so a 529
and a 500 are one class and opposite advice — "busy, try again in a minute"
against "try again later". A null status is the no-answer case, and the
sentence for it claims no more than that: the SDK raises one
`APIConnectionException` both for a network that was never reachable and for a
request that connected and then overran the client timeout, which at 300s for a
report is the likelier of the two, so "check the connection" alone could send
somebody after the wrong thing. A 408 joins it, being the same event reported
by a server that stayed up rather than a rejection. A failure that is not an
`APIException` at all gets the sentence that promises nothing about the cause:
that branch catches a bug in this app as readily as a transport the SDK did
not recognise, and sending somebody to check their network over a `TypeError`
sends them after the wrong thing.

**No `temperature` / `top_p` / `top_k`.** Removed on this model: a request
carrying any of them is rejected with a 400 rather than ignored.

**The first text block IS the JSON.** The schema is enforced server-side via
`output_config.format`, so nothing strips prose, unwraps a fenced block or
regex-hunts for a `{`. The content is walked rather than indexed because a
thinking block can precede the answer.

### What the callers still choose

The differences between the two paths are constructor arguments, so they are
readable at the two construction sites instead of hidden behind a flag:

- **effort** — the report asks for a configured effort level; vision takes
  the model's default. A null is dropped by the SDK rather than sent as
  `"effort": null`, so one caller wanting it costs the other nothing.
- **maxTokens** — 16 000 for a report against 4 096 for vision. Thinking
  shares the ceiling with the answer on claude-opus-5, and a report is ten
  prose sections plus a nutrient table.
- **the wording** — the log label, and the name the reader knows the service
  by. Two strings rather than one, because the log says "Vision" where the
  reader is told "the analysis service" and neither is worth bending to make
  the parameter list shorter. But only two: every user-facing sentence about a
  failed call is composed from that one name, so the report and vision paths
  cannot drift into carrying a ladder of wording each — which is the same
  argument that put the call itself in one class.
- **the four failure sentences** — passed to `ModelDocument::failureText()`
  as a map keyed by the `FAILED_*` constants. There are three such maps, not
  two: a meal, a supplement label and a report each advise a different
  fallback, because typing the meal in by hand, reading the bottle in your
  hand and choosing a shorter range are different pieces of advice. The map
  is typed as a shape, so a caller answering only three of the four cases is
  a static-analysis error rather than a user shown the bare word
  "truncated".

### One decode depth, not two

The two copies decoded the model's JSON at different nesting depths — 64 in
the report path, 32 in vision — with nothing anywhere explaining the
difference, which is the signature of a copy rather than a decision. The
shared call uses 64, matching the depth both copies already used for the raw
capture. The limit only guards against pathological nesting; a server-side
schema cannot produce a document close to either bound, and unifying upward
cannot turn a decode that used to succeed into one that fails.

`RawResponse::firstJsonObject`, which reads an answer back out of a stored
row, uses the same 64. It has to: a document the live call accepted and
saved would otherwise replay as "no readable answer" on a review screen,
which is the asymmetry this heading promises is gone.
