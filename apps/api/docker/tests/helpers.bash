#!/usr/bin/env bash
# shellcheck shell=sh
# Shared helpers for docker/*-loop.sh bats tests.
#
# Conventions:
#   - All tests work against a POSIX `/bin/sh` script. The helpers here use
#     bash only for bats harness and array handling; the production scripts
#     stay portable so they run on Alpine BusyBox sh.
#   - Each test works in an isolated BATS_TEST_TMPDIR; the readiness marker
#     defaults to a path inside it so production healthcheck files are not
#     touched.
#   - A fake `php` binary is prepended to PATH so tests can observe and
#     steer the artisan invocations.

set -u

# Resolve docker dir from the bats test file location.
DOCKER_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME:-$0}")/.." && pwd)"
# API directory is the parent of the docker dir, used so the loop
# launches with cwd that contains `artisan` — mirrors the production
# container which sets WORKDIR=/var/www/html.
API_DIR="$(cd "$DOCKER_DIR/.." && pwd)"

# Per-test scratch area. BATS_TEST_TMPDIR is reserved by bats.
TEST_TMPDIR="${BATS_TEST_TMPDIR:-$(mktemp -d -t cluster-loop.XXXXXX)}"
mkdir -p "$TEST_TMPDIR"

FAKE_BIN="$TEST_TMPDIR/bin"
mkdir -p "$FAKE_BIN"

# Default readiness markers are inside the test TMPDIR. Tests that want
# different values can override these in the test body before sourcing
# helpers again.
WORKER_READINESS_MARKER="${WORKER_READINESS_MARKER:-$TEST_TMPDIR/worker.ready}"
export WORKER_READINESS_MARKER
SCHEDULER_READINESS_MARKER="${SCHEDULER_READINESS_MARKER:-$TEST_TMPDIR/scheduler.ready}"
export SCHEDULER_READINESS_MARKER

# Disable real backoff during tests so loops cycle quickly.
WORKER_POLL_SECONDS="${WORKER_POLL_SECONDS:-1}"
export WORKER_POLL_SECONDS
WORKER_MAX_BACKOFF_SECONDS="${WORKER_MAX_BACKOFF_SECONDS:-1}"
export WORKER_MAX_BACKOFF_SECONDS
SCHEDULER_POLL_SECONDS="${SCHEDULER_POLL_SECONDS:-1}"
export SCHEDULER_POLL_SECONDS
SCHEDULER_MAX_BACKOFF_SECONDS="${SCHEDULER_MAX_BACKOFF_SECONDS:-1}"
export SCHEDULER_MAX_BACKOFF_SECONDS

# Tighter signal grace so kill+wait completes quickly in tests.
SIGNAL_GRACE_SECONDS="${SIGNAL_GRACE_SECONDS:-1}"
export SIGNAL_GRACE_SECONDS

# Reserve unique env-var names so helper exports don't leak into the
# parent shell. The production scripts compute these from script-local
# documentation, not from here.
export CLUSTER_LOOP_TEST_FAKE_BIN="$FAKE_BIN"
export CLUSTER_LOOP_TEST_TMPDIR="$TEST_TMPDIR"

# PATH so the fake `php` is found first.
export PATH="$FAKE_BIN:$PATH"

# Record of every artisan invocation made by the loop. Each line is
# the full argv of the `php` binary (we expect a single leading
# `artisan` token), for example:
#   artisan organization:relay-person-events --once --no-interaction
PHP_INVOCATION_LOG="$TEST_TMPDIR/php_invocations.log"
: > "$PHP_INVOCATION_LOG"
export CLUSTER_LOOP_PHP_LOG="$PHP_INVOCATION_LOG"

# Counting helpers are written via a fake `php` installed by
# `install_fake_php`. Tests call that to (re)install a fake binary
# between scenarios.

