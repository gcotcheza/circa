#!/usr/bin/env bash
# Cases for scripts/ci.sh's npm audit flags, its result row and its image tag, run
# on a copy in a throwaway repo with docker stubbed out: bash scripts/tests/ci-gate.sh
set -uo pipefail

HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd -- "${HERE}/../.." && pwd)"
CI_SH="${CI_SH:-${ROOT}/scripts/ci.sh}"
CI_COMPOSE="${CI_COMPOSE:-${ROOT}/docker-compose.ci.yml}"
PROD_COMPOSE="${ROOT}/docker-compose.yml"

WORK="$(mktemp -d)"
trap 'rm -rf -- "${WORK}"' EXIT
PASSED=0
FAILED=0
ok()     { PASSED=$((PASSED + 1)); printf 'ok - %s\n' "$1"; }
not_ok() { FAILED=$((FAILED + 1)); printf 'not ok - %s\n' "$1"; }
check()  { local name=$1; shift; if "$@"; then ok "${name}"; else not_ok "${name}"; fi; }

docker() {
    printf '%s\n' "$*" >>"${STUB_LOG}"
    case "$*" in
        *tinker*) echo health_tracker_test ;;
        *'artisan test'*)
            [[ -z "${STUB_MOVE_HEAD:-}" ]] \
                || git -C "${STUB_MOVE_HEAD}" -c user.name=fixture -c user.email=fixture@example.invalid commit -q --allow-empty -m moved
            return "${STUB_SUITE_RC:-0}" ;;
        *psql*) echo 1 ;;
        *'grep -q'*) return 1 ;;
    esac
    return 0
}
export -f docker

REPO="${WORK}/repo"
mkdir -p "${REPO}/scripts"
cp -- "${CI_SH}" "${REPO}/scripts/ci.sh"
cp -- "${CI_COMPOSE}" "${REPO}/docker-compose.ci.yml"
git init -q "${REPO}"
git -C "${REPO}" add scripts/ci.sh docker-compose.ci.yml
git -C "${REPO}" -c user.name=fixture -c user.email=fixture@example.invalid commit -q -m fixture
HEAD_SHA="$(git -C "${REPO}" rev-parse HEAD)"

# run_gate <case> [suite rc] [ci.sh args...]: leaves $WORK/<case>.{ledger,log,out,rc}
run_gate() {
    local name=$1 suite_rc=$2; shift 2
    : >"${WORK}/${name}.log"
    STUB_LOG="${WORK}/${name}.log" STUB_SUITE_RC="${suite_rc}" GATE_LEDGER="${WORK}/${name}.ledger" \
        CI_GIT="${GATE_GIT:-}" CI_PROJECT='' bash "${REPO}/scripts/ci.sh" --no-mem-check "$@" >"${WORK}/${name}.out" 2>&1
    echo $? >"${WORK}/${name}.rc"
}
rc_of()   { cat -- "${WORK}/$1.rc"; }
row_of()  { cat -- "${WORK}/$1.ledger" 2>/dev/null; }
rows_in() { if [[ -f "${WORK}/$1.ledger" ]]; then wc -l <"${WORK}/$1.ledger"; else echo 0; fi; }
row_is()  { [[ "$(rows_in "$1")" -eq 1 && "$(row_of "$1")" =~ ^$2\ ci\ [0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\ $3\ -$ ]]; }

audit_line() { grep -F 'npm audit ' -- "${WORK}/green.log"; }
audit_is_full_high() {
    local line; line="$(audit_line)" || return 1
    [[ "${line}" == *'npm audit --audit-level=high'* ]] \
        && ! grep -qE -- '--omit|--production|--only' <<<"${line}"
}

app_image() {
    awk '/^  app:/{in_app=1; next} /^  [a-z]/{in_app=0} in_app && /^    image:/{sub(/^    image: */, ""); gsub(/\x27|"/, ""); print; exit}' "$1"
}
gate_tag_is_its_own() {
    local tag; tag="$(app_image "${CI_COMPOSE}")"
    [[ -n "${tag}" && "${tag}" != *'$'* && "${tag}" != *:latest ]] || return 1
    grep -qxF "==> building ${tag}" -- "${WORK}/green.out" || return 1
    ! grep -qE "^ *image: *['\"]?${tag}['\"]? *$" -- "${PROD_COMPOSE}"
}
gate_never_names_production_files() {
    ! grep -qE 'docker-compose\.yml|:latest' -- "${WORK}/green.log" \
        && grep -q "docker-compose.ci.yml build app" -- "${WORK}/green.log"
}

run_gate green 0
run_gate red 3
run_gate targeted 0 --filter OneTest

check 'a green full run exits 0' test "$(rc_of green)" -eq 0
check 'the npm audit runs over dev packages too, at --audit-level=high' audit_is_full_high
check 'a green full run writes one <sha> ci <utc> 0 - row for HEAD' row_is green "${HEAD_SHA}" 0
check 'a failing suite fails the run with its own rc' test "$(rc_of red)" -eq 3
check 'a failing full run records its rc in the row' row_is red "${HEAD_SHA}" 3
check 'a targeted run records nothing' test "$(rows_in targeted)" -eq 0
check 'the gate app image is a tag of its own, never production'"'"'s' gate_tag_is_its_own
check 'the gate builds from docker-compose.ci.yml and never names a production file or :latest' \
    gate_never_names_production_files

printf 'untracked\n' >"${REPO}/stray"
run_gate dirty 0
check 'a dirty tree is recorded as <sha>-dirty' row_is dirty "${HEAD_SHA}-dirty" 0

git init -q "${WORK}/other"
git -C "${WORK}/other" -c user.name=fixture -c user.email=fixture@example.invalid commit -q --allow-empty -m other
GATE_GIT="git -C ${WORK}/other" run_gate stranger 0
check 'a CI_GIT that reads another checkout records nothing' test "$(rows_in stranger)" -eq 0

STUB_MOVE_HEAD="${REPO}" run_gate moved 0
check 'a run whose HEAD moved under it records nothing' test "$(rows_in moved)" -eq 0

printf '%d passed, %d failed\n' "${PASSED}" "${FAILED}"
[[ "${FAILED}" -eq 0 && "${PASSED}" -eq 11 ]]
