#!/usr/bin/env bash
# =============================================================================
# Health Tracker — run the test suite in a throwaway stack of its own
# =============================================================================
# Run it from anywhere inside the repository:
#
#   scripts/ci.sh                        # whole suite, then tear the stack down
#   scripts/ci.sh --keep                 # leave it warm for the next run
#   scripts/ci.sh --filter SleepTruncation
#   scripts/ci.sh tests/Feature/Ingest --stop-on-failure
#   scripts/ci.sh --down                 # tear down and exit
#   scripts/ci.sh --assets-only          # just build the front end
#   scripts/ci.sh --checks-only          # just pint + audits + phpstan + eslint
#   scripts/ci.sh --no-checks            # tests only, skip the checks
#
# A full run is SIX gates, and any one of them failing fails the run. They run
# in THIS order, which is the order of the `say` banners below: npm advisories,
# front-end lint (eslint), the node unit tests, code style (pint), composer
# advisories, static analysis (phpstan), then the PHP suite. The front-end half
# comes first because it needs no database and no stack. The checks are skipped
# automatically on a targeted run — passing a --filter or a path means you are
# iterating on one test and do not want to pay 90 seconds of analysis for it.
# `--checks-only` runs them alone.
#
# Anything this script does not recognise is passed straight to
# `php artisan test`, so `--filter`, `-p`, `--stop-on-failure` and a path all
# work exactly as they do locally.
#
# WHY THIS EXISTS
# The suite needs a database, and a checkout you are working in should never
# share one with anything else you are running. This script gives each checkout
# a throwaway compose stack of its own — named after the branch, torn down when
# it finishes — so two branches can be tested at once without colliding.
# =============================================================================
set -euo pipefail

# --- Where are we --------------------------------------------------------
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${REPO_ROOT}/docker-compose.ci.yml"

# --- Output --------------------------------------------------------------
if [[ -t 1 ]]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'
else
    C_RESET=''; C_BOLD=''; C_DIM=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''
fi
say()  { printf '%s==>%s %s\n' "${C_BLUE}${C_BOLD}" "${C_RESET}" "$*"; }
warn() { printf '%s[warn]%s %s\n' "${C_YELLOW}${C_BOLD}" "${C_RESET}" "$*" >&2; }
die()  { printf '%s[error]%s %s\n' "${C_RED}${C_BOLD}" "${C_RESET}" "$*" >&2; exit 1; }
note() { printf '%s      %s%s\n' "${C_DIM}" "$*" "${C_RESET}"; }

# --- Flags ---------------------------------------------------------------
KEEP=0            # leave the stack running when we are done
REBUILD=0         # --rebuild: build the :ci image with --no-cache (every run builds)
DOWN_ONLY=0       # tear down and exit
SHELL_ONLY=0      # drop into a shell instead of running tests
ASSETS_ONLY=0     # run the front-end build instead of running tests
NO_ASSETS=0       # skip the automatic front-end build (see build_assets)
WITH_REDIS=0      # start the profile-gated redis service
NO_MEM_CHECK=0    # skip the free-memory guard
NO_CHECKS=0       # skip pint/phpstan/eslint
CHECKS_ONLY=0     # run pint/phpstan/eslint and nothing else
TEST_ARGS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --keep|-k)         KEEP=1 ;;
        --rebuild)         REBUILD=1 ;;
        --down)            DOWN_ONLY=1 ;;
        --shell)           SHELL_ONLY=1 ;;
        --assets-only)     ASSETS_ONLY=1 ;;
        --no-assets)       NO_ASSETS=1 ;;
        --redis)           WITH_REDIS=1 ;;
        --no-mem-check)    NO_MEM_CHECK=1 ;;
        --no-checks)       NO_CHECKS=1 ;;
        --checks-only)     CHECKS_ONLY=1 ;;
        -h|--help)
            sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        --) shift; TEST_ARGS+=("$@"); break ;;
        *)  TEST_ARGS+=("$1") ;;
    esac
    shift
done

# --- Git plumbing --------------------------------------------------------
command -v git >/dev/null 2>&1 || die 'git is not on PATH.'
cd "${REPO_ROOT}"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[[ "${BRANCH}" == 'HEAD' ]] && BRANCH="detached-$(git rev-parse --short HEAD)"

