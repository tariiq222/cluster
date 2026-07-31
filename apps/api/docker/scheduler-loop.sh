#!/bin/sh
# Cluster production scheduler loop.
#
# Runs `php artisan schedule:run --no-interaction` once per iteration
# so the Laravel scheduler ticks while the container is up.
#
# Atomic readiness marker: write-temp + mv -f replaces the marker
# atomically. Don't hide failure: on iteration failure the marker is
# removed so the healthcheck turns red and the orchestrator can
# restart. Signal forwarding: SIGINT/SIGTERM are forwarded to the
# active child and the loop exits cleanly with status 0. Bounded
# backoff: consecutive failures double the sleep up to
# SCHEDULER_MAX_BACKOFF_SECONDS.
#
# POSIX /bin/sh. Tested on Alpine BusyBox sh.

set -eu

readonly poll_seconds="${SCHEDULER_POLL_SECONDS:-60}"
readonly max_backoff="${SCHEDULER_MAX_BACKOFF_SECONDS:-300}"
readonly readiness_marker="${SCHEDULER_READINESS_MARKER:-/tmp/scheduler.ready}"
readonly no_interaction="--no-interaction"

case "$poll_seconds" in
  ''|*[!0-9]*|0)
    printf '%s\n' 'ERROR: SCHEDULER_POLL_SECONDS must be a positive integer.' >&2
    exit 2
    ;;
esac
case "$max_backoff" in
  ''|*[!0-9]*|0)
    printf '%s\n' 'ERROR: SCHEDULER_MAX_BACKOFF_SECONDS must be a positive integer.' >&2
    exit 2
    ;;
esac

current_child_pid=""

on_signal() {
    if [ -n "$current_child_pid" ]; then
        kill "$current_child_pid" 2>/dev/null || true
    fi
    exit 0
}
trap on_signal INT TERM

on_exit() {
    rm -f "${readiness_marker}.tmp".* 2>/dev/null || true
}
trap on_exit EXIT

write_marker() {
    tmp="${readiness_marker}.tmp.$$"
    if ! : > "$tmp" 2>/dev/null; then
        return 1
    fi
    if ! mv -f "$tmp" "$readiness_marker" 2>/dev/null; then
        rm -f "$tmp" 2>/dev/null || true
        return 1
    fi
    return 0
}

clear_marker() {
    rm -f "$readiness_marker" 2>/dev/null || true
}

next_sleep_seconds() {
    failures="$1"
    if [ "$failures" -le 0 ]; then
        printf '%s\n' "$poll_seconds"
        return 0
    fi
    sleep_for=$poll_seconds
    i=1
    while [ "$i" -lt "$failures" ] && [ "$sleep_for" -lt "$max_backoff" ]; do
        sleep_for=$((sleep_for * 2))
        i=$((i + 1))
    done
    if [ "$sleep_for" -gt "$max_backoff" ]; then
        sleep_for=$max_backoff
    fi
    printf '%s\n' "$sleep_for"
}

interruptible_sleep() {
    sleep_seconds="$1"
    sleep "$sleep_seconds" &
    current_child_pid=$!
    set +e
    wait "$current_child_pid"
    set -e
    current_child_pid=""
}

consecutive_failures=0
while :; do
    set +e
    php artisan schedule:run $no_interaction &
    current_child_pid=$!
    wait "$current_child_pid"
    rc=$?
    current_child_pid=""
    set -e

    if [ "$rc" -eq 0 ]; then
        write_marker || printf 'WARN: readiness marker write failed.\n' >&2
        consecutive_failures=0
    else
        printf 'WARN: scheduler iteration failed (exit=%d).\n' "$rc" >&2
        clear_marker
        consecutive_failures=$((consecutive_failures + 1))
    fi

    sleep_for=$(next_sleep_seconds "$consecutive_failures")
    interruptible_sleep "$sleep_for"
done