# Install a fake php that:
#   - appends every invocation (full argv) to CLUSTER_LOOP_PHP_LOG
#   - exits 0 unless ENV CLUSTER_LOOP_FAIL_FILE points at a file
#     whose contents enumerate exact full-argv lines that should
#     fail. Lines consumed (removed) by each invocation.
#   - if ENV CLUSTER_LOOP_FAIL_LANES is "1", fails when any lane
#     argv (the artisan command) matches a substring in the
#     invocation line.
install_fake_php() {
    local log_path="$PHP_INVOCATION_LOG"
    cat > "$FAKE_BIN/php" <<PHP
#!/bin/sh
printf '%s\n' "\$*" >> "$log_path"
case "\$*" in
  *organization:relay-person-events*)
    if [ "\${CLUSTER_LOOP_FAIL_ORGANIZATION:-0}" = "1" ]; then exit 1; fi ;;
  *identity:consume-person-events*)
    if [ "\${CLUSTER_LOOP_FAIL_IDENTITY:-0}" = "1" ]; then exit 1; fi ;;
  *documents:relay-events*)
    if [ "\${CLUSTER_LOOP_FAIL_DOCUMENTS:-0}" = "1" ]; then exit 1; fi ;;
  *tasks:relay-events*)
    if [ "\${CLUSTER_LOOP_FAIL_TASKS:-0}" = "1" ]; then exit 1; fi ;;
  *schedule:run*)
    if [ "\${CLUSTER_LOOP_FAIL_SCHEDULE:-0}" = "1" ]; then exit 1; fi ;;
esac
exit 0
PHP
    chmod +x "$FAKE_BIN/php"
}

# Reset per-test state.
reset_loop_state() {
    : > "$PHP_INVOCATION_LOG"
    rm -f "$WORKER_READINESS_MARKER" "$WORKER_READINESS_MARKER".tmp.* 2>/dev/null || true
    rm -f "$SCHEDULER_READINESS_MARKER" "$SCHEDULER_READINESS_MARKER".tmp.* 2>/dev/null || true
    unset CLUSTER_LOOP_FAIL_ORGANIZATION \
          CLUSTER_LOOP_FAIL_IDENTITY \
          CLUSTER_LOOP_FAIL_DOCUMENTS \
          CLUSTER_LOOP_FAIL_TASKS \
          CLUSTER_LOOP_FAIL_SCHEDULE
}

# Run the loop in the background and capture its PID. Caller is
# responsible for stopping it with `stop_loop` or via signal.
# The script is invoked from the API directory so that `php artisan`
# resolves to the shipped artisan binary, matching the production
# container which sets WORKDIR=/var/www/html.
start_loop() {
    local script="$1"
    (
        cd "$API_DIR" 2>/dev/null || cd "$DOCKER_DIR/.."
        "$script" > "$TEST_TMPDIR/loop.stdout" 2> "$TEST_TMPDIR/loop.stderr" &
        echo $! > "$TEST_TMPDIR/loop.pid"
    )
    LOOP_PID=$(cat "$TEST_TMPDIR/loop.pid")
    echo "started loop pid=$LOOP_PID log=$PHP_INVOCATION_LOG"
}

# Stop the loop with SIGTERM and wait. Returns whatever exit code
# the loop produced, ignoring the typical 143 from being killed.
stop_loop() {
    local pid="${LOOP_PID:-}"
    if [ -z "$pid" ] || ! kill -0 "$pid" 2>/dev/null; then
        return 0
    fi
    kill -TERM "$pid" 2>/dev/null || true
    local i=0
    while [ "$i" -lt 50 ] && kill -0 "$pid" 2>/dev/null; do
        sleep 0.1
        i=$((i + 1))
    done
    if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
    fi
    wait "$pid" 2>/dev/null || true
    return 0
}

# Wait for the loop to have run at least N iterations (one
# iteration = all lanes invoked). We approximate by counting
# invocation lines for the first lane.
wait_for_iterations() {
    local marker="${1:-relay-person-events}"
    local min_iterations="${2:-1}"
    local i=0
    while [ "$i" -lt 100 ]; do
        local count=0
        if [ -f "$PHP_INVOCATION_LOG" ]; then
            count=$(grep -c "$marker" "$PHP_INVOCATION_LOG" 2>/dev/null || true)
            count=${count:-0}
        fi
        if [ "$count" -ge "$min_iterations" ] 2>/dev/null; then
            return 0
        fi
        sleep 0.1
        i=$((i + 1))
    done
    return 1
}
