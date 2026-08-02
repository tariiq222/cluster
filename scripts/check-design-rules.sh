#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
SRC=apps/web/src
fail=0

# Directional utilities break RTL. Logical properties only.
if rg -n --type-add 'tsx:*.{ts,tsx}' -t tsx \
   -e 'className="[^"]*\b(ml|mr|pl|pr|left|right|border-l|border-r|rounded-l|rounded-r)-' \
   -e 'className="[^"]*\btext-(left|right)\b' \
   "$SRC"; then
  echo "ERROR: directional utilities found. Use logical properties (ms/me/ps/pe/start/end/text-start/text-end)." >&2
  fail=1
fi

# Literal colors are permitted only in the theme file.
if rg -n --glob '!'"$SRC"'/styles/theme.css' \
   -e '#[0-9a-fA-F]{3,8}\b' -e 'rgba?\(' -e 'hsla?\(' -e 'oklch\(' \
   "$SRC"; then
  echo "ERROR: literal color outside src/styles/theme.css." >&2
  fail=1
fi

# Generated output is never hand-edited.
if rg -n 'eslint-disable|@ts-ignore|@ts-expect-error' "$SRC/components/ui" 2>/dev/null; then
  echo "ERROR: src/components/ui is generated and must not be hand-edited." >&2
  fail=1
fi

[ "$fail" -eq 0 ] && echo "Design rules OK."
exit "$fail"
