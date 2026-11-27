# The gate records its result by commit

A full `scripts/ci.sh` run appends one line, `<sha> ci <utc> <rc> -`, to the file `GATE_LEDGER` names (default `/var/lib/fleet/gate-ledger`), so a result is placed by the commit it judged and not by the minute it finished. Whatever reads the file to decide whether a commit is cleared reads the newest row for that sha.

Only a full run records: `--filter`, a path, `--checks-only`, `--no-checks`, `--no-assets`, `--assets-only` and `--shell` each judge part of the tree, and a row for them would read as the whole gate. The row is written from the teardown trap, so a run that dies part-way records its non-zero exit as well as a green one records 0. A run refused before the trap is set (no compose file, no Docker daemon) never started a gate and records nothing.

A row is withheld, with a warning, when it would name the wrong thing: when `CI_GIT` reads a checkout other than this one, when HEAD moved during the run, or when git cannot say whether the tree is clean. A dirty tree is recorded as `<sha>-dirty`, which no deploy can resolve, so uncommitted work never clears a commit. A failed append warns and leaves the run's own exit unchanged.

`GATE_LEDGER` moves the file, which is how `scripts/tests/ci-gate.sh` and any deliberate red run read a row without writing to the shared one: pointing it away hides a row from a reader rather than inventing one.
