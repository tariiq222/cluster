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
readonly PROJECT="cluster-w1-1-smoke-$$"
readonly MYSQL_PORT="${W1_1_MYSQL_PORT:-$(pick_free_port)}"
readonly REDIS_PORT="${W1_1_REDIS_PORT:-$(pick_free_port "$MYSQL_PORT")}"
readonly API_PORT="${W1_1_API_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT")}"
readonly HEALTH_TIMEOUT="${W1_1_HEALTH_TIMEOUT_SECONDS:-90}"
readonly MYSQL_DATABASE="${W1_1_MYSQL_DATABASE:-cluster}"
readonly MYSQL_USER="${W1_1_MYSQL_USER:-cluster}"
readonly MYSQL_PASSWORD="${W1_1_MYSQL_PASSWORD:-local-dev-password}"
readonly MYSQL_ROOT_PASSWORD="${W1_1_MYSQL_ROOT_PASSWORD:-local-dev-root}"
readonly LOG_FILE="${TMPDIR:-/tmp}/cluster-w1-1-smoke-$$.log"
readonly COMPOSE=(docker compose --project-name "$PROJECT" --env-file "$ENV_FILE" --file "$COMPOSE_FILE")
API_PID=""
CROSS_A=""
CROSS_B=""
export W1_1_COMPOSE_PROJECT="$PROJECT" W1_1_MYSQL_PORT="$MYSQL_PORT" W1_1_REDIS_PORT="$REDIS_PORT" W1_1_MYSQL_DATABASE="$MYSQL_DATABASE" W1_1_MYSQL_USER="$MYSQL_USER" W1_1_MYSQL_PASSWORD="$MYSQL_PASSWORD" W1_1_MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD"

