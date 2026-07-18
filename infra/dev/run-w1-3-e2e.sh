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
readonly TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cluster-w13-e2e.XXXXXX")"
readonly DATABASE="$TEMP_DIR/database.sqlite"
readonly API_PORT="${W1_3_API_PORT:-$(pick_free_port)}"
readonly WEB_PORT="${W1_3_WEB_PORT:-$(pick_free_port)}"
readonly LOG_FILE="$TEMP_DIR/runtime.log"
readonly APP_KEY='base64:K6z5AWr8AuJy2DLj2Ti8Q0l4iNdaWv7IB9AUrBv7mN0='
API_PID=''

cleanup() {
  local status=$?
  trap - EXIT
  if [[ -n "$API_PID" ]]; then
    kill -TERM "$API_PID" >/dev/null 2>&1 || true
    wait "$API_PID" >/dev/null 2>&1 || true
  fi
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then tail -n 120 "$LOG_FILE" >&2 || true; fi
  rm -rf "$TEMP_DIR"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

wait_http() {
  local url="$1" deadline=$((SECONDS + 30))
  while (( SECONDS < deadline )); do
    curl --silent --fail --max-time 2 "$url" >/dev/null 2>&1 && return
    sleep 1
  done
  printf 'ERROR: readiness probe failed for %s\n' "$url" >&2
  return 1
}

touch "$DATABASE"
readonly API_ENV=(
  APP_ENV=testing APP_KEY="$APP_KEY"
  DB_CONNECTION=sqlite DB_DATABASE="$DATABASE"
  SESSION_DRIVER=array CACHE_STORE=array
)

(cd "$API_DIR" && env "${API_ENV[@]}" php artisan migrate:fresh --force) >>"$LOG_FILE" 2>&1
(
  cd "$API_DIR"
  exec env "${API_ENV[@]}" php artisan serve --host=127.0.0.1 --port="$API_PORT"
) >>"$LOG_FILE" 2>&1 &
API_PID=$!
wait_http "http://127.0.0.1:${API_PORT}/up"

cd "$WEB_DIR"
env W1_1_API_ORIGIN="http://127.0.0.1:${API_PORT}" W1_1_WEB_PORT="$WEB_PORT" \
  npm run test:e2e:local -- e2e/w1-3-authorization.spec.ts
