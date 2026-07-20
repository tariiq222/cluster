#!/usr/bin/env bash
set -euo pipefail

pick_free_port() {
  local excluded=" $* " candidate
  while true; do
    candidate="$(python3 - <<'PY'
import socket
with socket.socket() as listener:
    listener.bind(('127.0.0.1', 0))
    print(listener.getsockname()[1])
PY
)"
    [[ "$excluded" == *" $candidate "* ]] || { printf '%s\n' "$candidate"; return; }
  done
}

uuid7() {
  python3 - <<'PY'
import os, time
b = bytearray(os.urandom(16))
t = int(time.time() * 1000)
for i in range(5, -1, -1):
    b[i] = t & 0xff
    t >>= 8
b[6] = (b[6] & 0x0f) | 0x70
b[8] = (b[8] & 0x3f) | 0x80
h = b.hex()
print(f'{h[:8]}-{h[8:12]}-{h[12:16]}-{h[16:20]}-{h[20:]}')
PY
}

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/infra/dev/compose.w1-2-e2e.yaml"
readonly API_DIR="$ROOT_DIR/apps/api"
readonly WEB_DIR="$ROOT_DIR/apps/web"
readonly PROJECT="cluster-w12-e2e-$$"
readonly MYSQL_PORT="${W1_2_MYSQL_PORT:-$(pick_free_port)}"
readonly REDIS_PORT="${W1_2_REDIS_PORT:-$(pick_free_port "$MYSQL_PORT")}" 
readonly MINIO_API_PORT="${W1_2_MINIO_API_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT")}" 
readonly MINIO_CONSOLE_PORT="${W1_2_MINIO_CONSOLE_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT" "$MINIO_API_PORT")}" 
readonly CLAMAV_PORT="${W1_2_CLAMAV_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT" "$MINIO_API_PORT" "$MINIO_CONSOLE_PORT")}" 
readonly API_PORT="${W1_2_API_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT" "$MINIO_API_PORT" "$MINIO_CONSOLE_PORT" "$CLAMAV_PORT")}" 
readonly WEB_PORT="${W1_2_WEB_PORT:-$(pick_free_port "$MYSQL_PORT" "$REDIS_PORT" "$MINIO_API_PORT" "$MINIO_CONSOLE_PORT" "$CLAMAV_PORT" "$API_PORT")}" 
readonly HEALTH_TIMEOUT="${W1_2_HEALTH_TIMEOUT_SECONDS:-240}"
readonly MYSQL_DATABASE="cluster_w12_e2e"
readonly MYSQL_USER="w12_e2e"
readonly MYSQL_PASSWORD="$(openssl rand -hex 24)"
readonly MYSQL_ROOT_PASSWORD="$(openssl rand -hex 24)"
readonly MINIO_ROOT_USER="w12e2e$(openssl rand -hex 6)"
readonly MINIO_ROOT_PASSWORD="$(openssl rand -hex 24)"
readonly DOCUMENTS_WORKER_TOKEN="$(openssl rand -hex 32)"
readonly APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
readonly LOG_FILE="${TMPDIR:-/tmp}/cluster-w12-e2e-$$.log"
readonly COMPOSE=(docker compose --project-name "$PROJECT" --file "$COMPOSE_FILE")
API_PID=""
VITE_PID=""
DRIVER_PID=""

export W1_2_MYSQL_PORT="$MYSQL_PORT" W1_2_REDIS_PORT="$REDIS_PORT" W1_2_MINIO_API_PORT="$MINIO_API_PORT" W1_2_MINIO_CONSOLE_PORT="$MINIO_CONSOLE_PORT" W1_2_CLAMAV_PORT="$CLAMAV_PORT"
export W1_2_MYSQL_DATABASE="$MYSQL_DATABASE" W1_2_MYSQL_USER="$MYSQL_USER" W1_2_MYSQL_PASSWORD="$MYSQL_PASSWORD" W1_2_MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD"
export W1_2_MINIO_ROOT_USER="$MINIO_ROOT_USER" W1_2_MINIO_ROOT_PASSWORD="$MINIO_ROOT_PASSWORD" W1_2_MINIO_CORS_ORIGIN="http://127.0.0.1:${WEB_PORT}"

