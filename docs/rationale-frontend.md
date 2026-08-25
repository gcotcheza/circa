# Frontend rationale

Prose extracted from `resources/js/` and `tests/` comments that resisted ~50%
compression because everything in it was load-bearing. Each in-code comment
that points here keeps the essential constraint inline; this file keeps the
full reasoning.

## Contents

- [Night Fragment Note](#night-fragment-note)
- [The 2026-08-18 Night Fragment Incident](#the-2026-08-18-night-fragment-incident)
- [Page attribution for error reports](#page-attribution-for-error-reports)
- [Why the balance is a centred meter](#why-the-balance-is-a-centred-meter)
- [Hours down the grid, days across](#hours-down-the-grid-days-across)
- [The stress band pastels, measured](#the-stress-band-pastels-measured)
- [The silent submit veto, and the comma](#the-silent-submit-veto-and-the-comma)
- [Saying it before the round trip](#saying-it-before-the-round-trip)
- [Correcting a range, and asking again with it](#correcting-a-range-and-asking-again-with-it)
- [The time field and the two-column grid](#the-time-field-and-the-two-column-grid)
- [451 green tests and a 405 in production](#451-green-tests-and-a-405-in-production)
- [Save & estimate left the user in the sheet](#save--estimate-left-the-user-in-the-sheet)
- [The null date-picker bound](#the-null-date-picker-bound)
- [Precision in an editable number box](#precision-in-an-editable-number-box)
- [Photo provenance on meal items](#photo-provenance-on-meal-items)
- [Why the body chart bridges its gaps](#why-the-body-chart-bridges-its-gaps)
- [The body chart's draw-in](#the-body-charts-draw-in)

## Night Fragment Note

`SleepCard.vue`'s sleep note (`fragment`, from `lib/sleep.js` /
`App\Services\Reporting\NightCoverage`) means part of a night hasn't arrived
yet, not that the wrist failed to record it — see
`tests/Feature/Ui/NightFragmentTest.php` for the incident that established
this (night 2026-08-18: first read as 1h59m, then a manual export replaced it
with 8h27m; the data had been there all along, delivery just hadn't happened).

The night is stored whole as interval data (`sleep_sessions`), not
reassembled from hourly buckets. The stage bar is proportional to reported
minutes and includes `awake`, because a 9-hour night with 70 minutes awake
isn't a 9-hour night.

The chip's wording is hedged ("usually fills in") because from the card's
position, data that is merely late and data that never existed look
identical — there is no way to distinguish them. Pipeline staleness is
answered exactly once, by the "last sync" indicator; this component never
computes a second answer to that question. The delivery/wrist distinction is
decided entirely server-side, in `App\Services\Reporting\NightCoverage`; the
resulting sentence is composed and tested in `resources/js/lib/sleep.js`.
Both the note and the underlying data clear themselves on the next render
once the rest of the night arrives — nothing here is cached or scheduled.

## The 2026-08-18 Night Fragment Incident

`night_date` 2026-08-18 first reached the server as 119 minutes
(06:26-08:26), beside six prior nights of 409-550 minutes — the card showed
"1h 59m" as the whole night. A manual export from the phone later replaced
it with 507 minutes (23:56-08:26) and nine overnight basal buckets: the
sleep had been recorded all along, the automatic exporter simply hadn't
delivered it yet.

The claim `NightFragmentTest` and `NightCoverage` make is DELIVERY, never
the wrist. The discriminator is WHERE the gap falls: outside the reported
session, nothing accounts for the time; inside it, the session itself is
evidence its data arrived.

`tests/Feature/Ui/NightFragmentTest.php` carries several fixtures beyond the
happy path because a test that passes either way proves nothing:

- the timezone fixture fails by 120 minutes if the coverage window is bound
  in local time instead of UTC;
- the charger fixture would fire a self-refuting sentence if coverage alone
  (rather than delivery) were the discriminator.

## Page attribution for error reports

Client error reports are stamped with the page component name inside
Inertia's `resolve` callback (`resources/js/app.js`), not in the
`inertia:navigate` listener in `resources/js/lib/inertia-guard.js`. The
listener remains as a backstop.

The reason is ordering. `client_errors` row 7 was filed against `Day` with
`url` set to `https://health.example.com/profile` — two fields disagreeing
about the same crash, which cost an investigation the time it takes to
notice that the url was the true one. It is an ordering fault, not a
mislabel:

```
  @inertiajs/core, page.ts:  this.swap({...}).then(() => {
                               ...
                               if (!replace) fireNavigateEvent(page)
                             })
```

`swap` calls the vue3 adapter's `swapComponent`, which assigns the new
component to a ref and returns. That assignment queues Vue's flush as a
microtask; the `.then` above queues `fireNavigateEvent` as the next one. So
Vue renders and mounts the new page — and throws, and reports — one
microtask before `inertia:navigate` fires and the guard gets to write the
new name down. A crash during a page's first render is therefore filed
under the page before it, which is precisely the crash that most needs
naming.

`resolve` runs before the swap and is handed the name outright, so setting
it there is both earlier and simpler. It also covers the case the event
never handles at all: `fireNavigateEvent` is skipped entirely on a replace
visit (`if (!replace)`), so a page reached that way used to keep the
previous name for as long as it was on screen.

The `inertia:navigate` listener stays as the backstop because `resolve`
runs on the page that is only about to be shown: a visit aborted mid-flight
(a second navigation started while this one was resolving) leaves the name
a beat ahead of the screen until the event corrects it.

## Why the balance is a centred meter

Two pictures died before the day card's balance meter, and both deaths are
the point.

First there were two bars, one above the other: "Eaten 2,071-2,311" in
teal, "Burned 1,362" in orange, and underneath them, in the same size as
everything else on the card, the sentence that is the actual reason anybody
opens this screen — "Surplus 709-949 kcal so far". The number that mattered
was a text afterthought, and the picture above it was two lengths the eye
had to subtract.

Second, the two bars became one track. Burn and intake shared a single
2,600 kcal scale from a shared origin, so they overlapped, and everything
past the crossover — the part only one of them reached, the overhang — was
the balance drawn as a length. It was honest, it was tested, and the user,
holding the phone, said: "I am still not very happy with the visual
slider. Is there a different way of showing it?"

The word they reached for is the diagnosis: slider. A grey rail with a
coloured blob part-way along it is the shape of a control, and a control's
blob means something by where it sits. The overhang meant nothing by where
it sat; it meant something by how long it was, measured from a seam that
was never marked, on a rail most of which was deliberately empty. That is
two decodings before the picture says anything at all, on the card whose
whole job is to be a glance.

So the rail is now an axis whose centre is zero, and the bar grows out of
that centre — left and cool for a day under your burn, right and warm for
a day over it — its length being how far from break-even the day landed.
Position is meaning again, because the one point on the rail that means
anything on its own is marked and everything is measured from it: nothing
is a length the reader has to subtract, and nothing is a position the
reader has to decode. Do not restore the single track with the floating
overhang. It was tried, it shipped, and the person this app is for called
it a slider.

## Hours down the grid, days across

`StressHeatmap.vue` draws seven columns of twenty-four. It was transposed
once — seven rows of twenty-four columns — and it lost on two independent
counts.

The device. The target is a phone held in portrait, roughly 350 px of usable
width. Seven columns get about 42 px each: a real, tappable target with room
for a "Mon" above it. Twenty-four columns get 13 px, smaller than a fingertip
and too narrow for anything but a tick every sixth column, so the old layout
could not label its own axis. Width is the scarce dimension on a phone and
height is the cheap one; the axis carrying twenty-four values belongs on the
cheap one.

The reading. A day is the unit somebody thinks in — "what happened on
Tuesday" — and a day in a COLUMN is one continuous vertical strip read top to
bottom, midnight to midnight, the way a calendar or a sleep chart is read.
Comparing two days is then comparing two adjacent strips, not scanning two
rows twelve pixels apart.

What the current layout buys: every hour carries its own label, which the
twenty-four-column version could not manage, with 00, 06, 12 and 18 drawn
darker as anchors to count from. The cost is a tall card that scrolls, which
is the honest consequence of showing all 24 hours rather than six ticks. Do
not transpose it back.

## The stress band pastels, measured

The four stress band fills are an ordinal ramp: the bands have an order
and a valence, so cool-to-warm is doing real work here, unlike a
categorical palette where hue only carries identity. Measured in OKLab
against both surfaces, the ramp scores as follows.

**Normal vision — pass.** The worst adjacent pair is dE 19.1 on light and
17.2 on dark, both above the 15 floor and better than the saturated ramp
this replaces (15.5).

**Contrast on dark — pass.** Overload reaches 3.63:1, up from 2.77:1.

**Contrast on light — warn.** A fill is between 1.50:1 (Pay attention) and
2.68:1 (Overload) against white. Pastel on white is the look; the small
swatches carry a hairline ring so they stay findable, and the heatmap's
cells are big enough not to need one.

**CVD separation — warn.** The worst pair is dE 6.5 for protanopia (was
12.7) and 7.7 for deuteranopia (was 6.6). Red-green vision gets a clearly
worse deal out of coral-vs-lime than it did out of crimson-vs-green, and
this is a real regression, not a rounding difference.

The CVD result is acceptable only because nothing in this feature is
encoded by colour alone, and nothing in it may become so. Every score is
printed as a number beside its swatch, the legend always carries the four
labels, the week chart writes each point's value above the point, and a
heatmap cell answers "Mon 09:00 · 42 · Pay attention" in words when
tapped. Colour is the fast channel; the text is the authoritative one.
Removing any of those affordances would turn a warning into a defect.

## The silent submit veto, and the comma

Tapping "Save changes" on the meal edit sheet scrolled the page up, put a
teal focus ring on an item's C/100 box (holding 28.35) and wrote nothing. No
error, no request — the server never heard about it.

`MealSheet.vue` holds the only `<form>` in this app with a `type="submit"`
button, so it is the only place native constraint validation runs. The
numeric inputs carried `min="0"` and `step="1"` / `step="0.1"` from when
every number in the sheet was typed by a person. Vision changed what lives
in those boxes: a confirmed estimate stores 158.333 kcal/100 g, 28.35 g of
carbs, 0.417 g of fat. With `min="0"` the step base is 0, so 28.35 is not a
multiple of 0.1, the control is `:stepMismatch`, and the browser refuses to
submit the form, scrolls the first offending control into view and focuses
it. On iOS it does not even render the explanatory bubble — which is
exactly "nothing happens, and the page jumps".

The values had been put there by this app. That is the rule these
assertions exist to keep: **any value the app can render into an input must
be one the input will hand back.** A constraint the browser enforces
silently is not allowed to stand between the user and a save.

So the meal-number boxes are `type="text" inputmode="decimal"` with no
`step`, `min`, `max` or `required`: the keypad is unchanged on the phone,
the plausibility ceilings stay in `ValidatesMealItems` where a refusal
comes back as a sentence in the error list, and the browser has nothing
left to veto.

The comma is the same failure from the other side. The keypad this app is
used on is Dutch: its decimal key is a comma. `Number('28,35')` is `NaN`
and `JSON.stringify(NaN)` is `null`, so a value the user typed and could
see on screen would have arrived at the server as a blank — and with
`type="number"` the comma never even reaches the handler, because the
control reports itself empty and, worse, counts as `badInput`, which is
another silent veto on the same submit button. `decimal()` in
`resources/js/lib/format.js` is the one parser, and it reads the comma.
Every numeric box in `MealSheet.vue`, `ProposalReview.vue` and
`ScannedProduct.vue` goes through it.

The one native constraint still allowed to stand is the item's **name**:
it is the only field a user can leave invalid that this app can never fill
in wrongly itself, and its prompt is visible and useful.

## Saying it before the round trip

Every form in this app was server-only after submit: you filled a box in
wrongly, tapped Save, waited for a round trip, and got a sentence back. The
sentences themselves were good — `ProfileRequest` will tell you that 1,6 looks
like metres and height goes in centimetres — but they arrived after the tap, on
a phone, sometimes over a mobile connection, and always about a box you had
stopped looking at. The exit the owner chose is inline validation with the
server's own words: a box says what is wrong AS YOU LEAVE IT, and what it says
is byte-identical to what the 422 would have said.

That means a second copy of the rules, in a language that cannot run the first.
There is no way around it — a browser cannot call `ProfileRequest` — so the
whole design is about bounding what that copy can cost.

**The copy is generated, not written.** `php artisan validation:export`
(`App\Services\Validation\RuleExporter`) walks every `FormRequest` in
`app/Http/Requests`, plus `LoginController::RULES`, and writes
`resources/js/lib/validation/rules.generated.json`: per request, per field, the
rule list with its parameters already resolved — the ceilings come out of
`config/health.php` as numbers, so the browser never holds a second copy of a
limit — and the exact sentence the server would emit for that field and rule.
The sentences are not rebuilt from templates. They are read through Laravel's
own message lookup, because that lookup does things a reimplementation would get
subtly wrong: a custom `messages()` line beats the generic one, a wildcard key
like `items.*.kcal_max.gte` matches a concrete `items.0.kcal_max`, and `max` on
a field carrying `numeric` says "must not be greater than 20000" where the same
rule on a string field says "20000 characters". Only `:attribute` is left
unfilled, because the name in the sentence depends on which row the reader is
looking at; the exported `attribute` template carries `:index` where the row
number goes.

**What the browser will not say.** A rule that needs the server is exported with
`server_only: true` and no sentence at all: `exists` and `unique` need the
database, `current_password` needs the session, `Password::uncompromised()` needs
a breach corpus, `email` needs the RFC parser, and `date`, `after_or_equal` and
`before_or_equal` need PHP's own date parsing against the app's clock in the
app's timezone — which is not the phone's. A field carrying an upload is the
server's whole and entire, because Laravel picks the kilobyte wording for `max`
off the value BEING a file. The browser stays silent on all of these and the
sentence still arrives after submit, exactly as before. Silence is the only safe
direction to be wrong in: a browser that refuses something the server would have
accepted teaches the reader to distrust every refusal it makes.

**The evaluator copies Laravel's gating literally.** `resources/js/lib/validation
/index.js` does not simply run a field's rules in order. Laravel skips
non-implicit rules against a value that trims to nothing, skips everything when
`nullable` meets a null, and skips the field entirely when `sometimes` meets an
absent key. Approximating that is how a browser ends up refusing an empty
optional box on a form whose every box is optional. It is transcribed, down to
`getSize` returning a character count for a numeric field holding something that
is not a number, and PHP's string cast where `true` is `1` and `false` is
nothing at all.

**Whitespace is where the two languages disagree most quietly, and an
independent review caught it here.** A browser's `\s` and `.trim()` match the
non-breaking space, the byte order mark, U+3000 and U+2028; PHP's `trim()`
matches none of them, and PHP does not even agree with itself — `is_numeric`
counts a form feed as whitespace and `filter_var` does not. Left on
JavaScript's definitions, a name box holding a single non-breaking space read
as EMPTY in the browser and as a one-character name on the server, so the
browser refused what the server accepts; a NUL had it the other way about,
since PHP strips that one and JavaScript does not. Every set in the evaluator
is now PHP's own, measured rather than remembered, and those characters are
part of the generated case set so the gap cannot reopen quietly. One case
cannot be generated: `is_numeric("\x0C5")` is true while `trim()` leaves the
form feed in place, so Laravel hands `BigNumber` a string it cannot read and
`max` throws a `MathException` instead of refusing anything — the server does
not answer, it 500s, and there is no sentence to agree with. The evaluator's
form-feed handling is pinned by a direct assertion instead.

**The agreement test is what makes the duplication affordable.** It runs in two
halves and neither is enough alone. `tests/Feature/Validation/
RulesAgreementTest.php` regenerates both committed artifacts and compares them
byte for byte with what is on disk, so moving a ceiling in `config/health.php`,
rewording a sentence, or adding a request fails the gate until somebody re-runs
the export. `tests/js/validation-agreement.test.js` takes the other half: the
export also writes `tests/js/fixtures/validation-cases.jsonl`, around two
thousand `(request, field, value)` cases each answered by RUNNING THE REAL
LARAVEL VALIDATOR, and the browser evaluator has to produce the identical
sentence for every one. The cases are derived from the rules rather than
written, so a new ceiling generates its own case one over and one exactly on it,
and nobody has to remember.

Two invariants hold the design together and both are asserted rather than
assumed. Within a field, no mirrored rule ever sits behind a server-only one —
otherwise the browser would answer with a later sentence than the one the 422
leads with, and the two would contradict each other about the same box. And the
exporter FAILS, loudly and non-zero, on a rule it does not recognise, rather
than quietly dropping it: a rule silently left out of the export is a box the
browser calls fine and the server refuses.

**Where the sentence appears, and when.** `resources/js/lib/validation/inline.js`
writes through Inertia's own error bag, so every `form.errors.x` already on a
page renders the inline sentence with no change to the markup and no second
error style — and submitting replaces the bag wholesale with the server's
answer, which is the right order of authority. A box is only judged once it has
been typed in: tabbing through an empty optional field, or past the blank row
the meal sheet always keeps open at the end, must never turn red. Emptying a box
counts as typing in it, so a required field you cleared still gets told about.
Nothing here disables a submit button. The server is still the one that decides;
this only means you usually find out sooner.

## Correcting a range, and asking again with it

Two edits to `ProposalReview.vue` came out of the same fault, one number the
user typed being worth more than the model's, and both are worth keeping.

A stored portion of 2 g, corrected in the min box to 250, left the sheet
reading "portion 250 – 2" — a band with NEGATIVE width, presented as though it
were a range somebody had chosen. Every screen downstream then had to cope with
min > max: the day's quadrature, the "whole plate" line, the confirm. The
server does refuse it (`MealProposalRequest` puts `gte` on every `_max`), but a
refusal arriving at the bottom of the sheet, phrased in field names, is not
what the user needed at the moment they typed it.

Hence `dragOtherEnd`: raise the minimum above the maximum and the maximum comes
up to meet it; drop the maximum below the minimum and the minimum comes down.
The last number typed is always honoured exactly, and the pair is always a
range — usually the zero-width one, which is precisely the claim "it was 250 g"
is. The alternatives are worse: refusing the keystroke fights the user
mid-word, and clamping the number they just typed silently changes it. The cost
is honest and worth writing down: typing 250 into a MAX that starts below it
drags the min along on the way through ("2" → "25" → "250"), so the min ends up
at 250 rather than back where it was. The rule only ever touches the end the
user is not looking at, and putting both back where they belong is two boxes
and two taps, which is what editing a range was anyway. Only pairs are touched
— a key ending `_min` or `_max` whose partner exists on the item — and only
when both ends have a value: a cleared box is "I do not know this end", not a
bound to drag anything to.

The same correction, made for the same reason, was then thrown away by the
button beside it. "Estimate this again" used to send nothing but an idempotency
key, and the server reconstructed the question from the STORED items — which
are the previous answer. A user who corrected a garbage 2 g to the 250 g they
had weighed, and then asked again precisely BECAUSE the rest of the answer was
built on the wrong weight, had their correction ignored and the same garbage
asked about a second time. From the outside it looked like the app deliberately
reverting an edit.

So the ask carries the sheet. `asLoaded` is what arrived, keyed by row id, and
anything that differs from it is the user's. A value they have settled on
becomes a GIVEN in the next request — echoed back untouched, exactly like a
figure typed into the meal sheet — and everything they have not touched stays a
question, which is what asking again is for. Two rules decide what counts as a
given, and both are deliberate:

- TOUCHED: it differs from what the model (or memory) proposed. An untouched
  range is not a claim, it is the answer being questioned.
- ZERO-WIDTH: min === max. A range the user narrowed but left open — 150-200 g
  — is still uncertainty, and a given is a number. With the drag rule above,
  typing one end of a range that crosses the other produces exactly this: one
  number, said twice.

The trade-off, said out loud: figures the user typed into the MEAL sheet before
the first estimate, and which the pipeline preserved into this proposal, arrive
here untouched — so once anything on the sheet is corrected, those go back to
being estimable unless they are corrected too. The sheet is the question, and
the sheet is what is on screen.

Nothing corrected, nothing to say — and saying it anyway would make things
worse. With no edits the sheet is just the previous answer read back, and
sending it as the question would hand the model its own numbers as though a
person had typed them. Omitted, the server reconstructs the question the way it
always has: from the input the user actually gave (see
`MealEstimateController::typedFrom`).

## The time field and the two-column grid

The meal sheet's Time field kept drawing its rounded border a third of the
way into the Meal column. `grid-cols-2` fixes the tracks at half each and
`min-w-0` on each cell clears the default `min-width: auto` a grid item
gets — neither reaches the actual cause: WebKit themes `input[type=time]`
as a native control with a UA `min-width` off its shadow tree, and used
width is `clamp(specified, min-width, max-width)` with min-width winning,
so `w-full` was simply raised back to it. Only an author `min-width` beats
a UA one — now stated once, in the `input[type='time']` rules in
`resources/css/app.css`, for every time field in the app.

The meal type used to be the grid's second column; it's a chip row now
(`MealTypeChips.vue` says why), and "Breakfast · Lunch · Dinner · Snack"
wants about 180px against a track that's ~175px on the widest iPhone and
~144px on the narrowest — four words don't fit in half a phone. So it
spans both columns on a second grid row instead, leaving the grid itself
untouched, which is what keeps the Time field in the half-width track the
CSS fix put it in.

## 451 green tests and a 405 in production

The review sheet's Confirm button answered "The server refused this
(405)" on a real meal, on a phone, with the whole suite green — and the
write had in fact succeeded. Tests and client were exercising two
different requests that both looked like "PUT /meals/{uuid}/proposal":
the test client's `$this->put(...)` does not follow redirects and
accepted a 302 via `assertRedirect`; the real client's offline-queue
`fetch()` sends no `X-Inertia` header, so Inertia's middleware skips its
302→303 rewrite, and `fetch` follows redirects. Per the Fetch standard
only POST downgrades to GET across a 301/302 — every other method is
preserved — so the browser re-issued `PUT /?date=…`, `/` is registered
GET-only, and the 405 the user saw was the SECOND request.

The lesson is not "assert harder on the confirm" — a test that writes its
own request cannot catch the client drifting. So `ConfirmRequestShapeTest`
pins both sides: verb and URI are read out of the component source (a
client change the router doesn't register fails here); the response is
asserted as 303 (what makes the browser's follow-up a GET); the wrong
verb is asserted to 405; and `PUT /` itself is asserted to 405, kept as a
test so nobody "fixes" it by teaching the day route to accept writes.

## Save & estimate left the user in the sheet

"Save & estimate" on an already-confirmed meal — add a blank line, tap
Save & estimate — used to leave the user stuck in the open edit sheet
(scrolled mid-list, keyboard up, caret in some other item's box) instead
of landing on the day view with the card spinning. The client half is in
`MealSheet.vue`: it saved, reloaded the page UNDER the open sheet, and
only closed it after a second request came back.

What `EstimateFromEditSheetTest` pins is the server half the client
relies on to make closing the sheet correct: (1) the save leaves a
CONFIRMED meal confirmed, with the new blank line; (2) the estimate that
follows is accepted as 202, not a 409 the sheet would need to stay open
to explain; (3) the meal is `analyzing` the instant it answers, so the
day card shows the same "Analysing…" state a photo produces, picked up by
`Day.vue`'s poller; (4) the poll endpoint reports the same state, since
that's what the day view actually reads. Manual phone check, deliberately
not asserted here (Vue state this suite doesn't mount): blank line on
yesterday's dinner, tap Save & estimate, land on the day view with the
card spinning.

## The null date-picker bound

`client_errors` row 7, build e3586ad222ff, phone on /profile: `TypeError:
null is not an object (evaluating 'r.toString')`. Resolved against the
deployed source map: `new AirDatepicker` in `AirDate.vue`'s mounted hook,
into air-datepicker's own option merge — `if (undefined !== value &&
'[object Object]' === value.toString())`. Since `undefined !== null` is
true, a null option value is a TypeError. The component passed `minDate:
null, maxDate: null` for a date of birth (no bounds), so the picker threw
before it finished constructing and the box had no calendar behind it.

Values are pinned in `tests/js/air-date.test.js`, which runs the real
module over every empty/malformed prop shape and holds down the one
property that matters: nothing it returns is ever null. No component
renderer exists in this project (no vitest, no jsdom — see `ci.sh`), so
`DatePickerNullBoundsTest` asserts, by source inspection, what JS can't:
that the component still routes through that module, and that the server
still sends the null bounds that made this a live fault. Same style as
`ProfileFormTest` and `ReportTabTest`.

## Precision in an editable number box

The edit sheet's Per 100 g row used to show "130.8333", "2.3456",
"163.3333" clipped at the edge of a field a fifth of a phone wide —
unreadable, and the user couldn't tell how much was hidden while being
asked to check it. Every one of those numbers was the app's own
arithmetic (`densityFor` dividing 157 kcal over 120 g = 130.83333333333334;
`mid` collapsing a band to its centre; `Nutriments::kcalPer100g` dividing
kJ by 4.184) over sources carrying real uncertainty — vision estimates
±40 kcal, crowd-sourced packet declarations. Fourteen significant figures
of that isn't precision, it's noise wearing precision's clothes: the tail
was unreadable AND untrue.

Rule: a value this app fills in is written at the precision its label
claims — kcal whole, grams and macros to a tenth, matching what the day
view already prints (`numberText`, `lib/format.js`). Two things the rule
may never break: (1) a value the USER typed is theirs — never rounded on
keystroke or on blur, since rewriting 2.36 as 2.4 under the caret (or a
second after they look away) is worse than the tail it fixes; (2)
rounding never reaches the state, only the box's displayed text —
`lib/basis.js` requires the Quick/Per 100 g toggle to post identical
bytes, which rounding-at-set breaks on the first tap (130.833 → 131, 4.9 g
protein/250 g → 5.0). `tests/js/boxes.test.js` covers both.
`FieldPrecisionTest` pins the shape of this in the templates, by source
inspection (Inertia SPA, server never renders these inputs, JS suite is
`node --test` over lib modules, which covers the arithmetic half).

## Photo provenance on meal items

A three-course dinner, photographed plate by plate, every item confirmed
— and an orange "3 more photos to review" banner anyway. The numbers were
fine; the provenance was gone: all sixteen items had a null
`meal_photo_id`, so `MealPhoto::entryState()` (which answers "has
anything been confirmed off this plate?" by looking for items pointing at
it) found nothing on any of the three plates and reported all three as
still pending. Confirm was never the culprit — it stamps the entry it's
given, always has. The culprit was the ordinary EDIT sheet:
`MealWriter::update()` replaces a meal's items wholesale, and the posted
list can't restate a column the form never shows. One edit on an
already-finished meal un-reviewed all of it.

So `PhotoProvenanceTest` holds the whole lifecycle: confirming stamps the
plate from the server's own knowledge of which entry the proposal belongs
to (not the client payload, which carries no `meal_photo_id`); editing
and re-saving keeps it; the banner is derived from the result and reads
zero; `vision:relink` repairs meals this already happened to.
`share_fraction` and `portion_full_g_*` ride the same wholesale writes
(one day older than this bug) and are asserted across the same edit
rather than trusted separately — they survive by a different mechanism
(the sheet round-trips them, since they're user-visible/editable
numbers), which is exactly why testing them here matters.

## Why the body chart bridges its gaps

Past `gapDays` (config, 21) `WeightChart.vue` carries both lines on to the next
reading — DASHED, faded, and straight rather than splined. This history has
2025-11-09 followed by 2026-01-05: fifty-seven days nobody measured, and a solid
spline across them would be an invention wearing the same visual authority as a
measurement, which is the most confident-looking part of the picture. A dashed
straight line says the same thing the hole said (nothing is known here) while
still joining the readings either side into one body over time. Straight and not
splined for the same reason: a curve across an eight-week silence would have a
shape, and a shape is a claim about what happened in there.

**This reverses #35, deliberately and by the owner.** That version broke the line
at a gap and left isolated readings floating, which is more rigorous and, in
daily use, unreadable: the 2024 pair and the two lone autumn rings looked like
stray marks rather than the earliest things known about the same person. Nothing
computed changed — the EMA has always spanned the gaps arithmetically
(`App\Services\Reporting\WeightEma`); only the drawing stopped.

The wash is the other half of the distinction: it fills under the SOLID runs
only. A second, dimmer wash under a bridge reads in dark mode as a seam between
two fills rather than as a statement, whereas its plain absence under the dashes
repeats what the dashes already say. One meaning per mark. The dashes are faded
as well as dashed, because a dash pattern alone lands at the same ink as the
solid stroke on a phone, which would make the bridge the most eye-catching thing
on a sparse window — backwards for the one part nobody measured.

A reading with no solid line through it keeps its ring even on the crowded views
where everything else thins to a dot: it sits mid-bridge, and as a 1.5-unit dot
the only measured thing in that stretch would be quieter than the guess running
through it. 2024-04-03 and 2024-09-14 are two numbers somebody typed in, not the
start of a trend, and the ring is what says so.

One point from BEFORE the window is allowed in, so a curve that was already
running does not appear to begin at the left edge. It is clipped to the plot, and
it is admitted only within `gapDays` — not because a bridge could not be drawn to
it, but because an arbitrarily old reading is not in view and would drag the value
axis out to meet it. Both of its numbers enter the y bounds, because the thin line
enters the plot from its RAW value and a raw morning sits up to a kilo off its own
trend; left out, that stretch of the thin line would be flattened against the top
of the clip and read as a rendering fault.

## The body chart's draw-in

`WeightChart.vue` draws itself oldest reading to newest: one pen, one pass, left
to right, with every ring, dot and value label landing as the front reaches it.
The choreography and its numbers are borrowed from `StressWeekChart.vue` rather
than invented, so two charts read in the same scroll do not have two
personalities; the CSS half is the `body-trend-draw` block in
`resources/css/app.css`.

**The pacing is arc length, not index.** Every leg is measured with `Math.hypot`
off coordinates this component already computes — no `getTotalLength()`, nothing
read back from the DOM, nothing that waits on layout — and a reading's delay is
its distance along the journey as a fraction of the whole. Index pacing would be
right only if every leg were the same length, and on the All view (fifteen
months, eight runs, spans from one day to eight weeks) it is not close: the front
would overshoot its own rings before the first winter.

**One `DRAW_MS` for every range.** The journey is normalised, so 1w with its five
rings and All with forty-eight take the same 950 ms — the stress chart's number,
deliberately. A per-point duration would make the range chips feel like they had
different amounts of patience.

**`CURVE_PAD` is 1.06 because `Math.hypot` measures the control polyline while the
pen draws the spline through it, which is always a little longer.** A run's dash
has to be at least as long as the path it hides: a dash shorter than its own path
leaves the far end of the run uncovered, i.e. sitting there visible before the pen
gets to it. Padding UP is the safe direction — the run then finishes a few percent
early and each ring lands just behind the front rather than just ahead of it,
which is the error nobody can see. Six percent is measured rather than guessed:
every run of every series on every range of the real history was flattened and
compared against its own polyline, the worst being 1.8 % (ten weigh-ins over a
year), and a synthetic daily zigzag across the full plot height — the shape a
monotone spline flattens hardest at, and not a body — reaches 2.0 %. Three times
the worst case costs 4 % of a run's duration at the end of it, and buys the
guarantee that no arrangement of weigh-ins can show a line before its time.

**A bridge is travel that is also ink**, and the one thing here that is not a copy
of the stress chart, where a gap draws nothing and only costs the pen time. A
dashed line cannot be revealed by `stroke-dashoffset` — one pattern cannot both
repeat every 3 units and hide the far end of the path — so a bridge is WIPED
instead, with the same `clip-path` inset the balance card's entrance uses. The
wipe is the honest equivalent rather than an approximation: a bridge is a straight
line, so distance along it is exactly proportional to distance across it, and a
wipe moving at constant speed IS the pen moving at constant speed. The dashes
appear from under the clip as it passes, which is what writing a dashed line looks
like. That it is a clip and not a mask is a choice of failure mode: a clip a
browser declines to animate leaves the line VISIBLE and unwiped, where a mask over
the stroke — the other way to do this — fails the other way round.

**The supporting cast arrives after the lead.** The thin raw line and the wash
fade in together once the trend has finished (`TAIL_MS` after `DRAW_MS`), and the
raw line gets no draw of its own: two pens racing along nearly the same path is
twice the motion for one story, and the thin line's whole job is to be found
second.

The draw is restarted by `:key="drawKey"`, which is series plus range. Vue patches
the existing SVG in place when a chip changes the numbers, and a CSS animation on
an element that was never replaced does not run again — without the key the chart
would draw itself once, on whichever range it happened to open on, and every chip
after that would swap the geometry in silently. The tap selection is deliberately
NOT in the key, and neither the rule nor the filled dot it draws is animated: they
are created by a tap long after the draw is over, and anything wearing an
animation class is animated the moment it enters the DOM, so a `.btd-tail` on the
selection would mean tapping a ring and waiting a second for the answer.

`tests/Feature/Ui/BodyChartDrawInTest.php` pins the parts of this that live in the
source: the `Math.hypot` measurement, `(distance / journey.value.total) * DRAW_MS`,
`leg.length * CURVE_PAD` with a pad greater than 1, the per-mark custom properties,
`:key="drawKey"`, and the bridge wipe's `clip-path: inset(-4px 100% -4px -4px)`.