cleanup() {
  local status=$?
  trap - EXIT
  if [[ -n "$API_PID" ]]; then
    kill_tree "$API_PID"
    wait "$API_PID" >/dev/null 2>&1 || true
  fi
  if ! "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1; then
    [[ "$status" -ne 0 ]] || status=1
  fi
  if [[ -n $("${COMPOSE[@]}" ps -q 2>/dev/null || true) ]]; then
    [[ "$status" -ne 0 ]] || status=1
  fi
  rm -f "$CROSS_A" "$CROSS_B"
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then
    tail -n 40 "$LOG_FILE" >&2 || true
  fi
  rm -f "$LOG_FILE"
  exit "$status"
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

api_env=(
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
)

printf 'Starting bounded API/worker smoke resources.\n'
assert_port_free "$MYSQL_PORT"
assert_port_free "$REDIS_PORT"
assert_port_free "$API_PORT"
"${COMPOSE[@]}" up -d mysql redis >/dev/null
wait_healthy mysql
wait_healthy redis
"${COMPOSE[@]}" exec -T mysql mysqladmin ping -h 127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent >/dev/null
"${COMPOSE[@]}" exec -T redis redis-cli ping | grep -Fxq PONG

(
  cd "$API_DIR"
  env "${api_env[@]}" php artisan migrate:fresh --force >/dev/null
  exec env "${api_env[@]}" php artisan serve --host=127.0.0.1 --port="$API_PORT"
) >"$LOG_FILE" 2>&1 &
API_PID=$!
wait_tcp 127.0.0.1 "$API_PORT"

correlation_a='018f6f7d-0c00-7000-8000-000000000201'
correlation_b='018f6f7d-0c00-7000-8000-000000000202'
login_a="$(curl --silent --show-error --fail --max-time 10 -H "X-Correlation-ID: $correlation_a" -H 'Content-Type: application/json' -d '{"username":"fixture-account-a","password":"fixture-password-a"}' "http://127.0.0.1:${API_PORT}/api/v1/auth/login")"
login_b="$(curl --silent --show-error --fail --max-time 10 -H "X-Correlation-ID: $correlation_b" -H 'Content-Type: application/json' -d '{"username":"fixture-account-b","password":"fixture-password-b"}' "http://127.0.0.1:${API_PORT}/api/v1/auth/login")"
token_a="$(jq -er '.data.access_token' <<<"$login_a")"
token_b="$(jq -er '.data.access_token' <<<"$login_b")"

create_a="$(curl --silent --show-error --fail --max-time 10 -H "Authorization: Bearer $token_a" -H "X-Correlation-ID: $correlation_a" -H 'Idempotency-Key: smoke-a-001' -H 'Content-Type: application/json' -d '{"work_definition_code":"request","title":"smoke-a","description":"وصف smoke أ"}' "http://127.0.0.1:${API_PORT}/api/v1/work-records")"
create_b="$(curl --silent --show-error --fail --max-time 10 -H "Authorization: Bearer $token_b" -H "X-Correlation-ID: $correlation_b" -H 'Idempotency-Key: smoke-b-001' -H 'Content-Type: application/json' -d '{"work_definition_code":"request","title":"smoke-b","description":"وصف smoke ب"}' "http://127.0.0.1:${API_PORT}/api/v1/work-records")"
record_a="$(jq -er '.data.id' <<<"$create_a")"
record_b="$(jq -er '.data.id' <<<"$create_b")"
[[ "$record_a" != "$record_b" ]]

list_a="$(curl --silent --show-error --fail --max-time 10 -H "Authorization: Bearer $token_a" -H "X-Correlation-ID: $correlation_a" "http://127.0.0.1:${API_PORT}/api/v1/work-records?limit=100")"
list_b="$(curl --silent --show-error --fail --max-time 10 -H "Authorization: Bearer $token_b" -H "X-Correlation-ID: $correlation_b" "http://127.0.0.1:${API_PORT}/api/v1/work-records?limit=100")"
jq -e --arg id "$record_a" '(.items | length) == 1 and .items[0].id == $id' <<<"$list_a" >/dev/null
jq -e --arg id "$record_b" '(.items | length) == 1 and .items[0].id == $id' <<<"$list_b" >/dev/null
! jq -e --arg id "$record_b" '.items[]? | select(.id == $id)' <<<"$list_a" >/dev/null
! jq -e --arg id "$record_a" '.items[]? | select(.id == $id)' <<<"$list_b" >/dev/null

CROSS_A="${TMPDIR:-/tmp}/cluster-w1-1-cross-a-$$.json"
CROSS_B="${TMPDIR:-/tmp}/cluster-w1-1-cross-b-$$.json"
cross_a_status="$(curl --silent --show-error --max-time 10 -o "$CROSS_A" -w '%{http_code}' -H "Authorization: Bearer $token_a" -H "X-Correlation-ID: $correlation_a" "http://127.0.0.1:${API_PORT}/api/v1/work-records/${record_b}")"
cross_b_status="$(curl --silent --show-error --max-time 10 -o "$CROSS_B" -w '%{http_code}' -H "Authorization: Bearer $token_b" -H "X-Correlation-ID: $correlation_b" "http://127.0.0.1:${API_PORT}/api/v1/work-records/${record_a}")"
[[ "$cross_a_status" == 404 && "$cross_b_status" == 404 ]]
cmp -s "$CROSS_A" "$CROSS_B"
jq -e 'keys == ["detail","status","title","type"] and .status == 404' "$CROSS_A" >/dev/null
! grep -Eiq 'smoke-|facility|owner|trace|authorization' "$CROSS_A"
rm -f "$CROSS_A" "$CROSS_B"
CROSS_A=""
CROSS_B=""

(
  cd "$API_DIR"
  env "${api_env[@]}" php artisan work-records:relay-pending --once >/dev/null
  env "${api_env[@]}" php artisan notifications:consume-work-record-submitted --once --consumer=smoke-worker >/dev/null
)
notifications_a="$(curl --silent --show-error --fail --max-time 10 -H "Authorization: Bearer $token_a" -H "X-Correlation-ID: $correlation_a" "http://127.0.0.1:${API_PORT}/api/v1/notifications")"
notifications_b="$(curl --silent --show-error --fail --max-time 10 -H "Authorization: Bearer $token_b" -H "X-Correlation-ID: $correlation_b" "http://127.0.0.1:${API_PORT}/api/v1/notifications")"
jq -e --arg id "$record_a" '(.items | length) == 1 and .items[0].source.record_id == $id' <<<"$notifications_a" >/dev/null
jq -e --arg id "$record_b" '(.items | length) == 1 and .items[0].source.record_id == $id' <<<"$notifications_b" >/dev/null
"${COMPOSE[@]}" exec -T redis redis-cli XPENDING platform.work-record.submitted.v1 notifications.work-record-submitted.v1 | head -n 1 | grep -Fxq 0

(
  cd "$API_DIR"
  env "${api_env[@]}" php vendor/bin/phpunit -c phpunit.mysql.xml --filter=WalkingSkeletonMySqlE2ETest >/dev/null
  env "${api_env[@]}" php vendor/bin/phpunit -c phpunit.mysql.xml Modules/Organization/Tests/OrganizationCoreHttpAdapterTest.php >/dev/null
  env "${api_env[@]}" php artisan migrate:rollback --force >/dev/null
)
printf 'PASS: API/worker smoke proved MySQL Organization core and rollback, symmetric isolation, relay, Inbox/effect, replay, and DLQ coverage.\n'
