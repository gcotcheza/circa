# The npm audit covers dev packages

`scripts/ci.sh` runs `npm audit --audit-level=high` with no `--omit=dev`. The dev half of `package-lock.json` is not on the site, but it is what runs on every machine that builds or gates this repository: vite, eslint and their plugins execute with the contributor's files and network, inside the same step that produces the bundle the site serves. A High advisory there is a hole in the build, not noise, so it fails the gate the same as one in a production package. The floor stays at High, so a Moderate or Low advisory is printed and passes.

The composer half keeps `--no-dev`: its dev packages (phpunit, pint, larastan) run only inside the throwaway gate container and never touch what ships.

If a High advisory ever lands on a dev package with no fix available, the way out is an allowlist that names that one advisory with a reason and an expiry, read by a filter over `npm audit --json`. It is not built yet because nothing needs it: a full audit, dev packages included, finds 0 vulnerabilities today. Putting `--omit=dev` back is not the way out.

`scripts/tests/ci-gate.sh` fails if the audit step gains `--omit`, `--production` or `--only`, or loses `--audit-level=high`.
