# The gate has a tag of its own

The gate builds and runs `circa/app:ci`, written as a literal in `docker-compose.ci.yml`, and its stacks are named `circa-ci-<branch>`. Production runs `health-tracker/app:latest`, and a gate that built that tag would re-point what the live containers are recreated from.

The tag carries this repository's own name rather than `health-tracker/app:ci` because the same Dockerfile is built from more than one checkout of this code: a shared gate tag lets one checkout's gate run another's image, and a shared stack name lets one checkout's `down -v` tear down another's database. There is no environment knob for the tag: a checker that reads `${VAR:-default}` sees only the default, so an override could build production's tag under a passing check.

`scripts/tests/ci-gate.sh` fails if the gate's app image is missing, carries a variable, ends in `:latest`, or is any image `docker-compose.yml` runs.
