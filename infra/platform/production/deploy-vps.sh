#!/usr/bin/env bash
set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly COMPOSE_FILE="$SCRIPT_DIR/compose.yaml"
readonly ENV_FILE="${1:-$SCRIPT_DIR/.env.production}"
readonly MIGRATE_TIMEOUT_SECONDS="${MIGRATE_TIMEOUT_SECONDS:-300}"
readonly HEALTH_TIMEOUT_SECONDS="${HEALTH_TIMEOUT_SECONDS:-180}"
readonly COMPOSE=(docker compose --env-file "$ENV_FILE" --file "$COMPOSE_FILE")

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 1
}

runtime_fail() {
  printf 'ERROR: %s\n' "$1" >&2
  "${COMPOSE[@]}" ps >&2 || true
  "${COMPOSE[@]}" logs --tail=100 api web worker migrate caddy >&2 || true
  exit 1
}

if [[ ! -f "$ENV_FILE" ]]; then
  printf 'ERROR: production environment file is missing: %s\n' "$ENV_FILE" >&2
  printf 'Create it from %s/.env.example and replace every placeholder.\n' "$SCRIPT_DIR" >&2
  exit 2
fi

[[ "$MIGRATE_TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]] || fail 'MIGRATE_TIMEOUT_SECONDS must be a positive integer.'
[[ "$HEALTH_TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]] || fail 'HEALTH_TIMEOUT_SECONDS must be a positive integer.'

readonly ENV_MODE="$(stat -c '%a' "$ENV_FILE")"
if [[ "$ENV_MODE" != 600 && "$ENV_MODE" != 400 ]]; then
  fail "production environment file permissions must be 600 or 400, found $ENV_MODE"
fi

APP_DOMAIN=""
while IFS='=' read -r key value; do
  if [[ "$key" == APP_DOMAIN ]]; then
    APP_DOMAIN="${value%$'\r'}"
    break
  fi
done <"$ENV_FILE"
readonly APP_DOMAIN
[[ "$APP_DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]] \
  || fail 'APP_DOMAIN must be a plain DNS hostname such as app.example.com.'

"${COMPOSE[@]}" config --quiet
"${COMPOSE[@]}" up --detach --build --remove-orphans

migrate_deadline=$((SECONDS + MIGRATE_TIMEOUT_SECONDS))
while ((SECONDS < migrate_deadline)); do
  migrate_id="$("${COMPOSE[@]}" ps --all --quiet migrate)"
  if [[ -n "$migrate_id" ]]; then
    migrate_status="$(docker inspect --format '{{.State.Status}}' "$migrate_id")"
    if [[ "$migrate_status" == exited ]]; then
      migrate_exit="$(docker inspect --format '{{.State.ExitCode}}' "$migrate_id")"
      [[ "$migrate_exit" == 0 ]] || runtime_fail "migration failed with exit code $migrate_exit"
      break
    fi
  fi
  sleep 2
done
((SECONDS < migrate_deadline)) || runtime_fail "migration did not finish within $MIGRATE_TIMEOUT_SECONDS seconds"

health_deadline=$((SECONDS + HEALTH_TIMEOUT_SECONDS))
while ((SECONDS < health_deadline)); do
  all_healthy=true
  for service in caddy web api worker; do
    container_id="$("${COMPOSE[@]}" ps --quiet "$service")"
    if [[ -z "$container_id" || "$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id")" != healthy ]]; then
      all_healthy=false
      break
    fi
  done
  if [[ "$all_healthy" == true ]]; then
    break
  fi
  sleep 2
done
[[ "$all_healthy" == true ]] || runtime_fail "application services did not become healthy within $HEALTH_TIMEOUT_SECONDS seconds"

if ! curl --silent --show-error --fail --retry 15 --retry-all-errors --retry-delay 2 "https://$APP_DOMAIN/up" >/dev/null; then
  runtime_fail "public HTTPS health check failed for https://$APP_DOMAIN/up"
fi

"${COMPOSE[@]}" ps
printf 'Deployment is healthy at https://%s\n' "$APP_DOMAIN"