# --- Which gates are we running ------------------------------------------
# The checks (pint, the two advisory scans, phpstan, eslint) look at the whole
# tree, so they mean the same thing whatever tests were asked for and cost the
# same ~2 minutes every time. On a full run that is the point. On
# `scripts/ci.sh --filter OneThing`, where somebody is going round a loop on a
# single test, it is two minutes of the wrong answer — so a TARGETED run skips
# them and says it did.
#
# Neither flag is required for the normal case: a bare `scripts/ci.sh` runs
# everything, which is what a branch has to be green under before it opens a PR.
RUN_CHECKS=1
TESTS_WANTED=1
CHECKS_SKIPPED_FOR_ARGS=0

if [[ "${CHECKS_ONLY}" -eq 1 ]]; then
    TESTS_WANTED=0
elif [[ "${NO_CHECKS}" -eq 1 ]]; then
    RUN_CHECKS=0
elif [[ "${#TEST_ARGS[@]}" -gt 0 ]]; then
    RUN_CHECKS=0
    CHECKS_SKIPPED_FOR_ARGS=1
fi

# --- Compose project name ------------------------------------------------
# The project name is what makes two stacks two stacks: compose derives the
# container names, the network name and the volume names from it. Derived from
# the branch, because the convention is one checkout per branch, so the branch is
# the unique key. Lowercased and non-alphanumerics folded to `-`, since compose
# only accepts [a-z0-9][a-z0-9_-]*.
#
# Two branches that sanitise to the SAME name (`feat/a-b` and `feat-a-b`) would
# collide; the name is printed on every run so that is visible, and CI_PROJECT
# overrides it.
sanitize() {
    printf '%s' "$1" \
        | tr '[:upper:]' '[:lower:]' \
        | sed -e 's/[^a-z0-9_-]\+/-/g' -e 's/^[^a-z0-9]\+//' -e 's/-\+$//'
}
PROJECT="${CI_PROJECT:-ht-ci-$(sanitize "${BRANCH}")}"

# `docker compose -p <project> -f <file>` and NOTHING else. Naming the file
# explicitly is what stops compose from also merging docker-compose.yml and
# docker-compose.override.yml.
dc() { docker compose -p "${PROJECT}" -f "${COMPOSE_FILE}" "$@"; }

# --- The front-end build -------------------------------------------------
# THE SUITE NEEDS THIS, for exactly one test, and it is worth knowing which:
# `ServiceWorkerTest::test_the_version_moves_with_the_build_and_not_with_anything_else`
# asserts that `BuildAssets->version()` differs from the `'no-build'` sentinel.
# With no `public/build/manifest.json` both sides of that comparison ARE
# 'no-build', so the test fails on any checkout that has not built — which a
# fresh clone is. A checkout that happens to have a stale manifest lying around
# passes, so the coupling stays invisible until you run the suite somewhere
# clean.
#
# It is cheap enough not to argue with: ~17s all in, and `node_modules/` is
# gitignored and dies with the stack. Skipped when a manifest already exists,
# so it costs nothing on later runs. `--no-assets` opts out.
build_assets() {
    docker compose -p "${PROJECT}" -f "${COMPOSE_FILE}" --profile assets run --rm assets
}

# --- The front-end unit tests --------------------------------------------
# `php artisan test` covers the half of this app that runs on the server. The
# other half draws every pixel, and for a long time nothing tested any of it —
# which was fine right up until a fault that lived entirely in the browser
# (`undefined is not an object`, three times in one morning, no server-side
# symptom at all) took an afternoon and a hand-read of a minified dependency to
# pin down.
#
# So the two modules that exist to survive that class of failure — lib/report.js
# and lib/inertia-guard.js — have tests, and they run here. `node --test` is
# built in: no vitest, no jsdom, no new dependency, nothing to keep current.
#
# Same image and same uid as the asset build, so `node_modules` is written once
# and owned by one user rather than being half root's.
run_js_tests() {
    docker compose -p "${PROJECT}" -f "${COMPOSE_FILE}" --profile assets run --rm assets \
        sh -c '[ -d node_modules ] || npm ci --no-audit --fund=false; npm test'
}

