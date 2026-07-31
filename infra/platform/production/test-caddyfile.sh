#!/usr/bin/env bash
# Focused assertions for the production Caddyfile contract:
#  - all paths reverse_proxy to web:8080 (the web nginx owns /api/ fastcgi routing)
#  - TLS/security headers (HSTS, CSP, frame/type/mime guards) are set at the edge
#  - the `-Server` header is removed so the proxy never leaks the Caddy version
#
# Usage:  bash infra/platform/production/test-caddyfile.sh
set -euo pipefail

readonly CADDYFILE="${CADDYFILE:-infra/platform/production/Caddyfile}"
readonly IMAGE="${CADDY_IMAGE:-docker.io/library/caddy:2.10.2-alpine}"

if [[ ! -f "$CADDYFILE" ]]; then
    printf 'FAIL: Caddyfile not found at %s\n' "$CADDYFILE" >&2
    exit 1
fi

assert_contains() {
    local needle="$1"
    local label="$2"
    if ! grep -Fq "$needle" "$CADDYFILE"; then
        printf 'FAIL: Caddyfile is missing %s (%s)\n' "$label" "$needle" >&2
        exit 1
    fi
}

assert_not_contains() {
    local needle="$1"
    local label="$2"
    if grep -Fq "$needle" "$CADDYFILE"; then
        printf 'FAIL: Caddyfile must not contain %s (%s)\n' "$label" "$needle" >&2
        exit 1
    fi
}

# Source-shape assertions.
assert_contains 'reverse_proxy web:8080' 'SPA fallback upstream'
assert_contains 'Strict-Transport-Security' 'HSTS edge header'
assert_contains 'max-age=31536000' 'HSTS max-age'
assert_contains 'X-Content-Type-Options' 'nosniff guard'
assert_contains 'X-Frame-Options' 'frame guard'
assert_contains 'Referrer-Policy' 'referrer guard'
assert_contains 'Content-Security-Policy' 'CSP guard'
assert_contains 'frame-ancestors' 'CSP frame-ancestors'
assert_contains '-Server' 'Server header removal'
assert_not_contains 'reverse_proxy http://api:9000' 'HTTP proxy to raw FPM (forbidden)'
assert_not_contains 'php_fastcgi' 'Caddy-level FastCGI (routing moved to web nginx)'

# Adapt + validate the file with the real Caddy image to confirm it parses.
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
mount_file="$tmp_dir/Caddyfile"
cp "$CADDYFILE" "$mount_file"

APP_DOMAIN="${APP_DOMAIN:-app.example.com}" \
    docker run --rm --label cluster-test-caddyfile \
    -e APP_DOMAIN \
    -v "$mount_file:/etc/caddy/Caddyfile:ro" \
    "$IMAGE" \
    caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null

APP_DOMAIN="${APP_DOMAIN:-app.example.com}" \
    docker run --rm --label cluster-test-caddyfile \
    -e APP_DOMAIN \
    -v "$mount_file:/etc/caddy/Caddyfile:ro" \
    "$IMAGE" \
    caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile >"$tmp_dir/adapted.json"

TMP_JSON="$tmp_dir/adapted.json" python3 - <<'PY'
import json, os

with open(os.environ["TMP_JSON"]) as fh:
    config = json.load(fh)

routes = config["apps"]["http"]["servers"]["srv0"]["routes"]
assert routes, "Expected at least one route"
# The site block wraps everything in a subroute; locate the first handle.
site = routes[0]["handle"][0]
assert site["handler"] == "subroute", site
subroutes = site["routes"]

# The adapted config nests a single subroute holding encode + headers +
# reverse_proxy in order. Verify the proxy target and the header contract.
handles = []
for sub in subroutes:
    for entry in sub.get("handle", []):
        if entry.get("handler") == "subroute":
            handles.extend(entry.get("routes", [])[0].get("handle", []))
        elif entry.get("handler") in ("headers", "encode", "reverse_proxy"):
            handles.append(entry)

web_proxy = next((h for h in handles if h.get("handler") == "reverse_proxy"), None)
assert web_proxy is not None, "Web fallback reverse_proxy not found"
assert "transport" not in web_proxy, web_proxy
assert web_proxy["upstreams"][0]["dial"] == "web:8080", web_proxy

header_handler = next((h for h in handles if h.get("handler") == "headers"), None)
assert header_handler is not None, "Expected an edge header handler"
response_headers = header_handler.get("response", {})
set_headers = response_headers.get("set", {})
deleted_headers = response_headers.get("delete", [])

for expected in (
    "Strict-Transport-Security",
    "X-Content-Type-Options",
    "X-Frame-Options",
    "Referrer-Policy",
    "Content-Security-Policy",
    "Permissions-Policy",
):
    assert expected in set_headers, f"Missing edge header: {expected}"

assert "Server" in deleted_headers, "Edge must delete the Server header"

print("OK: all paths route to web:8080 and the security header set is present at the edge")
PY

printf 'PASS: Caddyfile routes to web:8080 with the production security header set.\n'
