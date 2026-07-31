# Docker loop tests

Bats tests for `apps/api/docker/worker-loop.sh` and
`apps/api/docker/scheduler-loop.sh`.

The tests exercise the production loop scripts in isolation using a
fake `php` binary prepended to `PATH`. The fake records every
`artisan` invocation and can be configured to fail specific lanes so
the loop's lane persistence, signal forwarding, atomic readiness
marker, and bounded backoff can be observed.

## Running

From the repository root:

```sh
bats apps/api/docker/tests/
```

`bats` 1.13+ is required. The harness uses `dash`-compatible bash
features only; the production scripts are POSIX `/bin/sh` and are
exercised against both the fake `php` and the real `php` (the latter
only via the validation tests, since a real Laravel queue requires
MySQL/Redis).

## Files

- `helpers.bash` — bats helpers: tmpdir, fake `php`, loop start/stop,
  iteration wait, marker reset.
- `worker-loop.bats` — nine tests for the worker loop.
- `scheduler-loop.bats` — eight tests for the scheduler loop.

## Conventions

- Each test runs in `BATS_TEST_TMPDIR`; the readiness marker is
  redirected via `WORKER_READINESS_MARKER` and
  `SCHEDULER_READINESS_MARKER`, so the production `/tmp/worker.ready`
  and `/tmp/scheduler.ready` are never touched.
- The loop is started in the API directory so `php artisan` resolves
  to the shipped Laravel binary, mirroring the production container
  which sets `WORKDIR=/var/www/html`.
- All assertions use real signal handling (SIGTERM is delivered via
  `kill`) and real atomic writes (the same `mv -f` semantics
  Compose healthchecks rely on).