# --- The node advisory scan ----------------------------------------------
# Beside ESLint, not beside the composer half: this is the first step that needs
# node_modules, and a cold `npm ci` must not precede phpstan. docs/DECISIONS.md
run_js_audit() {
    docker compose -p "${PROJECT}" -f "${COMPOSE_FILE}" --profile assets run --rm assets \
        sh -c '[ -d node_modules ] || npm ci --no-audit --fund=false && npm audit --omit=dev --audit-level=high'
}

# --- The front-end lint --------------------------------------------------
# ESLint with eslint-plugin-vue's vue3-recommended set — see eslint.config.js
# for what is on, what is off and why each one.
#
# It runs in the same container as the tests above and for the same reason: this
# half of the app has no server-side symptom when it breaks. The suite proves
# the JSON that leaves the server is right; nothing but this proves the template
# consuming it spells the prop the same way.
#
# ERRORS FAIL THE BUILD, WARNINGS DO NOT. A warning here is a considered
# disagreement with a default (there is one: a snake_case prop the server sends
# under that name), and turning those into build failures is how a team learns
# to pass `--no-verify`.
run_js_lint() {
    docker compose -p "${PROJECT}" -f "${COMPOSE_FILE}" --profile assets run --rm assets \
        sh -c '[ -d node_modules ] || npm ci --no-audit --fund=false; npm run lint'
}
# `exec -T`: no TTY, so the exit status survives being called from a script or a
# CI runner rather than a terminal.
in_app() { dc exec -T app "$@"; }

[[ -f "${COMPOSE_FILE}" ]] || die "No docker-compose.ci.yml at ${COMPOSE_FILE}"
docker info >/dev/null 2>&1 || die 'Cannot talk to the Docker daemon.'

say "checkout ${C_BOLD}${REPO_ROOT}${C_RESET}"
note "branch  ${BRANCH}"
note "project ${PROJECT}"

if [[ "${CHECKS_SKIPPED_FOR_ARGS}" -eq 1 ]]; then
    note 'targeted run: skipping pint, the audits, phpstan and eslint (scripts/ci.sh --checks-only)'
fi

# --- Teardown ------------------------------------------------------------
# `down -v` removes this project's containers, its network and its ANONYMOUS
# volumes. It cannot reach another project's stack (different project) and it cannot
# reach the shared composer cache (declared `external`).
#
# shellcheck disable=SC2329  # invoked indirectly, by `trap teardown EXIT` below.
teardown() {
    local code=$?
    if [[ "${KEEP}" -eq 1 ]]; then
        echo
        say "stack left running (--keep). Next run reuses it; tear it down with:"
        note "scripts/ci.sh --down"
    else
        echo
        say 'tearing down'
        dc down -v --remove-orphans >/dev/null 2>&1 || true
    fi
    exit "${code}"
}

if [[ "${DOWN_ONLY}" -eq 1 ]]; then
    say 'tearing down'
    dc down -v --remove-orphans
    say 'down.'
    exit 0
fi

# --- Memory guard --------------------------------------------------------
# This VPS runs ~17 containers for six applications in 7.6 GB and has NO SWAP, so
# an OOM here does not slow something down, it kills something that was serving
# traffic. A CI stack costs roughly 250-400 MB once the suite is running.
if [[ "${NO_MEM_CHECK}" -eq 0 ]] && command -v free >/dev/null 2>&1; then
    AVAIL_MB="$(free -m | awk '/^Mem:/ {print $7}')"
    OTHERS="$(docker ps --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null \
                | grep '^ht-ci-' | sort -u | grep -cvxF "${PROJECT}" || true)"
    note "memory available ${AVAIL_MB} MB, other CI stacks up: ${OTHERS}"

    if [[ "${OTHERS}" -ge 2 ]]; then
        warn "${OTHERS} other CI stacks are already running."
        warn 'The guideline is a MAXIMUM OF TWO concurrent stacks per host.'
        warn 'Ask the other worker to finish, or run with --no-mem-check if you'
        warn 'know what the third one will cost.'
    fi
    if [[ "${AVAIL_MB}" -lt 600 ]]; then
        warn "only ${AVAIL_MB} MB available — a CI stack wants ~400 MB and there is no swap."
        warn 'Something serving traffic may be OOM-killed. Tear down an idle stack first:'
        warn '  docker compose ls | grep ht-ci-'
    fi
