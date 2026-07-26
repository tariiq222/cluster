#!/bin/sh
set -eu

readonly consumer="${NOTIFICATIONS_CONSUMER_NAME:-production-worker}"
readonly poll_seconds="${WORKER_POLL_SECONDS:-2}"

case "$poll_seconds" in
  ''|*[!0-9]*)
    printf '%s\n' 'ERROR: WORKER_POLL_SECONDS must be a positive integer.' >&2
    exit 2
    ;;
esac

trap 'exit 0' INT TERM

while :; do
  php artisan organization:relay-person-events --once --no-interaction
  php artisan identity:consume-person-events --once --consumer="$consumer" --no-interaction
  php artisan work-records:relay-pending --once --no-interaction
  php artisan documents:relay-events --once --no-interaction
  php artisan notifications:consume-work-record-submitted --once --consumer="$consumer" --no-interaction
  touch /tmp/worker.ready
  sleep "$poll_seconds" &
  wait $!
done
