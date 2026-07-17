#!/usr/bin/env python3

import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    print("ERROR: PyYAML is required to validate the W1.1 API surface.", file=sys.stderr)
    raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
SNAPSHOT = ROOT / "docs/contracts/api/w1-1.openapi.yaml"
EXPECTED_METHODS = {
    "/auth/login": {"post"},
    "/work-records": {"get", "post"},
    "/work-records/{recordId}": {"get"},
    "/notifications": {"get"},
}


document = yaml.safe_load(SNAPSHOT.read_text(encoding="utf-8"))
paths = document.get("paths") if isinstance(document, dict) else None
if not isinstance(paths, dict) or set(paths) != set(EXPECTED_METHODS):
    print("ERROR: W1.1 API snapshot paths must match the implemented Laravel routes.", file=sys.stderr)
    raise SystemExit(1)

for path, expected in EXPECTED_METHODS.items():
    path_item = paths[path]
    if not isinstance(path_item, dict):
        print(f"ERROR: W1.1 path item must be an object: {path}", file=sys.stderr)
        raise SystemExit(1)
    if "$ref" in path_item:
        source = yaml.safe_load((SNAPSHOT.parent / "openapi.yaml").read_text(encoding="utf-8"))
        source_item = source["paths"][path]
        methods = set(source_item) - {"parameters", "summary", "description", "servers"}
    else:
        methods = set(path_item) - {"parameters", "summary", "description", "servers"}
    if methods != expected:
        print(f"ERROR: W1.1 methods for {path} must be {sorted(expected)}, got {sorted(methods)}.", file=sys.stderr)
        raise SystemExit(1)

print("W1.1 OpenAPI snapshot matches the implemented Laravel route surface.")
