#!/usr/bin/env bash
set -euo pipefail

pick_free_port() {
  local excluded=" $* "
  local candidate
  while true; do
    candidate="$(python3 - <<'PY'
import socket

with socket.socket() as listener:
    listener.bind(("127.0.0.1", 0))
    print(listener.getsockname()[1])
PY
)"
    if [[ "$excluded" != *" $candidate "* ]]; then
      printf '%s\n' "$candidate"
      return
    fi
  done
}

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/infra/dev/compose.yaml"
readonly ENV_FILE="$ROOT_DIR/infra/dev/.env.example"
readonly API_DIR="$ROOT_DIR/apps/api"
readonly WEB_DIR="$ROOT_DIR/apps/web"
readonly PROJECT="cluster-w1-1-e2e-$$"
readonly MYSQL_PORT="${W1_1_MYSQL_PORT:-$(pick_free_port)}"
readonly REDIS_PORT="${W1_1_REDIS_PORT:-$(pick_free_port "$MYSQL_PORT")}"
readonly API_PORT="${W1_1_API_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT")}"
readonly WEB_PORT="${W1_1_WEB_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT" "$API_PORT")}"
readonly HEALTH_TIMEOUT="${W1_1_HEALTH_TIMEOUT_SECONDS:-90}"
readonly MYSQL_DATABASE="${W1_1_MYSQL_DATABASE:-cluster}"
readonly MYSQL_USER="${W1_1_MYSQL_USER:-cluster}"
readonly MYSQL_PASSWORD="${W1_1_MYSQL_PASSWORD:-local-dev-password}"
readonly MYSQL_ROOT_PASSWORD="${W1_1_MYSQL_ROOT_PASSWORD:-local-dev-root}"
readonly LOG_FILE="${TMPDIR:-/tmp}/cluster-w1-1-e2e-$$.log"
readonly COMPOSE=(docker compose --project-name "$PROJECT" --env-file "$ENV_FILE" --file "$COMPOSE_FILE")
readonly PLAYWRIGHT_GREP="${W1_1_PLAYWRIGHT_GREP:-}"
# With work_management disabled (the default), no work-record submissions
# flow, so the outbox relay/notification coordinator has nothing to drain;
# skip it. When the feature is re-enabled the coordinator drains 2 batches
# again unless explicitly overridden.
if [[ "${CLUSTER_WORK_MANAGEMENT_ENABLED:-false}" == "true" ]]; then
  readonly COORDINATOR_BATCHES="${W1_1_COORDINATOR_BATCHES:-2}"
else
  readonly COORDINATOR_BATCHES="${W1_1_COORDINATOR_BATCHES:-0}"
fi
readonly COORDINATOR_TARGET="${W1_1_COORDINATOR_TARGET:-$((COORDINATOR_BATCHES * 2))}"
if ! [[ "$COORDINATOR_BATCHES" =~ ^[0-9]+$ ]]; then
  printf 'ERROR: W1_1_COORDINATOR_BATCHES must be a non-negative integer.\n' >&2
  exit 2
fi
PLAYWRIGHT_ARGS=(
  e2e/walking-skeleton.spec.ts
  e2e/login.spec.ts
  e2e/shell.spec.ts
)
if [[ -n "$PLAYWRIGHT_GREP" ]]; then
  PLAYWRIGHT_ARGS+=(--grep "$PLAYWRIGHT_GREP")
fi
API_PID=""
VITE_PID=""
COORDINATOR_PID=""
export W1_1_COMPOSE_PROJECT="$PROJECT" W1_1_MYSQL_PORT="$MYSQL_PORT" W1_1_REDIS_PORT="$REDIS_PORT" W1_1_MYSQL_DATABASE="$MYSQL_DATABASE" W1_1_MYSQL_USER="$MYSQL_USER" W1_1_MYSQL_PASSWORD="$MYSQL_PASSWORD" W1_1_MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD"
# The shared compose file also declares the W1.2 MinIO/ClamAV services, so
# docker compose interpolates their env even when this runner never starts
# them. These placeholders are unused by the W1.1 journey.
export W1_2_MINIO_ROOT_USER="${W1_2_MINIO_ROOT_USER:-w1-1-e2e-minio}" W1_2_MINIO_ROOT_PASSWORD="${W1_2_MINIO_ROOT_PASSWORD:-w1-1-e2e-minio-secret}" W1_2_MINIO_API_PORT="${W1_2_MINIO_API_PORT:-0}" W1_2_MINIO_CONSOLE_PORT="${W1_2_MINIO_CONSOLE_PORT:-0}" W1_2_CLAMAV_PORT="${W1_2_CLAMAV_PORT:-0}"

