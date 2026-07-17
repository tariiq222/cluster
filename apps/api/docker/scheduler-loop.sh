#!/bin/sh
set -eu

readonly poll_seconds="${SCHEDULER_POLL_SECONDS:-60}"

case "$poll_seconds" in
  ''|*[!0-9]*)
    printf '%s\n' 'ERROR: SCHEDULER_POLL_SECONDS must be a positive integer.' >&2
    exit 2
    ;;
esac

trap 'exit 0' INT TERM

while :; do
  php artisan schedule:run --no-interaction
  touch /tmp/scheduler.ready
  sleep "$poll_seconds" &
  wait $!
done
