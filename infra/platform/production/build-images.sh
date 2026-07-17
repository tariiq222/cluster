#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
readonly API_IMAGE="${W11_BLD_API_IMAGE:-cluster-api:w11-bld-02}"
readonly WEB_IMAGE="${W11_BLD_WEB_IMAGE:-cluster-web:w11-bld-02}"

docker build --file "$ROOT_DIR/apps/api/Dockerfile" --tag "$API_IMAGE" "$ROOT_DIR/apps/api"
docker build --file "$ROOT_DIR/apps/web/Dockerfile" --tag "$WEB_IMAGE" "$ROOT_DIR/apps/web"

printf 'Built production images: %s and %s\n' "$API_IMAGE" "$WEB_IMAGE"
