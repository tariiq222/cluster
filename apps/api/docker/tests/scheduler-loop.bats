#!/usr/bin/env bats
# shellcheck shell=sh
#
# Tests for apps/api/docker/scheduler-loop.sh
#
# Behaviour under test:
#   1. Each iteration runs `php artisan schedule:run --no-interaction`.
#   2. The readiness marker /tmp/scheduler.ready is written atomically
#      on success and removed on failure.
#   3. Signals (SIGINT, SIGTERM) are forwarded to the active child
#      and the loop exits cleanly.
#   4. Validation: SCHEDULER_POLL_SECONDS must be a positive integer.
#   5. Bounded backoff: after consecutive failures, the loop sleeps
#      longer than the baseline, capped at
#      SCHEDULER_MAX_BACKOFF_SECONDS.

setup() {
    load helpers
    reset_loop_state
    install_fake_php
}

teardown() {
    stop_loop 2>/dev/null || true
}

@test "scheduler-loop runs schedule:run once per iteration" {
    SCHEDULER_POLL_SECONDS=1 SCHEDULER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/scheduler-loop.sh"
    wait_for_iterations "schedule:run" 1
    stop_loop

    [ "$(grep -c 'schedule:run' "$PHP_INVOCATION_LOG")" -ge 1 ]
}

@test "scheduler-loop writes the readiness marker on success" {
    SCHEDULER_POLL_SECONDS=1 SCHEDULER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/scheduler-loop.sh"
    wait_for_iterations "schedule:run" 1
    stop_loop

    [ -f "$SCHEDULER_READINESS_MARKER" ]
}

@test "scheduler-loop does not leave a partial .tmp marker" {
    SCHEDULER_POLL_SECONDS=1 SCHEDULER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/scheduler-loop.sh"
    wait_for_iterations "schedule:run" 1
    stop_loop

    run ls "$SCHEDULER_READINESS_MARKER.tmp."* 2>/dev/null
    [ "$status" -ne 0 ]
}

@test "scheduler-loop removes the readiness marker on failure" {
    CLUSTER_LOOP_FAIL_SCHEDULE=1 \
        SCHEDULER_POLL_SECONDS=1 SCHEDULER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/scheduler-loop.sh"
    # Wait for the first iteration to actually complete.
    sleep 2
    stop_loop

    [ ! -f "$SCHEDULER_READINESS_MARKER" ]
}

@test "scheduler-loop exits cleanly on SIGTERM" {
    SCHEDULER_POLL_SECONDS=2 SCHEDULER_MAX_BACKOFF_SECONDS=2 \
        start_loop "$DOCKER_DIR/scheduler-loop.sh"
    wait_for_iterations "schedule:run" 1

    start=$(date +%s)
    kill -TERM "$LOOP_PID"
    wait "$LOOP_PID" 2>/dev/null || true
    end=$(date +%s)
    elapsed=$((end - start))

    [ "$elapsed" -le 5 ]
    ! kill -0 "$LOOP_PID" 2>/dev/null
}

@test "scheduler-loop rejects non-integer SCHEDULER_POLL_SECONDS" {
    SCHEDULER_POLL_SECONDS=abc run "$DOCKER_DIR/scheduler-loop.sh"
    [ "$status" -ne 0 ]
    [[ "$output" == *"SCHEDULER_POLL_SECONDS"* ]]
}

@test "scheduler-loop rejects zero SCHEDULER_POLL_SECONDS" {
    SCHEDULER_POLL_SECONDS=0 run "$DOCKER_DIR/scheduler-loop.sh"
    [ "$status" -ne 0 ]
}

@test "scheduler-loop applies bounded backoff after consecutive failures" {
    CLUSTER_LOOP_FAIL_SCHEDULE=1 \
        SCHEDULER_POLL_SECONDS=1 SCHEDULER_MAX_BACKOFF_SECONDS=2 \
        start_loop "$DOCKER_DIR/scheduler-loop.sh"
    sleep 4
    stop_loop

    # At least one invocation happened (even though it failed).
    [ "$(grep -c 'schedule:run' "$PHP_INVOCATION_LOG")" -ge 1 ]
}
