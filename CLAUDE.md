# Contributing

## Layout
A Laravel 13 + Inertia/Vue 3 app on Postgres: HTTP and domain code in `app/`,
the Vue front end in `resources/js/`, schema in `database/migrations/`, and the
suite in `tests/` (PHP) and `tests/js/` (node).

## Tests
`scripts/ci.sh` runs the whole gate — eslint, the node tests, Pint, dependency
advisories, PHPStan, then the PHP suite — in a throwaway compose stack of its
own. Pass a `--filter` or a path to iterate on one test; `--checks-only` runs
just the static checks. It needs Docker and nothing else.

## Comments
Compact why-comments: every constraint or invariant the code cannot show, each
stated once, why-first, in prose. Rationale too long for that lives in
`docs/rationale-app.md`, `docs/rationale-data.md` or `docs/rationale-frontend.md`
with a one-line pointer from the code, `See docs/rationale-x.md § "Heading"`;
process decisions live in `docs/DECISIONS.md`.

## Static analysis
The PHPStan baseline is EMPTY (`ignoreErrors: []`) and stays that way: fix the
code, or — only for a genuine analyzer limitation you can name — add an inline
`@phpstan-ignore <identifier>` with the reason on the same line. Never
regenerate a baseline to get to green.

## Validation artifacts
The browser's copy of the validation rules is GENERATED. Change a rule in
`app/Http/Requests` (or `LoginController::RULES`) and re-run
`php artisan validation:export`, which rewrites
`resources/js/lib/validation/rules.generated.json` and the case fixtures.
`RulesAgreementTest` and `tests/js/validation-agreement.test.js` compare the
artifacts against the real validator, so a stale export fails the gate.
See `docs/DECISIONS.md` § "the-browsers-copy-of-the-rules-is-generated-and-gated".