fi

trap teardown EXIT

# --- The shared composer cache -------------------------------------------
# Checkouts do NOT share vendor/ — each gets its own, installed from its own
# composer.lock, because a branch that bumps a dependency must not silently test
# against another branch's vendor tree. The download cache is shared instead, so
# the correctness stays per-checkout and only the slow part is reused.
docker volume inspect ht-ci-composer-cache >/dev/null 2>&1 \
    || { say 'creating shared composer cache volume'; docker volume create ht-ci-composer-cache >/dev/null; }

# --- Image ---------------------------------------------------------------
# `health-tracker/app:ci`, never `:latest` — see docker-compose.ci.yml. The
# Dockerfile is identical to production's, so this is a layer-cache hit.
CI_IMAGE="${CI_APP_IMAGE:-health-tracker/app:ci}"
# Always build: a layer-cache hit costs about a second, and a gate that reused
# an existing image never exercised a Dockerfile change (audit C1's root fix
# lived only in the Dockerfile). --rebuild drops the cache as well.
say "building ${CI_IMAGE}"
if [[ "${REBUILD}" -eq 1 ]]; then dc build --no-cache app; else dc build app; fi

# --- Boot ----------------------------------------------------------------
UP_SERVICES=(app)
if [[ "${WITH_REDIS}" -eq 1 ]]; then
    dc() { docker compose -p "${PROJECT}" -f "${COMPOSE_FILE}" --profile redis "$@"; }
    UP_SERVICES+=(redis)
fi

if [[ "${ASSETS_ONLY}" -eq 1 ]]; then
    # Do not let the teardown trap destroy a stack that was already warm when we
    # were called — an asset build has nothing to do with the database.
    if [[ -n "$(dc ps -q app 2>/dev/null)" ]]; then
        KEEP=1
    fi
    say 'building front-end assets (npm ci && npm run build)'
    build_assets
    say "${C_GREEN}assets built${C_RESET}"
    exit 0
fi

# The suite reads public/build/manifest.json (see build_assets), so make sure
# there is one before anything runs. `--checks-only` does not need it: pint,
# phpstan and eslint read source, not a build.
if [[ "${NO_ASSETS}" -eq 0 ]] && [[ "${TESTS_WANTED}" -eq 1 ]] && [[ ! -f "${REPO_ROOT}/public/build/manifest.json" ]]; then
    say 'no public/build/manifest.json — building front-end assets first'
    note 'one PWA test compares a real build version against the no-build sentinel'
    build_assets
fi

# Before the stack, because it needs none of it — and because a failure here is
# a two-second answer, not a two-minute one. Lint before the tests for the same
# reason: it is the cheaper of the two and it fails on things the tests cannot
# see.
if [[ "${NO_ASSETS}" -eq 0 ]] && [[ "${RUN_CHECKS}" -eq 1 ]]; then
    echo
    say 'node advisories (npm audit)'
    echo

    set +e
    run_js_audit
    AUDIT_STATUS=$?
    set -e

    if [[ "${AUDIT_STATUS}" -ne 0 ]]; then
        die "$(cat <<EOF
the node advisory step failed (exit ${AUDIT_STATUS}) — the install or the audit.