cleanup() {
  local status=$?
  trap - EXIT
  for pid in "$COORDINATOR_PID" "$VITE_PID" "$API_PID"; do
    if [[ -n "$pid" ]]; then
      kill_tree "$pid"
      wait "$pid" >/dev/null 2>&1 || true
    fi
  done
  if ! "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1; then
    [[ "$status" -ne 0 ]] || status=1
  fi
  if [[ -n $("${COMPOSE[@]}" ps -q 2>/dev/null || true) ]]; then
    [[ "$status" -ne 0 ]] || status=1
  fi
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then
    tail -n 80 "$LOG_FILE" >&2 || true
  fi
  rm -f "$LOG_FILE"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

wait_healthy() {
  local service="$1"
  local deadline=$((SECONDS + HEALTH_TIMEOUT))
  local container_id=""
  while (( SECONDS < deadline )); do
    container_id="$("${COMPOSE[@]}" ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]] && [[ "$(docker inspect --format '{{.State.Health.Status}}' "$container_id" 2>/dev/null || true)" == "healthy" ]]; then
      return 0
    fi
    sleep 1
  done
  printf 'ERROR: %s did not become healthy within %ss.\n' "$service" "$HEALTH_TIMEOUT" >&2
  return 1
}

wait_http() {
  local url="$1"
  local deadline=$((SECONDS + HEALTH_TIMEOUT))
  while (( SECONDS < deadline )); do
    if curl --silent --show-error --fail --max-time 3 "$url" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  printf 'ERROR: HTTP readiness probe failed for %s.\n' "$url" >&2
  return 1
}

wait_tcp() {
  local host="$1"
  local port="$2"
  local deadline=$((SECONDS + HEALTH_TIMEOUT))
  while (( SECONDS < deadline )); do
    if nc -z "$host" "$port" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  printf 'ERROR: TCP readiness probe failed for %s:%s.\n' "$host" "$port" >&2
  return 1
}

assert_port_free() {
  local port="$1"
  if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
    printf 'ERROR: localhost port %s is already in use.\n' "$port" >&2
    return 1
  fi
}

kill_tree() {
  local pid="$1"
  local child
  for child in $(pgrep -P "$pid" 2>/dev/null || true); do
    kill_tree "$child"
  done
  kill -TERM "$pid" >/dev/null 2>&1 || true
}

readonly API_ENV=(
  APP_ENV=local
  APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT="$MYSQL_PORT"
  DB_DATABASE="$MYSQL_DATABASE"
  DB_USERNAME="$MYSQL_USER"
  DB_PASSWORD="$MYSQL_PASSWORD"
  REDIS_CLIENT=predis
  REDIS_HOST=127.0.0.1
  REDIS_PORT="$REDIS_PORT"
  SESSION_DRIVER=array
  # The maintenance middleware caches the active window per request; without
  # an explicit store the fixture falls back to the database cache store and
  # 500s on every POST because migrate:fresh never creates the cache table.
  CACHE_STORE=array
  IDENTITY_SESSION_SECURE=false
  AUDIT_INTEGRITY_KEYS=w1-1-e2e:test-w1-1-e2e-audit-integrity-key-32-bytes-minimum-AAAA
  AUDIT_INTEGRITY_KEY_VERSION=w1-1-e2e
  OUTBOX_RELAY_BATCH_SIZE=2
)

db_count() {
  local table="$1"
  (
    cd "$API_DIR"
    env "${API_ENV[@]}" php artisan tinker --execute="echo DB::table('${table}')->count();" 2>/dev/null | tail -n 1
  )
}

pending_outbox_count() {
  (
    cd "$API_DIR"
    env "${API_ENV[@]}" php artisan tinker --execute="echo DB::table('outbox_events')->whereNull('published_at')->where('event_type', 'com.cluster.workrecord.submitted.v1')->count();" 2>/dev/null | tail -n 1
  )
}

printf 'Starting full local MySQL/Redis/API/Vite/browser lifecycle.\n'
assert_port_free "$MYSQL_PORT"
assert_port_free "$REDIS_PORT"
assert_port_free "$API_PORT"
assert_port_free "$WEB_PORT"
"${COMPOSE[@]}" up -d mysql redis >/dev/null
wait_healthy mysql
wait_healthy redis
"${COMPOSE[@]}" exec -T mysql mysqladmin ping -h 127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent >/dev/null
"${COMPOSE[@]}" exec -T redis redis-cli ping | grep -Fxq PONG
(
  cd "$API_DIR"
  env "${API_ENV[@]}" php artisan migrate:fresh --force >/dev/null
  env "${API_ENV[@]}" php artisan db:seed --class=Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder --force >/dev/null
  env "${API_ENV[@]}" php artisan tinker --execute="DB::table('authorization_bootstrap')->update(['state' => 'complete', 'completed_by_user_id' => \\Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 'completed_at' => now(), 'lock_version' => 2, 'updated_at' => now()]);" >/dev/null
  exec env "${API_ENV[@]}" php artisan serve --host=127.0.0.1 --port="$API_PORT"
) >>"$LOG_FILE" 2>&1 &
API_PID=$!
wait_tcp 127.0.0.1 "$API_PORT"


(
  target=$COORDINATOR_TARGET
  deadline=$((SECONDS + HEALTH_TIMEOUT * 2 * COORDINATOR_BATCHES))
  while (( SECONDS < deadline )); do
    pending="$(pending_outbox_count || true)"
    notifications="$(db_count notifications || true)"

    if [[ "$notifications" =~ ^[0-9]+$ ]] && (( notifications >= target )) \
      && [[ "$pending" =~ ^[0-9]+$ ]] && (( pending == 0 )); then

      exit 0
    fi
    if [[ "$pending" =~ ^[0-9]+$ ]] && (( pending >= 2 )); then

      (
        cd "$API_DIR"
        env "${API_ENV[@]}" php artisan work-records:relay-pending --once
        env "${API_ENV[@]}" php artisan notifications:consume-work-record-submitted --once --consumer=e2e-coordinator
      ) >/dev/null 2>&1 || { echo "[coordinator] relay/consumer FAILED" >&2; exit 1; }
      effect_deadline=$((SECONDS + HEALTH_TIMEOUT))
      while (( SECONDS < effect_deadline )); do
        pending="$(pending_outbox_count || true)"
        notifications="$(db_count notifications || true)"
        if [[ "$notifications" =~ ^[0-9]+$ ]] && (( notifications >= target )) \
          && [[ "$pending" =~ ^[0-9]+$ ]] && (( pending == 0 )); then
          exit 0
        fi
        sleep 1
      done
    fi
    sleep 1
  done
  pending_final="$(pending_outbox_count || true)"
  notifications_final="$(db_count notifications || true)"
  if [[ "$pending_final" =~ ^[0-9]+$ ]] && (( pending_final == 0 )) \
    && [[ "$notifications_final" =~ ^[0-9]+$ ]] && (( notifications_final >= target )); then
    exit 0
  fi
  printf 'ERROR: coordinator timed out before all batches (pending=%s, notifications=%s, expected>=%s).\n' "$pending_final" "$notifications_final" "$target" >&2
  exit 1
) >>"$LOG_FILE" 2>&1 &
COORDINATOR_PID=$!

if ! (
  cd "$WEB_DIR"
  W1_1_API_ORIGIN="http://127.0.0.1:${API_PORT}" \
  W1_1_WEB_PORT="$WEB_PORT" \
  PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/Library/Caches/cluster-playwright/1.61.1}" \
  ./node_modules/.bin/playwright test "${PLAYWRIGHT_ARGS[@]}"
) >>"$LOG_FILE" 2>&1; then
  printf 'ERROR: Playwright walking-skeleton suite failed; see %s for diagnostics.\n' "$LOG_FILE" >&2
  # CI has no interactive access to the runner log; surface the failure
  # context inline so architecture-closure failures are diagnosable from
  # the job output alone.
  if [[ "${CI:-false}" == "true" ]]; then
    printf '%s\n' '--- e2e runner log (tail 200) ---' >&2
    tail -n 200 "$LOG_FILE" >&2 || true
    printf '%s\n' '--- end e2e runner log ---' >&2
    # The API's real exceptions land in the Laravel log, not artisan serve's
    # stdout; surface them too.
    printf '%s\n' '--- laravel.log (tail 120) ---' >&2
    tail -n 120 "$API_DIR/storage/logs/laravel.log" >&2 || true
    printf '%s\n' '--- end laravel.log ---' >&2
  fi
  exit 1
fi

if ! wait "$COORDINATOR_PID"; then
  printf 'ERROR: coordinator failed after browser execution.\n' >&2
  exit 1
fi
COORDINATOR_PID=""
printf 'PASS: Arabic RTL and English LTR browser journeys completed through MySQL, Outbox, Redis, Inbox, and notifications.\n'
