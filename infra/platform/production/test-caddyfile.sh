#!/usr/bin/env bash
# Focused assertions for the production Caddyfile contract:
#  - /api/v1/* is routed to PHP-FPM via php_fastcgi on api:9000 (NOT HTTP)
#  - all other paths reverse_proxy to web:8080
#  - root * /var/www/html/public is set inside the @api handle
#  - the FastCGI handler appears BEFORE the web fallback
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
assert_contains '@api path /api/v1/*' '/api/v1 matcher'
assert_contains 'php_fastcgi api:9000' 'PHP-FPM upstream'
assert_contains 'root * /var/www/html/public' 'Laravel public root'
assert_contains 'reverse_proxy web:8080' 'SPA fallback upstream'
assert_not_contains 'reverse_proxy http://api:9000' 'HTTP proxy to raw FPM (forbidden)'

# Order: @api handle must come before the web fallback so /api/v1/* never hits SPA.
api_line=$(grep -n '@api path /api/v1/\*' "$CADDYFILE" | head -1 | cut -d: -f1)
fallback_line=$(grep -n 'reverse_proxy web:8080' "$CADDYFILE" | head -1 | cut -d: -f1)
if (( api_line >= fallback_line )); then
    printf 'FAIL: @api matcher (line %d) must come BEFORE web fallback (line %d)\n' "$api_line" "$fallback_line" >&2
    exit 1
fi

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
site_routes = routes[0]["handle"][0]["routes"]

fastcgi_route = None
web_route = None
for sub in site_routes:
    match = sub.get("match")
    if not match:
        continue
    for matcher in match:
        if "path" in matcher and matcher["path"] == ["/api/v1/*"]:
            fastcgi_route = sub

for sub in site_routes:
    if "match" not in sub or not sub["match"]:
        web_route = sub

assert fastcgi_route is not None, "FastCGI route for /api/v1/* not found"
assert web_route is not None, "Web fallback route not found"

fastcgi_handles = fastcgi_route["handle"][0]["routes"]
php_handlers = []
for entry in fastcgi_handles:
    if "match" in entry and entry["match"]:
        for m in entry["match"]:
            if m.get("path") == ["*.php"]:
                php_handlers.append(entry)

assert php_handlers, "Expected a php reverse_proxy handler matching *.php"
proxy = php_handlers[-1]["handle"][0]
assert proxy["handler"] == "reverse_proxy", proxy
transport = proxy["transport"]
assert transport["protocol"] == "fastcgi", transport
assert transport["split_path"] == [".php"], transport
upstreams = proxy["upstreams"]
assert upstreams and upstreams[0]["dial"] == "api:9000", upstreams

web_proxy = web_route["handle"][0]["routes"][0]["handle"][0]
assert web_proxy["handler"] == "reverse_proxy", web_proxy
assert "transport" not in web_proxy, web_proxy
assert web_proxy["upstreams"][0]["dial"] == "web:8080", web_proxy

print("OK: /api/v1 routes via FastCGI to api:9000, fallback routes to web:8080")
PY

printf 'PASS: Caddyfile exposes /api/v1 via PHP-FPM and falls back to web:8080.\n'
