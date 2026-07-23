#!/usr/bin/env bash
set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly WEB_DIR="$ROOT_DIR/apps/web"
readonly API_ORIGIN="${W1_1_API_ORIGIN:-}"

skip() {
  printf 'SKIP platform-settings e2e: %s\n' "$1"
  printf 'PLATFORM_SETTINGS_E2E=skipped reason=%s\n' "$1"
}

if [[ -z "$API_ORIGIN" ]]; then
  skip 'W1_1_API_ORIGIN is not set to a pre-running localhost API'
  exit 0
fi

if ! [[ "$API_ORIGIN" =~ ^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]{2,5})?$ ]]; then
  skip 'W1_1_API_ORIGIN is not a plain localhost origin'
  exit 0
fi

if ! curl --silent --fail --max-time 2 "$API_ORIGIN/up" >/dev/null; then
  skip "API unavailable at $API_ORIGIN/up"
  exit 0
fi

cd "$WEB_DIR"
W1_1_API_ORIGIN="$API_ORIGIN" npm run test:e2e:local -- e2e/platform-settings.spec.ts