The step is \`[ -d node_modules ] || npm ci … && npm audit …\`, so this is either
a High/Critical advisory against a production package or an \`npm ci\` that never
got as far as auditing. The output above says which. See docs/DECISIONS.md.
EOF
)"
    fi

    echo
    say 'front-end lint (eslint)'
    echo

    set +e
    run_js_lint
    LINT_STATUS=$?
    set -e

    if [[ "${LINT_STATUS}" -ne 0 ]]; then
        die "eslint found errors (exit ${LINT_STATUS}). Fix the mechanical ones with: npm run lint:fix"
    fi
fi

if [[ "${NO_ASSETS}" -eq 0 ]] && [[ "${TESTS_WANTED}" -eq 1 ]]; then
    echo
    say 'front-end unit tests (node --test)'
    echo

    set +e
    run_js_tests
    JS_STATUS=$?
    set -e

    if [[ "${JS_STATUS}" -ne 0 ]]; then
        die "the JavaScript unit tests failed (exit ${JS_STATUS}). Run them alone with: npm test"
    fi
fi

# Was this checkout's stack already warm when we were called? Only `--checks-only`
# cares: it has nothing to do with the database, so it should not be the thing
# that throws away a stack somebody left running with --keep. Same courtesy the
# `--assets-only` branch above extends.
STACK_WAS_WARM=0
[[ -n "$(dc ps -q app 2>/dev/null)" ]] && STACK_WAS_WARM=1

say 'starting stack'
dc up -d --wait "${UP_SERVICES[@]}"

# `--wait` already blocks on the healthcheck, but a stack reused via --keep can
# come back with a postgres that is up and not yet accepting connections, so the
# guarantee is made explicit rather than assumed.
say 'waiting for postgres'
for i in $(seq 1 60); do
    if dc exec -T postgres pg_isready -U health_tracker -d health_tracker_test -h /var/run/postgresql >/dev/null 2>&1; then
        note "ready after ${i}s"
        break
    fi
    [[ "${i}" -eq 60 ]] && die 'postgres never became ready. Run scripts/ci.sh --down, then retry.'
    sleep 1
done

# The database is created by POSTGRES_DB at initdb time. This re-asserts it,
# because a stack kept warm across runs could have had it dropped by hand.
dc exec -T postgres psql -U health_tracker -d postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname='health_tracker_test'" 2>/dev/null | grep -q 1 \
    || {
        say 'creating health_tracker_test'
        dc exec -T postgres psql -U health_tracker -d postgres \
            -c 'CREATE DATABASE health_tracker_test OWNER health_tracker;' >/dev/null
    }

# --- .env.testing --------------------------------------------------------
# Written INSIDE the container so it lands owned by the container's user —
# `key:generate` rewrites this file, and it cannot if the host created it as
# root.
#
# It is gitignored, so a fresh checkout never has one. Derived from the committed
# template with the DB pointed at this stack's postgres service; the credentials
# are the throwaway ones from docker-compose.ci.yml, so nothing here can reach
# a real database even if DB_HOST were wrong.
if ! in_app test -f .env.testing; then
    say 'deriving .env.testing from .env.testing.example'
    in_app sh -eu -c '
        cp .env.testing.example .env.testing
        sed -i \
            -e "s/^DB_HOST=.*/DB_HOST=postgres/" \
            -e "s/^DB_PORT=.*/DB_PORT=5432/" \
            -e "s/^DB_DATABASE=.*/DB_DATABASE=health_tracker_test/" \
            -e "s/^DB_USERNAME=.*/DB_USERNAME=health_tracker/" \
            -e "s/^DB_PASSWORD=.*/DB_PASSWORD=ci-not-a-secret/" \
            .env.testing
    '
fi

# --- Dependencies --------------------------------------------------------
# Dev dependencies included: phpunit, pint and phpstan live there.
if ! in_app test -f vendor/autoload.php; then
    say 'installing composer dependencies (first run in this checkout)'
    note 'downloads are cached in the shared ht-ci-composer-cache volume'
    in_app composer install --no-interaction --prefer-dist --no-progress
    # Third layer, after the Dockerfile (no unzip → ZipArchive honors umask) and
    # composer.json's post-install/update hooks: whatever extracted the archives,
    # no code path under vendor/ may be world-writable (audit C1).
    in_app sh -c 'find vendor -perm -0002 ! -type l -exec chmod go-w {} +'
fi

if in_app grep -q '^APP_KEY=$' .env.testing 2>/dev/null; then
    say 'generating a testing APP_KEY'
    note 'fresh, and decrypts nothing in production'
    in_app php artisan key:generate --env=testing --no-interaction >/dev/null
fi

# --- Prove which database we are pointed at ------------------------------
# The README calls this out as the footgun that burned three sessions:
# `--env=testing` silently fell back to `.env`, which is the REAL environment.
# Cheap to assert, so assert it every run.
TARGET_DB="$(in_app php artisan tinker --env=testing \
    --execute="echo config('database.connections.pgsql.database');" 2>/dev/null | tr -d '\r\n' || true)"
case "${TARGET_DB}" in
    *health_tracker_test*) note "database ${TARGET_DB} (in this stack's postgres, tmpfs)" ;;
    *) die "REFUSING TO RUN: --env=testing resolves to '${TARGET_DB}', not health_tracker_test." ;;
esac

# --- Shell ---------------------------------------------------------------
if [[ "${SHELL_ONLY}" -eq 1 ]]; then
    say 'dropping into the app container (exit to tear down)'
    dc exec app bash || true
    exit 0
fi

# --- Code style, then static analysis ------------------------------------
# Both run inside the app container, against this checkout's own vendor/, for
# the same reason the suite does: the tool version that judges the branch is the
# one the branch's composer.lock pins, not whatever happens to be on the host.
#
# STYLE (pint.json, the `laravel` preset). The codebase was already uniformly
# clean under it; committing the config is what turns "we happen to agree" into
# something a branch can be held to. `--test` reports and changes nothing —
# reformatting somebody's tree from a CI script is not this script's business.
#
# STATIC ANALYSIS (phpstan.neon, level 8 + Larastan). The baseline that held
# the 579 findings from the day this was switched on has been paid off and is
# now empty, so this gate asserts the plain thing: no errors at all.
#
# `--memory-limit=512M` against the container's 768 MB cap: phpstan's own
# default is 128M, which this codebase exceeds and dies on with a message that
# does not obviously mean "raise the limit".
if [[ "${RUN_CHECKS}" -eq 1 ]]; then
    echo
    say 'code style (pint --test)'
    echo

    set +e
    dc exec -T app ./vendor/bin/pint --test
    PINT_STATUS=$?
    set -e

    [[ "${PINT_STATUS}" -ne 0 ]] \
        && die "pint found style violations (exit ${PINT_STATUS}). Fix them with: vendor/bin/pint"

    echo
    say 'php advisories (composer audit)'
    echo

    # --locked --no-dev: the lockfile, production packages only. An advisory
    # against phpunit or pint is not on the site. See docs/DECISIONS.md.
    #
    # --abandoned=report because composer's own default is `fail`: an abandoned
    # package is not a vulnerability and is not fixable the afternoon it lands.
    set +e
    dc exec -T app composer audit --locked --no-dev --abandoned=report
    AUDIT_PHP_STATUS=$?
    set -e

    if [[ "${AUDIT_PHP_STATUS}" -ne 0 ]]; then
        die "composer audit reported an advisory against a production package (exit ${AUDIT_PHP_STATUS}). See docs/DECISIONS.md."
    fi

    echo
    say 'static analysis (phpstan, level 8 + larastan)'
    note 'one process, ~90s — see the parallel: block in phpstan.neon for why'
    echo

    set +e
    dc exec -T app ./vendor/bin/phpstan analyse --no-progress --memory-limit=512M
    STAN_STATUS=$?
    set -e

    if [[ "${STAN_STATUS}" -ne 0 ]]; then
        die "$(cat <<'EOF'
phpstan found errors.

Fix the code. For a genuine analyzer limitation you can name, add an inline
`@phpstan-ignore <identifier>` with the reason on the same line, where the next
reader will see it.

The baseline is `ignoreErrors: []` and stays that way: NEVER --generate-baseline
to get green. See CLAUDE.md's Comments section.
EOF
)"
    fi
fi

if [[ "${CHECKS_ONLY}" -eq 1 ]]; then
    [[ "${STACK_WAS_WARM}" -eq 1 ]] && KEEP=1
    echo
    say "${C_GREEN}${C_BOLD}checks green${C_RESET} — ${BRANCH}"
    exit 0
fi

# --- Run -----------------------------------------------------------------
echo
if [[ "${#TEST_ARGS[@]}" -gt 0 ]]; then
    say "php artisan test ${TEST_ARGS[*]}"
else
    say 'php artisan test'
fi
echo

set +e
dc exec -T app php artisan test "${TEST_ARGS[@]}"
STATUS=$?
set -e

echo
if [[ "${STATUS}" -eq 0 ]]; then
    say "${C_GREEN}${C_BOLD}green${C_RESET} — ${BRANCH} in ${PROJECT}"
else
    say "${C_RED}${C_BOLD}failed${C_RESET} (exit ${STATUS}) — ${BRANCH} in ${PROJECT}"
    note 'to poke at it without losing the database: scripts/ci.sh --keep --shell'
fi

# teardown runs on EXIT and preserves this status
exit "${STATUS}"
