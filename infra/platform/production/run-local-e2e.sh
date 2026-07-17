#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/infra/platform/production/compose.yaml"
readonly WEB_DIR="$ROOT_DIR/apps/web"
readonly PROJECT="cluster-w11-bld-02-e2e-$$"
readonly PUBLIC_PORT="${W11_BLD_PUBLIC_PORT:-18080}"
readonly HEALTH_TIMEOUT="${W11_BLD_HEALTH_TIMEOUT_SECONDS:-150}"
readonly API_IMAGE="${W11_BLD_API_IMAGE:-cluster-api:w11-bld-02}"
readonly WEB_IMAGE="${W11_BLD_WEB_IMAGE:-cluster-web:w11-bld-02}"
readonly MYSQL_IMAGE="docker.io/library/mysql:8.4.6@sha256:869218921e61d6c3c89820955d63cca42971f0e3e6c1e2792247bbd944ebc6e9"
readonly VALKEY_IMAGE="docker.io/valkey/valkey:8.1.1@sha256:a19bebed6a91bd5e6e2106fef015f9602a3392deeb7c9ed47548378dcee3dfc2"
readonly APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
readonly DB_PASSWORD="$(openssl rand -hex 24)"
readonly DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
readonly VALKEY_PASSWORD="$(openssl rand -hex 24)"
readonly LOG_FILE="${TMPDIR:-/tmp}/cluster-w11-bld-02-e2e-$$.log"
readonly COMPOSE=(docker compose --project-name "$PROJECT" --file "$COMPOSE_FILE")

compose() {
  env \
    API_IMAGE="$API_IMAGE" \
    WEB_IMAGE="$WEB_IMAGE" \
    APP_ENV=testing \
    APP_KEY="$APP_KEY" \
    APP_URL="http://127.0.0.1:${PUBLIC_PORT}" \
    DB_PASSWORD="$DB_PASSWORD" \
    DB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
    VALKEY_PASSWORD="$VALKEY_PASSWORD" \
    PUBLIC_BIND_ADDRESS=127.0.0.1 \
    PUBLIC_PORT="$PUBLIC_PORT" \
    "${COMPOSE[@]}" "$@"
}

cleanup() {
  local status=$?
  trap - EXIT
  if ! compose down --volumes --remove-orphans >/dev/null 2>&1; then
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

assert_port_free() {
  if ! [[ "$PUBLIC_PORT" =~ ^[0-9]+$ ]] || (( PUBLIC_PORT < 1024 || PUBLIC_PORT > 65535 )); then
    printf 'ERROR: W11_BLD_PUBLIC_PORT must be between 1024 and 65535.\n' >&2
    return 1
  fi
  if lsof -nP -iTCP:"$PUBLIC_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    printf 'ERROR: localhost port %s is already in use.\n' "$PUBLIC_PORT" >&2
    return 1
  fi
}

require_images() {
  local image
  for image in "$API_IMAGE" "$WEB_IMAGE" "$MYSQL_IMAGE" "$VALKEY_IMAGE"; do
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
  while (( SECONDS < deadline )); do
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
  while (( SECONDS < deadline )); do
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

wait_http() {
  local deadline=$((SECONDS + HEALTH_TIMEOUT))
  while (( SECONDS < deadline )); do
    if curl --silent --show-error --fail --max-time 3 "http://127.0.0.1:${PUBLIC_PORT}/up" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  printf 'ERROR: production Web/API endpoint did not become ready.\n' >&2
  return 1
}

printf 'Starting prebuilt production images with the governed Compose bundle.\n'
assert_port_free
require_images
compose config --quiet
compose up --detach >"$LOG_FILE" 2>&1
wait_migration
for service in mysql valkey api worker scheduler web; do
  wait_healthy "$service"
done
wait_http

compose restart valkey >>"$LOG_FILE" 2>&1
wait_healthy valkey
compose restart worker >>"$LOG_FILE" 2>&1
wait_healthy worker

(
  cd "$WEB_DIR"
  W1_1_WEB_ORIGIN="http://127.0.0.1:${PUBLIC_PORT}" \
  PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/Library/Caches/cluster-playwright/1.61.1}" \
  ./node_modules/.bin/playwright test --config playwright.production.config.ts
) >>"$LOG_FILE" 2>&1

printf 'PASS: production Compose, migrations, healthchecks, restarts, Arabic RTL, English LTR, and facility isolation passed.\n'