kill_tree() {
  local pid="$1" child
  for child in $(pgrep -P "$pid" 2>/dev/null || true); do kill_tree "$child"; done
  kill -TERM "$pid" >/dev/null 2>&1 || true
}

cleanup() {
  local status=$?
  trap - EXIT
  for pid in "$DRIVER_PID" "$VITE_PID" "$API_PID"; do
    if [[ -n "$pid" ]]; then
      kill_tree "$pid"
      wait "$pid" >/dev/null 2>&1 || true
    fi
  done
  if ! "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1; then [[ "$status" -ne 0 ]] || status=1; fi
  if [[ -n "$("${COMPOSE[@]}" ps -q 2>/dev/null || true)" ]]; then [[ "$status" -ne 0 ]] || status=1; fi
  if [[ "$status" -ne 0 && -s "$LOG_FILE" ]]; then tail -n 160 "$LOG_FILE" >&2 || true; fi
  rm -f "$LOG_FILE"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

assert_port_free() {
  local port="$1"
  [[ "$port" =~ ^[0-9]+$ ]] && (( port >= 1024 && port <= 65535 )) || { printf 'ERROR: invalid port %s\n' "$port" >&2; return 1; }
  ! lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1 || { printf 'ERROR: localhost port %s is already in use.\n' "$port" >&2; return 1; }
}

wait_healthy() {
  local service="$1" deadline=$((SECONDS + HEALTH_TIMEOUT)) container_id
  while (( SECONDS < deadline )); do
    container_id="$("${COMPOSE[@]}" ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" && "$(docker inspect --format '{{.State.Health.Status}}' "$container_id" 2>/dev/null || true)" == healthy ]]; then return; fi
    sleep 1
  done
  printf 'ERROR: %s did not become healthy within %ss.\n' "$service" "$HEALTH_TIMEOUT" >&2
  return 1
}

wait_exited_successfully() {
  local service="$1" deadline=$((SECONDS + HEALTH_TIMEOUT)) container_id status exit_code
  while (( SECONDS < deadline )); do
    container_id="$("${COMPOSE[@]}" ps -aq "$service" 2>/dev/null || true)"
    if [[ -n "$container_id" ]]; then
      status="$(docker inspect --format '{{.State.Status}}' "$container_id")"
      exit_code="$(docker inspect --format '{{.State.ExitCode}}' "$container_id")"
      [[ "$status" != exited ]] || [[ "$exit_code" == 0 ]] && return
      [[ "$status" != exited ]] || { printf 'ERROR: %s exited with %s.\n' "$service" "$exit_code" >&2; return 1; }
    fi
    sleep 1
  done
  printf 'ERROR: %s did not finish within %ss.\n' "$service" "$HEALTH_TIMEOUT" >&2
  return 1
}

wait_http() {
  local url="$1" deadline=$((SECONDS + HEALTH_TIMEOUT))
  while (( SECONDS < deadline )); do
    curl --silent --show-error --fail --max-time 3 "$url" >/dev/null 2>&1 && return
    sleep 1
  done
  printf 'ERROR: HTTP readiness probe failed for %s.\n' "$url" >&2
  return 1
}

wait_tcp() {
  local port="$1" deadline=$((SECONDS + HEALTH_TIMEOUT))
  while (( SECONDS < deadline )); do nc -z 127.0.0.1 "$port" >/dev/null 2>&1 && return; sleep 1; done
  printf 'ERROR: TCP readiness probe failed for 127.0.0.1:%s.\n' "$port" >&2
  return 1
}

json_value() {
  local key="$1"
  python3 -c 'import json, sys; print(json.load(sys.stdin)[sys.argv[1]])' "$key"
}

