#!/usr/bin/env python3
"""Read generated image references without evaluating dotenv content.

The release pipeline writes a tiny two-line artifact, but artifacts are data
and must never be sourced as shell code.  This parser accepts only the exact
keys and immutable ``registry/path@sha256:<64 hex>`` values expected by the
release jobs, then prints one validated value for command substitution.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path


KEYS = {"API_IMAGE_DIGEST_REF", "WEB_IMAGE_DIGEST_REF"}
REF = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._/-]*@sha256:[0-9a-f]{64}$")


def parse(path: Path) -> dict[str, str]:
  try:
    lines = path.read_text(encoding="utf-8").splitlines()
  except (OSError, UnicodeError) as error:
    raise ValueError(f"cannot read image reference artifact: {error}") from error

  values: dict[str, str] = {}
  for line_number, line in enumerate(lines, 1):
    if not line:
      raise ValueError(f"line {line_number} is empty")
    if "=" not in line:
      raise ValueError(f"line {line_number} is not a key/value record")
    key, value = line.split("=", 1)
    if key not in KEYS:
      raise ValueError(f"line {line_number} contains an unexpected key")
    if key in values:
      raise ValueError(f"line {line_number} duplicates {key}")
    if not REF.fullmatch(value):
      raise ValueError(f"line {line_number} contains a non-immutable image reference")
    values[key] = value

  if values.keys() != KEYS:
    missing = ", ".join(sorted(KEYS - values.keys()))
    raise ValueError(f"image reference artifact is incomplete (missing: {missing})")
  return values


def main(argv: list[str]) -> int:
  if len(argv) != 3 or argv[2] not in KEYS:
    print(f"usage: {argv[0]} FILE {'|'.join(sorted(KEYS))}", file=sys.stderr)
    return 2
  try:
    print(parse(Path(argv[1]))[argv[2]])
  except ValueError as error:
    print(f"FAIL: {error}", file=sys.stderr)
    return 1
  return 0


if __name__ == "__main__":
  raise SystemExit(main(sys.argv))
