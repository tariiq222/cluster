#!/usr/bin/env bash
set -euo pipefail

readonly source_dir="docs/architecture/diagrams"
readonly output_dir="${1:-build/diagrams}"

if ! command -v mmdc >/dev/null 2>&1; then
  printf '%s\n' 'Mermaid CLI (mmdc) is unavailable; no diagrams were rendered.' >&2
  exit 0
fi

if [[ ! -d "$source_dir" ]]; then
  printf 'Diagram source directory is missing: %s\n' "$source_dir" >&2
  exit 1
fi

staging_dir="$(mktemp -d "${TMPDIR:-/tmp}/mermaid-render.XXXXXX")"
trap 'rm -rf "$staging_dir"' EXIT

while IFS= read -r -d '' source; do
  name="$(basename "${source%.mmd}")"
  mmdc --input "$source" --output "$staging_dir/$name.svg" --backgroundColor transparent
done < <(find "$source_dir" -type f -name '*.mmd' -print0 | sort -z)

shopt -s nullglob
rendered=("$staging_dir"/*.svg)
if ((${#rendered[@]} == 0)); then
  printf '%s\n' 'No Mermaid diagram sources were found; no diagrams were rendered.'
  exit 0
fi

mkdir -p "$output_dir"
rm -f "$output_dir"/*.svg
mv "${rendered[@]}" "$output_dir/"
printf 'Rendered Mermaid diagrams to %s\n' "$output_dir"
