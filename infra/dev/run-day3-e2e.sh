#!/usr/bin/env bash
set -euo pipefail

pick_free_port() {
  python3 - <<'PY'
import socket
with socket.socket() as listener:
    listener.bind(('127.0.0.1', 0))
    print(listener.getsockname()[1])
PY
}

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly API_DIR="$ROOT_DIR/apps/api"
readonly WEB_DIR="$ROOT_DIR/apps/web"
readonly TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cluster-day3-e2e.XXXXXX")"
readonly DATABASE="$TEMP_DIR/database.sqlite"
readonly API_PORT="${DAY3_API_PORT:-$(pick_free_port)}"
readonly WEB_PORT="${DAY3_WEB_PORT:-$(pick_free_port)}"
readonly LOG_FILE="$TEMP_DIR/runtime.log"
readonly APP_KEY='base64:K6z5AWr8AuJy2DLj2Ti8Q0l4iNdaWv7IB9AUrBv7mN0='
API_PID=''

cleanup() {
  local status=$?
  trap - EXIT
  if [[ -n "$API_PID" ]]; then kill -TERM "$API_PID" >/dev/null 2>&1 || true; wait "$API_PID" >/dev/null 2>&1 || true; fi
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then tail -n 160 "$LOG_FILE" >&2 || true; fi
  rm -rf "$TEMP_DIR"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

touch "$DATABASE"
readonly API_ENV=(APP_ENV=testing APP_KEY="$APP_KEY" DB_CONNECTION=sqlite DB_DATABASE="$DATABASE" SESSION_DRIVER=array CACHE_STORE=array)
(cd "$API_DIR" && env "${API_ENV[@]}" php artisan migrate:fresh --force && env "${API_ENV[@]}" php artisan db:seed --class=Database\\Seeders\\Day3RepresentativeSeeder --force && env "${API_ENV[@]}" php artisan db:seed --class=Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder --force) >>"$LOG_FILE" 2>&1
(cd "$API_DIR" && exec env "${API_ENV[@]}" php artisan serve --host=127.0.0.1 --port="$API_PORT") >>"$LOG_FILE" 2>&1 &
API_PID=$!
for _ in {1..30}; do curl --silent --fail --max-time 2 "http://127.0.0.1:${API_PORT}/up" >/dev/null 2>&1 && break; sleep 1; done
curl --silent --fail --max-time 2 "http://127.0.0.1:${API_PORT}/up" >/dev/null
cd "$WEB_DIR"
env W1_1_API_ORIGIN="http://127.0.0.1:${API_PORT}" W1_1_WEB_PORT="$WEB_PORT" npm run test:e2e:local -- e2e/login.spec.ts e2e/walking-skeleton.spec.ts
env W1_1_API_ORIGIN="http://127.0.0.1:${API_PORT}" W1_1_WEB_PORT="$WEB_PORT" npm run test:e2e:local -- e2e/day2-workflow.spec.ts e2e/day3-r1.spec.ts e2e/r1-screens.spec.ts
