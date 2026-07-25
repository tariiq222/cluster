#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly API_DIR="$ROOT_DIR/apps/api"
readonly COMPOSE_FILE="$ROOT_DIR/infra/dev/compose.yaml"
readonly ENV_FILE="$ROOT_DIR/infra/dev/.env.example"
readonly PROJECT="cluster-mysql-integration-$$"
readonly MYSQL_DATABASE="${MYSQL_INTEGRATION_DATABASE:-cluster_w12_test}"
readonly MYSQL_USER="${MYSQL_INTEGRATION_USER:-cluster}"
readonly MYSQL_PASSWORD="${MYSQL_INTEGRATION_PASSWORD:-local-dev-password}"
readonly MYSQL_ROOT_PASSWORD="${MYSQL_INTEGRATION_ROOT_PASSWORD:-local-dev-root}"
readonly HEALTH_TIMEOUT_SECONDS="${MYSQL_INTEGRATION_HEALTH_TIMEOUT_SECONDS:-90}"
readonly TEST_TIMEOUT_SECONDS="${MYSQL_INTEGRATION_TEST_TIMEOUT_SECONDS:-300}"
readonly COMPOSE=(docker compose --project-name "$PROJECT" --env-file "$ENV_FILE" --file "$COMPOSE_FILE")

COMPOSE_STARTED=0
TEST_PID=""

skip_prerequisite() {
  printf 'SKIP: verify-mysql-integration prereq missing: %s.\n' "$1"
  exit 0
}

validate_timeout() {
  local name="$1"
  local value="$2"

  if ! [[ "$value" =~ ^[1-9][0-9]*$ ]]; then
    printf 'ERROR: %s must be a positive integer, got %s; failing the gate.\n' "$name" "$value" >&2
    exit 2
  fi
}

