# The gate records its result by commit

A full `scripts/ci.sh` run appends one line, `<sha> ci <utc> <rc> -`, to the file `GATE_LEDGER` names (default `/var/lib/fleet/gate-ledger`), so a result is placed by the commit it judged and not by the minute it finished. Whatever reads the file to decide whether a commit is cleared reads the newest row for that sha.

Only a full run records: `--filter`, a path, `--checks-only`, `--no-checks`, `--no-assets`, `--assets-only` and `--shell` each judge part of the tree, and a row for them would read as the whole gate. The row is written from the teardown trap, so a run that dies part-way records its non-zero exit as well as a green one records 0. A run refused before the trap is set (no compose file, no Docker daemon) never started a gate and records nothing.

A row says 0 only for a run whose suite proved it ran tests. PHPUnit exits 0 on an empty suite, so a broken `<directory>` in `phpunit.xml` would otherwise read as a pass. The suite's output is `tee`d, its status taken from `PIPESTATUS[0]`, and `GATE_SUITE_PASSED` is set only when the runner's last `Tests:` line counts more than 0 passed (with colour codes scrubbed under `LC_ALL=C`). On a full run the teardown trap turns any exit 0 without it into a failure: the run exits 1, and that is what the row records. The variable is unset at start, so a caller cannot pass it in. The check is in the script, not only in `phpunit.xml`, because a config file can lose a setting without anyone noticing.

Before anything is built, a run with checks runs `scripts/tests/ci-gate.sh` on the host with plain bash and git, because it stubs docker and needs no stack. The step passes only when the file exits 0 and its last line is `N passed, 0 failed` with N above 0, since an emptied or half-run file also exits 0. A missing file fails the step as well. Inside the test, the copied `ci.sh` sits in a fixture repo whose `scripts/tests/ci-gate.sh` is a stub that prints a verdict, so it does not recurse. There is no skip variable for this step, so nothing can switch it off and still get a row.

The memory guard counts both `ht-ci-` and `circa-ci-` stacks toward its limit of two, because the two copies can share a host.

A row is withheld, with a warning, when it would name the wrong thing: when `CI_GIT` reads a checkout other than this one, when HEAD moved during the run, or when git cannot say whether the tree is clean. A dirty tree is recorded as `<sha>-dirty`, which no deploy can resolve, so uncommitted work never clears a commit. A failed append warns and leaves the run's own exit unchanged.

`GATE_LEDGER` moves the file, which is how `scripts/tests/ci-gate.sh` and any deliberate red run read a row without writing to the shared one: pointing it away hides a row from a reader rather than inventing one.
