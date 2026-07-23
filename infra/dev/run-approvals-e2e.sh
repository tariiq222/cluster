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
readonly TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cluster-approvals-e2e.XXXXXX")"
readonly DATABASE="$TEMP_DIR/database.sqlite"
readonly API_PORT="${APPROVALS_API_PORT:-$(pick_free_port)}"
readonly WEB_PORT="${APPROVALS_WEB_PORT:-$(pick_free_port)}"
readonly LOG_FILE="$TEMP_DIR/runtime.log"
readonly SESSION_FILES="$TEMP_DIR/sessions"
readonly APP_KEY='base64:K6z5AWr8AuJy2DLj2Ti8Q0l4iNdaWv7IB9AUrBv7mN0='
API_PID=''

cleanup() {
  local status=$?
  trap - EXIT
  if [[ -n "$API_PID" ]]; then
    kill -TERM "$API_PID" >/dev/null 2>&1 || true
    wait "$API_PID" >/dev/null 2>&1 || true
  fi
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then tail -n 160 "$LOG_FILE" >&2 || true; fi
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
mkdir -p "$SESSION_FILES"
readonly API_ENV=(
  APP_ENV=testing APP_KEY="$APP_KEY"
  DB_CONNECTION=sqlite DB_DATABASE="$DATABASE"
  SESSION_DRIVER=file SESSION_FILES="$SESSION_FILES" CACHE_STORE=array
  IDENTITY_SESSION_SECURE=false
)

(cd "$API_DIR" && env "${API_ENV[@]}" php artisan migrate:fresh --force && env "${API_ENV[@]}" php artisan db:seed --class=Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder --force) >>"$LOG_FILE" 2>&1
# The decision engine is gated behind the authorization bootstrap; the journey
# fixtures model an already-bootstrapped cluster, same as run-w1-3-e2e.sh.
(cd "$API_DIR" && env "${API_ENV[@]}" php artisan tinker --execute="DB::table('authorization_bootstrap')->update(['state' => 'complete', 'completed_by_user_id' => \\Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 'completed_at' => now(), 'lock_version' => 2, 'updated_at' => now()]);") >>"$LOG_FILE" 2>&1
(
  cd "$API_DIR"
  exec env "${API_ENV[@]}" php artisan serve --host=127.0.0.1 --port="$API_PORT"
) >>"$LOG_FILE" 2>&1 &
API_PID=$!
wait_http "http://127.0.0.1:${API_PORT}/up"

cd "$WEB_DIR"
env W1_1_API_ORIGIN="http://127.0.0.1:${API_PORT}" W1_1_WEB_PORT="$WEB_PORT" \
  npm run test:e2e:local -- e2e/shell.spec.ts e2e/capability-navigation.spec.ts e2e/personal-work.spec.ts e2e/dashboard-navigation-browser-qa.spec.ts e2e/workflow-authoring.spec.ts e2e/requests-and-approvals.spec.ts e2e/workflow-details.spec.ts