cleanup() {
  local status=$?
  trap - EXIT

  if [[ -n "$TEST_PID" ]]; then
    kill -TERM "$TEST_PID" >/dev/null 2>&1 || true
    wait "$TEST_PID" >/dev/null 2>&1 || true
  fi

  if (( COMPOSE_STARTED )); then
    if ! "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1; then
      [[ "$status" -ne 0 ]] || status=1
    fi
    if [[ "$status" -ne 0 ]]; then
      "${COMPOSE[@]}" logs --tail=80 mysql redis >&2 || true
    fi
  fi

  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

wait_healthy() {
  local service="$1"
  local deadline=$((SECONDS + HEALTH_TIMEOUT_SECONDS))
  local container_id=""
  local health=""

  while (( SECONDS < deadline )); do
    container_id="$("${COMPOSE[@]}" ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]]; then
      health="$(docker inspect --format '{{.State.Health.Status}}' "$container_id" 2>/dev/null || true)"
      if [[ "$health" == "healthy" ]]; then
        return 0
      fi
      if [[ "$health" == "unhealthy" ]]; then
        printf 'ERROR: %s became unhealthy before readiness; failing the gate.\n' "$service" >&2
        return 1
      fi
    fi
    sleep 1
  done

  printf 'ERROR: %s did not become healthy within %ss; failing the gate.\n' "$service" "$HEALTH_TIMEOUT_SECONDS" >&2
  return 1
}

published_port() {
  local service="$1"
  local container_port="$2"
  local endpoint=""
  local port=""

  endpoint="$("${COMPOSE[@]}" port "$service" "$container_port" 2>/dev/null | sed -n '1p')"
  port="${endpoint##*:}"
  if ! [[ "$port" =~ ^[1-9][0-9]*$ ]]; then
    printf 'ERROR: unable to resolve published port for %s:%s; failing the gate.\n' "$service" "$container_port" >&2
    return 1
  fi

  printf '%s\n' "$port"
}

wait_for_suite() {
  local pid="$1"
  local deadline=$((SECONDS + TEST_TIMEOUT_SECONDS))

  while kill -0 "$pid" >/dev/null 2>&1; do
    if (( SECONDS >= deadline )); then
      kill -TERM "$pid" >/dev/null 2>&1 || true
      sleep 2
      kill -KILL "$pid" >/dev/null 2>&1 || true
      wait "$pid" >/dev/null 2>&1 || true
      return 124
    fi
    sleep 1
  done

  wait "$pid"
}

run_suite() {
  cd "$API_DIR"
  exec env \
    APP_ENV=testing \
    DB_CONNECTION=mysql \
    DB_HOST=127.0.0.1 \
    DB_PORT="$MYSQL_PORT" \
    DB_DATABASE="$MYSQL_DATABASE" \
    DB_USERNAME="$MYSQL_USER" \
    DB_PASSWORD="$MYSQL_PASSWORD" \
    DB_URL= \
    REDIS_CLIENT=predis \
    REDIS_HOST=127.0.0.1 \
    REDIS_PORT="$REDIS_PORT" \
    REDIS_DB=15 \
    REDIS_URL= \
    vendor/bin/phpunit -c phpunit.mysql.xml
}

if ! command -v docker >/dev/null 2>&1; then
  skip_prerequisite docker
fi
if ! docker info >/dev/null 2>&1; then
  skip_prerequisite 'docker daemon'
fi
if ! docker compose version >/dev/null 2>&1; then
  skip_prerequisite 'docker compose'
fi
if ! command -v php >/dev/null 2>&1; then
  skip_prerequisite php
fi
if ! php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'; then
  skip_prerequisite pdo_mysql
fi
if ! php -r 'exit(extension_loaded("pcntl") ? 0 : 1);'; then
  skip_prerequisite pcntl
fi
if [[ ! -x "$API_DIR/vendor/bin/phpunit" ]]; then
  skip_prerequisite 'apps/api/vendor/bin/phpunit'
fi
if [[ ! -f "$API_DIR/phpunit.mysql.xml" ]]; then
  printf '%s\n' 'ERROR: apps/api/phpunit.mysql.xml is missing; failing the gate.' >&2
  exit 1
fi
if [[ ! -f "$COMPOSE_FILE" || ! -f "$ENV_FILE" ]]; then
  printf '%s\n' 'ERROR: MySQL integration compose inputs are missing; failing the gate.' >&2
  exit 1
fi

validate_timeout MYSQL_INTEGRATION_HEALTH_TIMEOUT_SECONDS "$HEALTH_TIMEOUT_SECONDS"
validate_timeout MYSQL_INTEGRATION_TEST_TIMEOUT_SECONDS "$TEST_TIMEOUT_SECONDS"

export W1_1_MYSQL_PORT="${MYSQL_INTEGRATION_MYSQL_PORT:-0}"
export W1_1_REDIS_PORT="${MYSQL_INTEGRATION_REDIS_PORT:-0}"
export W1_1_MYSQL_DATABASE="$MYSQL_DATABASE"
export W1_1_MYSQL_USER="$MYSQL_USER"
export W1_1_MYSQL_PASSWORD="$MYSQL_PASSWORD"
export W1_1_MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD"
# Docker Compose interpolates every declared service before selecting mysql and
# redis, including the unused MinIO/ClamAV variables from the shared file.
export W1_2_MINIO_ROOT_USER="${W1_2_MINIO_ROOT_USER:-mysql-integration-minio}"
export W1_2_MINIO_ROOT_PASSWORD="${W1_2_MINIO_ROOT_PASSWORD:-mysql-integration-minio-secret}"
export W1_2_MINIO_API_PORT="${W1_2_MINIO_API_PORT:-0}"
export W1_2_MINIO_CONSOLE_PORT="${W1_2_MINIO_CONSOLE_PORT:-0}"
export W1_2_CLAMAV_PORT="${W1_2_CLAMAV_PORT:-0}"

printf 'Starting isolated MySQL and Redis services for integration tests.\n'
COMPOSE_STARTED=1
if ! "${COMPOSE[@]}" up --detach mysql redis; then
  printf '%s\n' 'ERROR: unable to start MySQL/Redis integration services; failing the gate.' >&2
  exit 1
fi

wait_healthy mysql
wait_healthy redis
MYSQL_PORT="$(published_port mysql 3306)"
REDIS_PORT="$(published_port redis 6379)"

run_suite &
TEST_PID=$!
if wait_for_suite "$TEST_PID"; then
  TEST_PID=""
  printf 'PASS: isolated MySQL integration suite completed.\n'
else
  suite_status=$?
  TEST_PID=""
  if [[ "$suite_status" -eq 124 ]]; then
    printf 'ERROR: MySQL integration suite timed out after %ss; failing the gate.\n' "$TEST_TIMEOUT_SECONDS" >&2
  else
    printf 'ERROR: MySQL integration suite failed; failing the gate.\n' >&2
  fi
  exit "$suite_status"
fi
