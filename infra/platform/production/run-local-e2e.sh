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

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/infra/platform/production/compose.yaml"
readonly TEST_COMPOSE_FILE="$ROOT_DIR/infra/platform/production/compose.test.yaml"
readonly WEB_DIR="$ROOT_DIR/apps/web"
readonly PROJECT="cluster-w11-bld-02-e2e-$$"
readonly TEST_MYSQL_PORT="${VPS_TEST_MYSQL_PORT:-$(pick_free_port)}"
readonly TEST_REDIS_PORT="${VPS_TEST_REDIS_PORT:-$(pick_free_port "$TEST_MYSQL_PORT")}"
readonly HTTP_PORT="${VPS_TEST_HTTP_PORT:-$(pick_free_port "$TEST_MYSQL_PORT" "$TEST_REDIS_PORT")}"
readonly HTTPS_PORT="${VPS_TEST_HTTPS_PORT:-$(pick_free_port "$TEST_MYSQL_PORT" "$TEST_REDIS_PORT" "$HTTP_PORT")}"
readonly HEALTH_TIMEOUT="${VPS_TEST_HEALTH_TIMEOUT_SECONDS:-150}"
readonly API_IMAGE="${API_IMAGE:-cluster-api:local}"
readonly WEB_IMAGE="${WEB_IMAGE:-cluster-web:local}"
readonly APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
readonly DB_PASSWORD="$(openssl rand -hex 24)"
readonly DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
readonly REDIS_PASSWORD="$(openssl rand -hex 24)"
readonly LOG_FILE="${TMPDIR:-/tmp}/cluster-w11-bld-02-e2e-$$.log"
readonly COMPOSE=(docker compose --project-name "$PROJECT" --file "$COMPOSE_FILE" --file "$TEST_COMPOSE_FILE")

compose() {
  env \
    API_IMAGE="$API_IMAGE" \
    WEB_IMAGE="$WEB_IMAGE" \
    APP_ENV=testing \
    APP_KEY="$APP_KEY" \
    APP_DOMAIN=localhost \
    DB_HOST=host.docker.internal \
    DB_DATABASE=cluster \
    DB_USERNAME=cluster \
    DB_PASSWORD="$DB_PASSWORD" \
    DB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
    REDIS_HOST=host.docker.internal \
    REDIS_PASSWORD="$REDIS_PASSWORD" \
    AUDIT_INTEGRITY_KEYS="v1:testing-only-32-byte-key-material" \
    AUDIT_INTEGRITY_KEY_VERSION=v1 \
    TEST_MYSQL_PORT="$TEST_MYSQL_PORT" \
    TEST_REDIS_PORT="$TEST_REDIS_PORT" \
    HTTP_PORT="$HTTP_PORT" \
    HTTPS_PORT="$HTTPS_PORT" \
    "${COMPOSE[@]}" "$@"
}

cleanup() {
  local status=$?
  trap - EXIT
  if ! compose down --volumes --remove-orphans >/dev/null 2>&1; then
    [[ "$status" -ne 0 ]] || status=1
  fi
  if [[ -n "$(compose ps -q 2>/dev/null || true)" ]]; then
    [[ "$status" -ne 0 ]] || status=1
  fi
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then
    tail -n 120 "$LOG_FILE" >&2 || true
  fi
  rm -f "$LOG_FILE"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

assert_ports_free() {
  local port
  for port in "$TEST_MYSQL_PORT" "$TEST_REDIS_PORT" "$HTTP_PORT" "$HTTPS_PORT"; do
    if ! [[ "$port" =~ ^[0-9]+$ ]] || ((port < 1024 || port > 65535)); then
      printf 'ERROR: invalid local test port: %s\n' "$port" >&2
      return 1
    fi
    if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
      printf 'ERROR: localhost port %s is already in use.\n' "$port" >&2
      return 1
    fi
  done
}

require_images() {
  local image
  for image in "$API_IMAGE" "$WEB_IMAGE"; do
    if ! docker image inspect "$image" >/dev/null 2>&1; then
      printf 'ERROR: required prebuilt image is unavailable locally: %s\n' "$image" >&2
      return 1
    fi
  done
}

wait_healthy() {
  local service="$1"
  local deadline=$((SECONDS + HEALTH_TIMEOUT))
  local container_id=""
  while ((SECONDS < deadline)); do
    container_id="$(compose ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]] && [[ "$(docker inspect --format '{{.State.Health.Status}}' "$container_id" 2>/dev/null || true)" == healthy ]]; then
      return 0
    fi
    sleep 1
  done
  printf 'ERROR: %s did not become healthy within %ss.\n' "$service" "$HEALTH_TIMEOUT" >&2
  return 1
}

wait_migration() {
  local deadline=$((SECONDS + HEALTH_TIMEOUT))
  local container_id=""
  local status=""
  local exit_code=""
  while ((SECONDS < deadline)); do
    container_id="$(compose ps -a -q migrate 2>/dev/null || true)"
    if [[ -n "$container_id" ]]; then
      status="$(docker inspect --format '{{.State.Status}}' "$container_id")"
      exit_code="$(docker inspect --format '{{.State.ExitCode}}' "$container_id")"
      if [[ "$status" == exited ]]; then
        [[ "$exit_code" == 0 ]]
        return
      fi
    fi
    sleep 1
  done
  printf 'ERROR: migration did not complete within %ss.\n' "$HEALTH_TIMEOUT" >&2
  return 1
}

wait_https() {
  local deadline=$((SECONDS + HEALTH_TIMEOUT))
  while ((SECONDS < deadline)); do
    if curl --insecure --silent --show-error --fail --max-time 3 "https://localhost:${HTTPS_PORT}/up" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  printf 'ERROR: Caddy HTTPS endpoint did not become ready.\n' >&2
  return 1
}

printf 'Starting the direct-VPS bundle with external-state and Caddy test wiring.\n'
assert_ports_free
require_images
compose config --quiet
compose pull caddy mysql redis >"$LOG_FILE" 2>&1
compose up --detach mysql redis migrate api worker web caddy >>"$LOG_FILE" 2>&1
wait_migration
for service in mysql redis api worker web caddy; do
  wait_healthy "$service"
done
wait_https

compose restart redis >>"$LOG_FILE" 2>&1
wait_healthy redis
compose restart worker >>"$LOG_FILE" 2>&1
wait_healthy worker

(
  cd "$WEB_DIR"
  W1_1_WEB_ORIGIN="https://localhost:${HTTPS_PORT}" \
  W1_1_ALLOW_SELF_SIGNED=1 \
  PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/Library/Caches/cluster-playwright/1.61.1}" \
  ./node_modules/.bin/playwright test --config playwright.production.config.ts e2e/login.spec.ts e2e/walking-skeleton.spec.ts
) >>"$LOG_FILE" 2>&1

printf 'PASS: Caddy HTTPS, host-gateway MySQL/Redis, migrations, worker restart, RTL/LTR, and facility isolation passed.\n'
