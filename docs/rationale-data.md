# Rationale — data domain

Extracted design rationale for `config/`, `database/`, and `routes/` — comments dense
enough that compressing them in place would have cut load-bearing content. Each
config/migration comment that points here keeps the essential constraint and a
pointer; this file keeps the full reasoning.

## Table of contents

- [Rollup — basal/active rate ceiling](#rollup--basalactive-rate-ceiling)
- [Sleep — partial night arrival](#sleep--partial-night-arrival)
- [Trends — body card ranges](#trends--body-card-ranges)
- [Stress — circadian profile and rolling baseline](#stress--circadian-profile-and-rolling-baseline)
- [Stress — score anchors](#stress--score-anchors)
- [Stress digest — notable moments threshold](#stress-digest--notable-moments-threshold)
- [Report — token budgets](#report--token-budgets)
- [Supplements — units_per_day arithmetic rule](#supplements--units_per_day-arithmetic-rule)

## Rollup — basal/active rate ceiling

A stamped hour that is not an hour. When the Watch reconnects after a gap, Apple
writes the modeled basal energy for the whole gap into one bucket stamped with the
sync hour — an observed case on 2026-08-18 carried 1,283 kcal into a single
09:01-10:01 bucket (roughly eleven hours flattened into sixty minutes), pushing
that day's resting burn to 1,647 kcal against the 1,130-1,140 kcal of complete
days.

A bucket whose value over its stamped span implies a rate no body reaches is
dropped from both the total and the covered seconds, so the day reads as the
floor it honestly is and the burn-floor badge says so. It is deliberately NOT
re-windowed across the gap it came from: the stamp is the only time information
the row carries, and it's wrong — spreading the energy over a guessed span would
invent the very thing this guard exists to refuse.

The ceilings are multiples of the largest bucket this history has ever held, not
of its average. Across 27,112 captured datapoints, basal tops out at 82.155 kcal
in a bucket (~47 kcal/h is the daily average on complete days) and active at
319.642; 200 and 1,500 sit 2.4x and 4.7x above those. The guard targets the
physically impossible, never the merely unusual — the hardest hour this user ever
has must not trip it, and the 1,283 kcal artifact is 15x the biggest basal bucket
on record. `min_seconds` stops a very short bucket from being judged on a rate
extrapolated from a few seconds.

## Sleep — partial night arrival

night_date 2026-08-18 first arrived as a 119-minute session, 06:26 to 08:26,
beside six nights of 409-550. The card presented it as the night and the user
caught it — twice — before anything said otherwise. A manual export from the
phone then replaced it with the real one: 23:56 to 08:26, 507 minutes, same
source, nine overnight basal buckets alongside it. The sleep had been recorded
all along; the automatic exporter had not delivered it yet.

So the claim is about DELIVERY and never about the wrist. At render time the app
cannot tell a Watch on a charger from data still sitting on the phone, and it must
not guess: it says how much of the night is still unaccounted for and that a late
sync usually fills it in. The note then clears itself the moment the data lands,
which is exactly what it did.

The evidence is the Watch's own basal buckets — written every hour it is worn,
and delivered by the same export — so a stretch of night with neither a bucket
nor a session is a stretch that has not arrived. The window is a typical night
(`starts_at` the evening before, `ends_at` the morning of) widened to the
session's own span, because a truncated session cannot be asked how long the
night was; that is the question.

ONLY GAPS OUTSIDE THE SESSION COUNT. A hole in the buckets inside the reported
session proves nothing about delivery — the session covering that span is itself
the evidence its data arrived — and it is a story about a Watch on a charger,
which is not this app's to tell. Without that rule a complete 507-minute night
with a charger hole in the middle of it would read "only part of this night has
arrived", which is both false and self-refuting, and would never clear.

`missing_fraction` 0.2 is a judgement, and it is about edges. On a 10-hour window
that is two hours: shorter than any gap that could hide a meaningful amount of
sleep, and longer than the ordinary ragged edge of an export that batches (the
incident night is 0.81 of its window, a night delivered whole is 0.0). Below it
the card says nothing.

`watch_lookback_days` decides whether there is a Watch behind this setup at all,
and it deliberately asks the recent past rather than this night: the worst
outage — the export resuming in the morning, leaving a stub session and no
overnight buckets — is exactly the night whose own emptiness cannot be used as
evidence about itself. A phone-only setup has no watch-derived basal on any night
and stays silent forever.

How stale the pipeline is stays one question with one answer, in the "last sync"
indicator; this note never computes a second one.

## Trends — body card ranges

The 7/28-day `ranges` on the energy and steps charts belong to a different clock:
a day is the unit there and 28 bars is already a full phone screen. Body
composition is stepped on a few times a month, the change worth seeing is ~1 kg
over a season, and there are now fifteen months of it — so this card carries its
OWN ranges, switched client-side from one payload rather than by reloading the
page, since the whole series is a few dozen rows (one per weigh-in day, never per
hour).

GAP_DAYS is the honesty threshold. This history has real holes in it —
2025-11-09 to 2026-01-05 is fifty-seven days with no weigh-in — and a smooth
curve drawn across one would be an invention with exactly the same visual
authority as a measurement. Past this many days the line therefore goes DASHED
rather than solid: it still reaches the next reading, so the series reads as one
body over time, but the stretch it crossed without evidence is marked as such.
Three weeks is the number because it is longer than any plausible "I was on
holiday" run and shorter than the shortest real gap in the data.

(Until 2026-08 the line BROKE at this threshold instead, and isolated readings
floated with no line at all. The owner overruled that: the continuity is what they
want to see, and the dashes are what keeps it honest. Nothing computed changed —
the EMA always spanned the gaps, it was only the drawing that stopped.)

DEFAULT_MIN_POINTS picks the opening range: the shortest one that holds at least
this many weigh-ins, falling back to All. A fixed default cannot be right for both
a sparse history and a daily one — four weeks is a nearly empty card today and
exactly the right window once the user weighs in every morning, which is the
direction this is going. Deriving it from the data means the card opens on
something worth looking at either way.

It is FOUR, and the number has come down twice — ten, then six, then this — for
the same reason each time: it was measuring a STREAK when what it should measure
is a HABIT. Ten put the 1w chip permanently out of reach, since a seven-day
window can hold at most seven weigh-ins. Six then wanted six of the last seven
mornings, and the week the daily-weighing habit actually started held five — so
the card kept opening on 4w for somebody who was already doing the thing they had
asked the card to reward. A default that arrives a week late is a default that is
wrong on the only day anybody notices it.

Four is where the two failure modes are furthest apart. Two or three missed
mornings — a weekend away, a run of early starts, the first days of the habit
itself — still leave four points across seven days, which is a direction and not
a scatter. Below that the week has too few dots to read as a trend, and the
fall-through does its job: the loop keeps walking out to the next chip until a
window holds enough, so lowering this number never risks an empty card, only a
shorter one. Nothing else has to move if it changes again.

EXPORT belongs to the one series that arrives by HAND — the muscle-mass series.
It sits on the series and not on the card because "how long is too long" is a
fact about that workflow, and a second hand-fed series would want its own answer.
A fortnight is roughly how often the export happens, and a warning firing at the
routine interval gets ignored at the interval that matters: a week behind is
nothing and says so in grey, a quarter behind draws the same confident line with
a season of it missing, which is a lie told by omission.

## Stress — circadian profile and rolling baseline

In production HRV runs ~46 ms at 06:00 and ~30 ms at 23:00: a 50% swing that has
nothing to do with stress. Scoring raw HRV against a flat baseline would say
"great" every night and "pay attention" every evening, forever, which is
precisely the failure mode of the app this replaces.

`profile_days` 365: the shape is a physiological constant, so more data is
better — but a year rather than the whole history so that a change in shift
pattern or season shows up within a season instead of being out-voted by
three-year-old habits.

The two minimums are what stop a noisy shape being subtracted as if it were
known. An hour below `profile_min_hour_samples` gets NO offset (0, not a guess),
and days below `profile_min_day_samples` do not contribute at all — a day with
one reading has no meaningful median to measure its hours against.

THE ROLLING BASELINE. 60 days, ending the day BEFORE the day being scored —
today is compared with the past, never with itself.

60 rather than 365: HRV level drifts with fitness, illness, alcohol, season and
age, and a baseline that spans a year would score a fit spring against an unfit
autumn. 60 rather than 14: the spread has to be estimated too, and a MAD over a
fortnight is itself noise. In production a 60-day window holds 55-60 scored days.

`min_baseline_days` 21 is the gate. Below it there is no score at all and the UI
says so — "not enough history yet" is a real answer and a number from nine days
is not.

`min_day_samples` 3: fewer than three readings is not a day, it is three
readings. They are still SHOWN (coverage is a first-class output) but they do
not produce a score.

`min_spread_log` 0.03 floors the denominator. A fortnight of unnaturally
identical days would otherwise turn a 2 ms wobble into an "overload"; 0.03 in
log units is ~3%, comfortably below the ~16% day-to-day spread production
actually shows.

## Stress — score anchors

THE SCORE. 1-99, four bands, HIGHER = LESS STRESS, matching the app this
replaces so the user does not have to relearn a scale.

    score = 1 + 98 x Phi((z - z0) / s)

Phi is the normal CDF: a smooth, monotone, saturating squash, so an extraordinary
day cannot run off the end of the scale and an ordinary one is not pushed
against it either.

`s` and `z0` are DERIVED from the two anchors rather than typed in, because the
anchors are the actual decisions:

- `baseline_score` 65 — a day exactly on your own baseline (z = 0) scores 65, the
  middle of the Normal band. The obvious alternative, z = 0 -> 50, puts the
  median day on the Normal/Pay-attention BOUNDARY, so half of an ordinary life
  reads as a warning. An app that says "pay attention" every other day is an app
  nobody looks at twice.
- `great_z` 0.85 — 0.85 personal standard deviations above baseline is where
  Great starts (score 80). Roughly the top fifth of days, which is what "great"
  should mean: better than usual, not merely usual.

Measured against the real 819 scored days those two anchors produce 21% Great /
55% Normal / 21% Pay attention / 3% Overload — the shape of a life with
occasional bad weeks, which is the shape being reported on.

## Stress digest — notable moments threshold

NOTABLE MOMENTS — the honest version of "overload zone at 1:41 AM".

A single hourly reading is scanned against the distribution of the user's OWN
deseasonalised hourly readings over the `baseline_days` before the week being
viewed — the same residual (ln HRV minus the circadian offset for that hour) and
the same median/MAD machinery the daily score uses, so "2.5 sigma" means the same
thing here as it does there.

WHY 2.5 AND NOT 2. Measured on this user's last year of readings (7,277 of them,
robust sigma 0.334 log units):

    |z| >= 1.5   14.2% of readings   ~2.4 a day
    |z| >= 2.0    5.7%               ~1 a day
    |z| >= 2.5    2.0%               ~2-3 a week
    |z| >= 3.0    0.8%               ~1 a week

At 2.0 the section would fire every single day and mean nothing — which is
exactly how the old app's "moments" ended up ignored. At 2.5 a week produces two
or three, which is what `moment_max` happens to allow, and a quiet week produces
none and SAYS none.

`moment_max` 3, at most one per day per direction: an hour-long dip usually shows
up in two or three consecutive readings, and listing all of them would report
one event three times.

The gate for showing the section at all is `min_baseline_days` days of readings
in the pool — the same gate a daily score needs. Below it there is no section,
rather than a threshold applied to a spread nobody has established.

## Report — token budgets

CEILING ON THE WHOLE RESPONSE — THINKING AND THE JSON TOGETHER (`max_tokens`).
Four times the vision path's 4096, and the multiplier is not caution: the answer
is ten prose sections, a micronutrient table, and two lists of grounded
observations; the thinking is ON BY DEFAULT on claude-opus-5 and shares this cap,
over a fact sheet that MEASURED 37,000 input tokens for a single seven-day
window on production data.

MEASURED, not guessed: that live seven-day report came back at 4,698 output
tokens all in. 16,000 is therefore roughly 3x headroom, which is the right shape
of margin for a ceiling whose failure mode is total — truncation mid-document is
unparseable JSON and a failed report, not a shorter one. The ceiling costs
nothing unless it is used, since output tokens are billed as generated. A 30-day
report carries roughly four times the day table, so budget for the input side
growing rather than this one.

HOW THE DAY TABLE IS DELIVERED, BY RANGE LENGTH (`buckets`). A row per day is the
right shape for a fortnight and the wrong shape for a year: a 365-day stress
report assembled ~136k tokens of daily rows and came back with an empty document,
where the same focus over 182 days (~78k tokens) was rich. So beyond a threshold
the day table is folded into weekly or monthly period buckets (see DayBuckets) —
smaller input, and the month/week structure a "which months" question wants.

- daily: up to a quarter (92 days) — the same ceiling every unfocused and food
  report already tops out at, so a report that stays daily is exactly the set
  that was always daily.
- weekly: 93 days up to `weekly_max_days` (~26 buckets for a half-year).
- monthly: beyond it (~12 buckets for a year — one per month).

Only a focused report can reach past `daily`: every unfocused and food report is
capped at `max_range_days`, so the fold only ever touches the longer ranges a
focus unlocked.

THE DEFENSIVE CEILING ON THE QUESTION ITSELF (`max_input_tokens`). The range
caps above bound ROWS; this bounds the DOCUMENT, which is what is actually
billed. It is a fuse rather than a meter — a snapshot that has grown an order of
magnitude because a year of complete food logging landed should be refused
politely and cheaply, not discovered as a 400 after assembly or as a truncated
answer that was paid for. 150,000 against a MEASURED 37,000 for a seven-day
window: four times the biggest real report, comfortably inside the 1M context
window, and far enough from the estimate's own accuracy (chars/4) that being 30%
out changes no decision.

## Supplements — units_per_day arithmetic rule

`supplements.units_per_day`: how many of a serving are taken in a day.

THE ONE ARITHMETIC RULE IN THIS FEATURE, STATED ONCE:

    supplement_nutrients.amount  is the figure PRINTED ON THE LABEL, for the
                                  serving the label states it for — whatever
                                  that serving is.
    serving_text                 is that serving, verbatim: "per capsule",
                                  "per 2 capsules", "per dagdosering
                                  (1 tablet)".
    units_per_day                is how many of THOSE the user takes.
    daily total                  = amount x units_per_day.

Nothing is ever normalised, converted or divided. A panel that prints "per 2
capsules: 200 mg" and a user who takes those two capsules is 200 mg x 1, not
100 mg x 2 — and the app never has to work out which, because it does not
try. That is the whole reason `serving_text` is stored verbatim next to it:
the two columns together are unambiguous to the human setting them, and the
multiplication is the only thing left for the machine to do.

The alternative — asking the model to restate everything per capsule — means
a division on a figure the user cannot then check against the bottle,
performed by the one participant that cannot see the bottle. Every wrong
answer it produced would look exactly like a right one.

The CHECK-OFF does not touch this. One tap means "I took my dose today",
whether the dose is one tablet or two softgels; a per-pill tick would be two
taps for the magnesium and a count nobody wants to keep.
