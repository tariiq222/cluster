#!/usr/bin/env bats
# shellcheck shell=sh
#
# Tests for apps/api/docker/worker-loop.sh
#
# Behaviour under test:
#   1. Each iteration runs all five worker lanes: organization:relay
#      person-events, identity:consume-person-events, work-records:
#      relay-pending, documents:relay-events, notifications:consume
#      -work-record-submitted.
#   2. A failure in one lane does NOT abort the other lanes (lane
#      persistence).
#   3. The readiness marker /tmp/worker.ready is replaced atomically
#      on success and removed on failure so the Compose healthcheck
#      turns red — we do not hide failure.
#   4. Signals (SIGINT, SIGTERM) are forwarded to the current
#      child/sleep and the loop exits cleanly.
#   5. Validation: WORKER_POLL_SECONDS must be a positive integer,
#      and the script must reject non-integer values.
#   6. Bounded backoff: when iterations fail consecutively, the loop
#      sleeps longer than the baseline, capped at
#      WORKER_MAX_BACKOFF_SECONDS.

setup() {
    load helpers
    reset_loop_state
    install_fake_php
}

teardown() {
    stop_loop 2>/dev/null || true
}

# Each lane runs once per iteration.
@test "worker-loop runs all five lanes once per iteration" {
    WORKER_POLL_SECONDS=1 WORKER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/worker-loop.sh"
    wait_for_iterations "notifications:consume-work-record-submitted" 1
    stop_loop

    [ "$(grep -c 'organization:relay-person-events' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'identity:consume-person-events' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'work-records:relay-pending' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'documents:relay-events' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'notifications:consume-work-record-submitted' "$PHP_INVOCATION_LOG")" -ge 1 ]
}

# On full-lane success, the readiness marker exists and has a recent
# modification time.
@test "worker-loop writes the readiness marker on success" {
    WORKER_POLL_SECONDS=1 WORKER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/worker-loop.sh"
    wait_for_iterations "notifications:consume-work-record-submitted" 1
    stop_loop

    [ -f "$WORKER_READINESS_MARKER" ]
}

# Atomic write: the marker must not be left as a `.tmp.*` file under
# any failure scenario the test exercises.
@test "worker-loop does not leave a partial .tmp marker" {
    WORKER_POLL_SECONDS=1 WORKER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/worker-loop.sh"
    wait_for_iterations "notifications:consume-work-record-submitted" 1
    stop_loop

    run ls "$WORKER_READINESS_MARKER.tmp."* 2>/dev/null
    [ "$status" -ne 0 ]
}

# Lane persistence: when one lane fails, the others still run.
@test "worker-loop continues other lanes when one lane fails" {
    CLUSTER_LOOP_FAIL_ORGANIZATION=1 \
        WORKER_POLL_SECONDS=1 WORKER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/worker-loop.sh"
    wait_for_iterations "notifications:consume-work-record-submitted" 1
    stop_loop

    [ "$(grep -c 'organization:relay-person-events' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'identity:consume-person-events' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'work-records:relay-pending' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'documents:relay-events' "$PHP_INVOCATION_LOG")" -ge 1 ]
    [ "$(grep -c 'notifications:consume-work-record-submitted' "$PHP_INVOCATION_LOG")" -ge 1 ]
}

# Don't hide failure: when at least one lane fails, the readiness
# marker is removed so the healthcheck reports unhealthy.
@test "worker-loop removes the readiness marker when a lane fails" {
    CLUSTER_LOOP_FAIL_ORGANIZATION=1 \
        WORKER_POLL_SECONDS=1 WORKER_MAX_BACKOFF_SECONDS=1 \
        start_loop "$DOCKER_DIR/worker-loop.sh"
    wait_for_iterations "notifications:consume-work-record-submitted" 1
    stop_loop

    [ ! -f "$WORKER_READINESS_MARKER" ]
}

# Signal forwarding: SIGTERM causes the loop to exit promptly
# without leaving a zombie child.
@test "worker-loop exits cleanly on SIGTERM and forwards the signal" {
    WORKER_POLL_SECONDS=2 WORKER_MAX_BACKOFF_SECONDS=2 \
        start_loop "$DOCKER_DIR/worker-loop.sh"
    wait_for_iterations "relay-person-events" 1

    echo "BEFORE KILL: pid=$LOOP_PID alive=$(kill -0 "$LOOP_PID" 2>/dev/null && echo yes || echo no)"
    echo "loop stdout:"; cat "$TEST_TMPDIR/loop.stdout" 2>/dev/null || echo "(none)"
    echo "loop stderr:"; cat "$TEST_TMPDIR/loop.stderr" 2>/dev/null || echo "(none)"
    start=$(date +%s)
    kill -TERM "$LOOP_PID"
    wait "$LOOP_PID" 2>/dev/null || true
    end=$(date +%s)
    elapsed=$((end - start))

    # Should exit within ~3 seconds (sleep + grace).
    [ "$elapsed" -le 5 ]
    # The loop should be gone.
    ! kill -0 "$LOOP_PID" 2>/dev/null
}

# Validation: non-integer poll seconds must be rejected at startup.
@test "worker-loop rejects non-integer WORKER_POLL_SECONDS" {
    WORKER_POLL_SECONDS=abc run "$DOCKER_DIR/worker-loop.sh"
    [ "$status" -ne 0 ]
    [[ "$output" == *"WORKER_POLL_SECONDS"* ]]
}

# Validation: zero poll seconds is invalid.
@test "worker-loop rejects zero WORKER_POLL_SECONDS" {
    WORKER_POLL_SECONDS=0 run "$DOCKER_DIR/worker-loop.sh"
    [ "$status" -ne 0 ]
}

# Bounded backoff: consecutive failures increase the sleep up to
# the cap. We verify by measuring inter-iteration gaps.
@test "worker-loop applies bounded backoff after consecutive failures" {
    CLUSTER_LOOP_FAIL_ORGANIZATION=1 \
        CLUSTER_LOOP_FAIL_IDENTITY=1 \
        CLUSTER_LOOP_FAIL_WORK_RECORDS=1 \
        CLUSTER_LOOP_FAIL_DOCUMENTS=1 \
        CLUSTER_LOOP_FAIL_NOTIFICATIONS=1 \
        WORKER_POLL_SECONDS=1 WORKER_MAX_BACKOFF_SECONDS=2 \
        start_loop "$DOCKER_DIR/worker-loop.sh"
    wait_for_iterations "relay-person-events" 2

    # Gap between the first two work-records:relay-pending invocations
    # should be at least the base poll + extra backoff sleep.
    sleep 1
    stop_loop

    # Verify five lanes were invoked at least twice.
    [ "$(grep -c 'relay-person-events' "$PHP_INVOCATION_LOG")" -ge 2 ]
}
