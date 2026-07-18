#!/usr/bin/env bash
set -euo pipefail

readonly API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly ROOT_DIR="$(cd "$API_DIR/../.." && pwd)"
readonly COMPOSE_FILE="$ROOT_DIR/infra/dev/compose.yaml"
readonly COMPOSE_ENV_FILE="$ROOT_DIR/infra/dev/.env.example"
readonly PROJECT="cluster-w12-mysql-tests-$$"
readonly MYSQL_DATABASE="${W1_2_MYSQL_DATABASE:?Set W1_2_MYSQL_DATABASE to a dedicated W1.2 test database name (for example, cluster_w12_test).}"
readonly REDIS_DATABASE="${W1_2_REDIS_DB:?Set W1_2_REDIS_DB to a dedicated Redis database number (0-15).}"
readonly MYSQL_PORT="${W1_2_MYSQL_PORT:-$(python3 - <<'PY'
import socket

with socket.socket() as listener:
    listener.bind(('127.0.0.1', 0))
    print(listener.getsockname()[1])
PY
)}"
readonly REDIS_PORT="${W1_2_REDIS_PORT:-$(python3 - <<'PY'
import socket

with socket.socket() as listener:
    listener.bind(('127.0.0.1', 0))
    print(listener.getsockname()[1])
PY
)}"
readonly MYSQL_USER="${W1_2_MYSQL_USER:-cluster_test}"
readonly MYSQL_PASSWORD="${W1_2_MYSQL_PASSWORD:-local-test-password}"
readonly MYSQL_ROOT_PASSWORD="${W1_2_MYSQL_ROOT_PASSWORD:-local-test-root}"
readonly COMPOSE=(docker compose --project-name "$PROJECT" --env-file "$COMPOSE_ENV_FILE" --file "$COMPOSE_FILE")

if ! [[ "$MYSQL_DATABASE" =~ ^(cluster_w12_test|cluster_w12_[A-Za-z0-9]+_test)$ ]]; then
    printf 'ERROR: W1_2_MYSQL_DATABASE must be a dedicated W1.2 name such as cluster_w12_test or cluster_w12_<lane>_test.\n' >&2
    exit 2
fi

if ! [[ "$REDIS_DATABASE" =~ ^([0-9]|1[0-5])$ ]]; then
    printf 'ERROR: W1_2_REDIS_DB must be an explicit Redis database number from 0 through 15.\n' >&2
    exit 2
fi

if ! test -x "$API_DIR/vendor/bin/phpunit"; then
    printf 'ERROR: PHPUnit is unavailable; run composer install in apps/api first.\n' >&2
    exit 2
fi

if ! php -r 'exit(extension_loaded("pdo_mysql") && extension_loaded("pcntl") ? 0 : 1);'; then
    printf 'ERROR: pdo_mysql and pcntl PHP extensions are required.\n' >&2
    exit 2
fi

if lsof -nP -iTCP:"$MYSQL_PORT" -sTCP:LISTEN >/dev/null 2>&1 || lsof -nP -iTCP:"$REDIS_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    printf 'ERROR: requested MySQL or Redis test port is already in use.\n' >&2
    exit 2
fi

export W1_1_MYSQL_PORT="$MYSQL_PORT"
export W1_1_REDIS_PORT="$REDIS_PORT"
export W1_1_MYSQL_DATABASE="$MYSQL_DATABASE"
export W1_1_MYSQL_USER="$MYSQL_USER"
export W1_1_MYSQL_PASSWORD="$MYSQL_PASSWORD"
export W1_1_MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD"
# Compose interpolates every service, including MinIO. These are ephemeral and
# unused because this runner starts only its isolated MySQL and Redis services.
export W1_2_MINIO_ROOT_USER="${W1_2_MINIO_ROOT_USER:-w12-mysql-test}"
export W1_2_MINIO_ROOT_PASSWORD="${W1_2_MINIO_ROOT_PASSWORD:-$(openssl rand -hex 24)}"

cleanup() {
    local exit_code=$?
    trap - EXIT
    "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || exit_code=1
    exit "$exit_code"
}
trap cleanup EXIT

wait_healthy() {
    local service="$1"
    local container_id
    local deadline=$((SECONDS + 90))

    while ((SECONDS < deadline)); do
        container_id="$("${COMPOSE[@]}" ps -q "$service" 2>/dev/null || true)"
        if [[ -n "$container_id" ]] && [[ "$(docker inspect --format '{{.State.Health.Status}}' "$container_id" 2>/dev/null || true)" == 'healthy' ]]; then
            return 0
        fi
        sleep 1
    done

    printf 'ERROR: %s did not become healthy within 90 seconds.\n' "$service" >&2
    return 1
}

printf 'Starting isolated MySQL/Redis PHPUnit resources for %s.\n' "$MYSQL_DATABASE"
"${COMPOSE[@]}" up -d mysql redis >/dev/null
wait_healthy mysql
wait_healthy redis

(
    cd "$API_DIR"
    env \
        APP_ENV=testing \
        APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
        DB_CONNECTION=mysql \
        DB_URL= \
        DB_HOST=127.0.0.1 \
        DB_PORT="$MYSQL_PORT" \
        DB_DATABASE="$MYSQL_DATABASE" \
        DB_USERNAME="$MYSQL_USER" \
        DB_PASSWORD="$MYSQL_PASSWORD" \
        REDIS_CLIENT=predis \
        REDIS_URL= \
        REDIS_HOST=127.0.0.1 \
        REDIS_PORT="$REDIS_PORT" \
        REDIS_DB="$REDIS_DATABASE" \
        php vendor/bin/phpunit -c phpunit.mysql.xml
)