readonly API_ENV=(
  APP_ENV=testing APP_KEY="$APP_KEY"
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$MYSQL_PORT" DB_DATABASE="$MYSQL_DATABASE" DB_USERNAME="$MYSQL_USER" DB_PASSWORD="$MYSQL_PASSWORD"
  REDIS_CLIENT=predis REDIS_HOST=127.0.0.1 REDIS_PORT="$REDIS_PORT"
  SESSION_DRIVER=array CACHE_STORE=array
  DOCUMENTS_TEST_RUNTIME_ENABLED=true DOCUMENTS_UPLOAD_ENDPOINT_ALLOWLIST=127.0.0.1
  DOCUMENTS_S3_REGION=us-east-1 DOCUMENTS_S3_ENDPOINT="http://127.0.0.1:${MINIO_API_PORT}" DOCUMENTS_S3_USE_PATH_STYLE=true
  DOCUMENTS_S3_QUARANTINE_BUCKET=documents-quarantine DOCUMENTS_S3_AVAILABLE_BUCKET=documents-available
  DOCUMENTS_S3_ACCESS_KEY_ID="$MINIO_ROOT_USER" DOCUMENTS_S3_SECRET_ACCESS_KEY="$MINIO_ROOT_PASSWORD" DOCUMENTS_UPLOAD_INTENT_TTL_SECONDS=300
  DOCUMENTS_CLAMAV_TRANSPORT=tcp DOCUMENTS_CLAMAV_HOST=127.0.0.1 DOCUMENTS_CLAMAV_PORT="$CLAMAV_PORT" DOCUMENTS_CLAMAV_ENGINE_NAME=clamav-e2e DOCUMENTS_CLAMAV_SIGNATURE_VERSION=e2e-signatures
)

mysql_versions() {
  local where="$1"
  "${COMPOSE[@]}" exec -T mysql mysql --batch --skip-column-names -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT public_id FROM document_versions WHERE ${where} ORDER BY created_at;"
}

document_action() {
  local version_id="$1" action="$2" expected_status="$3" response body_file
  body_file="$(mktemp)"
  response="$(curl --silent --show-error --max-time 30 --output "$body_file" --write-out '%{http_code}' \
    --request POST "http://127.0.0.1:${API_PORT}/api/v1/internal/documents/versions/${version_id}/${action}" \
    --header 'Content-Type: application/json' \
    --header "X-Documents-Worker-Token: ${DOCUMENTS_WORKER_TOKEN}" \
    --header "X-Correlation-ID: $(uuid7)" \
    --header "Idempotency-Key: w12-e2e-${action}-${version_id}-$(uuid7)" \
    --data '{}')" || { rm -f "$body_file"; printf 'ERROR: document %s request failed.\n' "$action" >&2; return 1; }
  if [[ "$response" != "$expected_status" ]]; then
    printf 'ERROR: document %s returned HTTP %s: %s\n' "$action" "$response" "$(<"$body_file")" >&2
    rm -f "$body_file"
    return 1
  fi
  rm -f "$body_file"
}

document_driver() {
  local version_id
  while true; do
    while IFS= read -r version_id; do
      [[ -z "$version_id" ]] || document_action "$version_id" scan 202 || return
    done < <(mysql_versions "scan_status = 'pending' AND availability_status = 'quarantined'")
    while IFS= read -r version_id; do
      [[ -z "$version_id" ]] || document_action "$version_id" reconcile-promotion 200 || return
    done < <(mysql_versions "scan_status = 'clean' AND availability_status = 'promotion_pending'")
    sleep 1
  done
}

printf 'Starting isolated W1.2 MySQL, Redis, MinIO, ClamAV, Laravel, Vite, and browser lifecycle.\n'
for port in "$MYSQL_PORT" "$REDIS_PORT" "$MINIO_API_PORT" "$MINIO_CONSOLE_PORT" "$CLAMAV_PORT" "$API_PORT" "$WEB_PORT"; do assert_port_free "$port"; done
"${COMPOSE[@]}" config --quiet
"${COMPOSE[@]}" up -d mysql redis minio clamav >>"$LOG_FILE" 2>&1
for service in mysql redis minio clamav; do wait_healthy "$service"; done
"${COMPOSE[@]}" up -d --no-deps minio-init >>"$LOG_FILE" 2>&1
wait_exited_successfully minio-init

