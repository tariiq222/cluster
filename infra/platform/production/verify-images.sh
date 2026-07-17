#!/usr/bin/env bash
set -euo pipefail

readonly API_IMAGE="${W11_BLD_API_IMAGE:-cluster-api:w11-bld-02}"
readonly WEB_IMAGE="${W11_BLD_WEB_IMAGE:-cluster-web:w11-bld-02}"

docker image inspect "$API_IMAGE" "$WEB_IMAGE" >/dev/null

[[ "$(docker image inspect "$API_IMAGE" --format '{{.Config.User}}')" == app:app ]]
[[ "$(docker image inspect "$WEB_IMAGE" --format '{{.Config.User}}')" == 101:101 ]]
docker image inspect "$API_IMAGE" --format '{{json .Config.Healthcheck.Test}}' | grep -Fq "fsockopen"
docker image inspect "$WEB_IMAGE" --format '{{json .Config.Healthcheck.Test}}' | grep -Fq "127.0.0.1:8080/up"

docker run --rm --entrypoint php "$API_IMAGE" -r "exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);"
docker run --rm --entrypoint php-fpm "$API_IMAGE" -tt >/dev/null 2>&1
docker run --rm --entrypoint sh "$API_IMAGE" -c '
  test "$(id -u)" = 10001
  test ! -d tests
  test ! -x /usr/local/bin/composer
  test -f public/index.php
  test -f vendor/autoload.php
  test -f composer.json
'
docker run --rm --add-host api:127.0.0.1 --entrypoint nginx "$WEB_IMAGE" -t >/dev/null 2>&1
docker run --rm --entrypoint sh "$WEB_IMAGE" -c '
  test "$(id -u)" != 0
  test ! -d /app/node_modules
  test ! -x /usr/local/bin/node
  test -f /usr/share/nginx/html/index.html
'

printf 'PASS: production images contain runtime-only artifacts and run as non-root users.\n'
