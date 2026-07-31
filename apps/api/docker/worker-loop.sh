#!/bin/sh
# Cluster production worker loop.
#
# Runs five independent worker lanes once per iteration:
#   1. organization:relay-person-events
#   2. identity:consume-person-events
#   3. work-records:relay-pending
#   4. documents:relay-events
#   5. notifications:consume-work-record-submitted
#
# Lane persistence: a failure in one lane does not abort the
# others. Atomic readiness marker: write-temp + mv -f replaces
# the marker file atomically. Don't hide failure: when any lane
# fails, the marker is removed so the healthcheck turns red and
# the orchestrator can restart the container. Signal forwarding:
# SIGINT/SIGTERM are forwarded to the active child and the loop
# exits cleanly with status 0. Bounded backoff: consecutive
# failures double the sleep up to WORKER_MAX_BACKOFF_SECONDS.
#
# POSIX /bin/sh. Tested on Alpine BusyBox sh.

set -eu

readonly consumer="${NOTIFICATIONS_CONSUMER_NAME:-production-worker}"
readonly poll_seconds="${WORKER_POLL_SECONDS:-2}"
readonly max_backoff="${WORKER_MAX_BACKOFF_SECONDS:-60}"
readonly readiness_marker="${WORKER_READINESS_MARKER:-/tmp/worker.ready}"
readonly no_interaction="--no-interaction"

case "$poll_seconds" in
  ''|*[!0-9]*|0)
    printf '%s\n' 'ERROR: WORKER_POLL_SECONDS must be a positive integer.' >&2
    exit 2
    ;;
esac
case "$max_backoff" in
  ''|*[!0-9]*|0)
    printf '%s\n' 'ERROR: WORKER_MAX_BACKOFF_SECONDS must be a positive integer.' >&2
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

run_lane() {
    label="$1"
    shift
    set +e
    php artisan "$@" $no_interaction &
    current_child_pid=$!
    wait "$current_child_pid"
    rc=$?
    current_child_pid=""
    set -e
    if [ "$rc" -ne 0 ]; then
        printf 'WARN: worker lane failed (label=%s exit=%d).\n' "$label" "$rc" >&2
        iteration_failures=$((iteration_failures + 1))
    fi
}

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
    iteration_failures=0

    run_lane organization:relay-person-events organization:relay-person-events --once
    run_lane identity:consume-person-events identity:consume-person-events --once --consumer="$consumer"
    run_lane work-records:relay-pending work-records:relay-pending --once
    run_lane documents:relay-events documents:relay-events --once
    run_lane notifications:consume-work-record-submitted notifications:consume-work-record-submitted --once --consumer="$consumer"

    if [ "$iteration_failures" -eq 0 ]; then
        write_marker || printf 'WARN: readiness marker write failed.\n' >&2
        consecutive_failures=0
    else
        clear_marker
        consecutive_failures=$((consecutive_failures + 1))
    fi

    sleep_for=$(next_sleep_seconds "$consecutive_failures")
    interruptible_sleep "$sleep_for"
done