(
  cd "$API_DIR"
  env "${API_ENV[@]}" php artisan migrate:fresh --force
) >>"$LOG_FILE" 2>&1
(cd "$API_DIR" && env "${API_ENV[@]}" php artisan db:seed --class=Database\\Seeders\\DevelopmentJourneyAuthorizationSeeder --force) >>"$LOG_FILE" 2>&1
SEED_JSON="$(cd "$API_DIR" && env "${API_ENV[@]}" php artisan e2e:w1-2:seed)"
IDENTITY_USERNAME="$(printf '%s' "$SEED_JSON" | json_value identity_username)"
IDENTITY_PASSWORD="$(printf '%s' "$SEED_JSON" | json_value identity_password)"
IMPORT_USERNAME="$(printf '%s' "$SEED_JSON" | json_value import_username)"
IMPORT_PASSWORD="$(printf '%s' "$SEED_JSON" | json_value import_password)"
IMPORT_POSITION_ID="$(printf '%s' "$SEED_JSON" | json_value import_position_id)"
TEMPORARY_PERSON_ID="$(printf '%s' "$SEED_JSON" | json_value temporary_assignment_person_id)"
TEMPORARY_UNIT_ID="$(printf '%s' "$SEED_JSON" | json_value temporary_assignment_unit_id)"
TEMPORARY_CAPABILITY="$(printf '%s' "$SEED_JSON" | json_value temporary_assignment_capability)"

(
  cd "$API_DIR"
  exec env "${API_ENV[@]}" \
    IDENTITY_DEFAULT_ORGANIZATION_UNIT_ID="$TEMPORARY_UNIT_ID" \
    DOCUMENTS_WORKER_TOKEN="$DOCUMENTS_WORKER_TOKEN" \
    DOCUMENTS_WORKER_USER_ID=018f6f7d-0c00-7000-8000-000000000021 \
    DOCUMENTS_WORKER_ORGANIZATION_UNIT_ID="$TEMPORARY_UNIT_ID" \
    php artisan serve --host=127.0.0.1 --port="$API_PORT"
) >>"$LOG_FILE" 2>&1 &
API_PID=$!
wait_tcp "$API_PORT"

(
  cd "$WEB_DIR"
  exec env W1_1_API_ORIGIN="http://127.0.0.1:${API_PORT}" npm run dev -- --port "$WEB_PORT" --strictPort
) >>"$LOG_FILE" 2>&1 &
VITE_PID=$!
wait_http "http://127.0.0.1:${WEB_PORT}/"

document_driver >>"$LOG_FILE" 2>&1 &
DRIVER_PID=$!
sleep 1
kill -0 "$DRIVER_PID"

if ! (
  cd "$WEB_DIR"
  env \
    W1_2_WEB_ORIGIN="http://127.0.0.1:${WEB_PORT}" \
    W1_2_SESSION_SECURE_COOKIE=true \
    W1_2_IDENTITY_USERNAME="$IDENTITY_USERNAME" \
    W1_2_IDENTITY_PASSWORD="$IDENTITY_PASSWORD" \
    W1_2_IMPORT_USERNAME="$IMPORT_USERNAME" \
    W1_2_IMPORT_PASSWORD="$IMPORT_PASSWORD" \
    W1_2_IMPORT_POSITION_ID="$IMPORT_POSITION_ID" \
    W1_2_TEMPORARY_ASSIGNMENT_PERSON_ID="$TEMPORARY_PERSON_ID" \
    W1_2_TEMPORARY_ASSIGNMENT_UNIT_ID="$TEMPORARY_UNIT_ID" \
    W1_2_TEMPORARY_ASSIGNMENT_CAPABILITY="$TEMPORARY_CAPABILITY" \
    npm run test:e2e:w1-2
) >>"$LOG_FILE" 2>&1; then
  printf 'ERROR: W1.2 Playwright suite failed.\n' >&2
  exit 1
fi

if ! kill -0 "$DRIVER_PID" 2>/dev/null; then
  wait "$DRIVER_PID"
  printf 'ERROR: W1.2 document scan/reconciliation driver stopped unexpectedly.\n' >&2
  exit 1
fi

printf 'PASS: W1.2 browser cookie/CSRF, signed MinIO import, ClamAV scan/reconciliation, and temporary assignment lifecycle completed.\n'
