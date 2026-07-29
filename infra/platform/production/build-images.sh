#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
readonly API_IMAGE="${API_IMAGE:-cluster-api:local}"
readonly WEB_IMAGE="${WEB_IMAGE:-cluster-web:local}"

docker build --file "$ROOT_DIR/apps/api/Dockerfile" --tag "$API_IMAGE" "$ROOT_DIR/apps/api"
# The web build context is the repo root: SwaggerUiScreen imports
# docs/contracts/api/openapi.yaml relative to the source tree, which escapes
# an apps/web-only context.
docker build --file "$ROOT_DIR/apps/web/Dockerfile" --tag "$WEB_IMAGE" "$ROOT_DIR"

printf 'Built production images: %s and %s\n' "$API_IMAGE" "$WEB_IMAGE"
