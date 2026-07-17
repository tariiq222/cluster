#!/usr/bin/env python3

import hashlib
import json
import sys
from pathlib import Path
from urllib.parse import urlsplit


def validate_live_policy(policy_path: Path, example_path: Path) -> list[str]:
    failures: list[str] = []
    if policy_path.is_symlink():
        failures.append("live policy must be a regular file, not a symbolic link")
    policy_bytes = policy_path.read_bytes()
    example_bytes = example_path.read_bytes()
    if policy_path.resolve() == example_path.resolve():
        failures.append("live policy path resolves to the checked-in example")
    if hashlib.sha256(policy_bytes).digest() == hashlib.sha256(example_bytes).digest():
        failures.append("live policy content matches the checked-in example")

    policy = json.loads(policy_bytes)
    endpoints = policy.get("endpoints") if isinstance(policy, dict) else None
    if not isinstance(endpoints, dict):
        failures.append("live policy endpoints must be an object")
        return failures
    for field in ("public_https_origin", "management_https_origin"):
        value = endpoints.get(field)
        hostname = urlsplit(value).hostname if isinstance(value, str) else None
        if not hostname or hostname.endswith(".invalid"):
            failures.append(f"{field} must use an approved non-placeholder hostname")

    return failures


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print("usage: validate_live_net04_policy.py POLICY EXAMPLE", file=sys.stderr)
        raise SystemExit(2)
    failures = validate_live_policy(Path(sys.argv[1]), Path(sys.argv[2]))
    if failures:
        for failure in failures:
            print(f"ERROR: {failure}", file=sys.stderr)
        raise SystemExit(1)
    print("Live NET-04 policy is distinct from checked-in placeholders.")
