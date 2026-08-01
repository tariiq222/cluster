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
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then tail -n 120 "$LOG_FILE" >&2 || true; fi
  if [[ -d "$SESSION_FILES" ]]; then find "$SESSION_FILES" -mindepth 1 -maxdepth 1 -type f -delete; fi
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
  # The W1.3 journey exercises work-record endpoints, so work management must
  # be enabled even though apps/api/config/features.php defaults it off.
  CLUSTER_WORK_MANAGEMENT_ENABLED=true
)

(cd "$API_DIR" && env "${API_ENV[@]}" php artisan migrate:fresh --force && env "${API_ENV[@]}" php artisan db:seed --class=Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder --force) >>"$LOG_FILE" 2>&1
# The production binding gates every non-setup decision behind the authorization
# bootstrap lifecycle. The journey fixtures model an already-bootstrapped
# environment, so close the window exactly like the feature harness does.
(cd "$API_DIR" && env "${API_ENV[@]}" php artisan tinker --execute="DB::table('authorization_bootstrap')->update(['state' => 'complete', 'completed_by_user_id' => \\Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 'completed_at' => now(), 'lock_version' => 2, 'updated_at' => now()]);") >>"$LOG_FILE" 2>&1
# Cluster authority is required for role-capability attachment and cluster-wide
# administration (role-capability create validates grant authority at the
# default cluster scope). The seeder grants Account A at facility scope only,
# so add the cluster-scoped authorization-admin assignment exactly like the
# feature journey fixture (SecurityJourneyW13Test.php lines 139-154). This
# touches only the fresh temporary SQLite database.
(cd "$API_DIR" && env "${API_ENV[@]}" php artisan tinker --execute="\$clusterId = DB::table('clusters')->where('singleton_key', 1)->value('id'); \$roleId = DB::table('roles')->where('code', \\Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id'); if (is_string(\$clusterId) && is_string(\$roleId)) { DB::table('role_assignments')->insertOrIgnore(['id' => \\Illuminate\\Support\\Str::uuid7()->toString(), 'user_id' => \\Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 'role_id' => \$roleId, 'scope_type' => 'cluster', 'scope_id' => \$clusterId, 'start_at' => '2026-01-01 00:00:00.000', 'end_at' => null, 'status' => 'active', 'granted_by_user_id' => \\Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 'created_at' => now(), 'updated_at' => now()]); }") >>"$LOG_FILE" 2>&1
(
  cd "$API_DIR"
  exec env "${API_ENV[@]}" php artisan serve --host=127.0.0.1 --port="$API_PORT"
) >>"$LOG_FILE" 2>&1 &
API_PID=$!
wait_http "http://127.0.0.1:${API_PORT}/up"

cd "$WEB_DIR"
env W1_1_API_ORIGIN="http://127.0.0.1:${API_PORT}" W1_1_WEB_PORT="$WEB_PORT" \
    W1_3_ACCOUNT_A_USERNAME=w13-e2e-account-a W1_3_ACCOUNT_A_PASSWORD='North!River7Quartz2026' \
    W1_3_ACCOUNT_B_USERNAME=w13-e2e-account-b W1_3_ACCOUNT_B_PASSWORD='Cedar!Orbit8Harbor2026' \
  npm run test:e2e:local -- e2e/w1-3-authorization.spec.ts
