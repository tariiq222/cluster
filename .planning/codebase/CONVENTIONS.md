# Coding Conventions

**Analysis Date:** 2026-07-15

## Current Implementation Scope

- Treat this checkout as a documentation repository with validation automation, not as an implemented Laravel/React product. The executable project-owned code is `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`; there is no root `composer.json`, product `package.json`, `phpunit.xml`, `tsconfig.json`, or application source tree.
- Treat `docs/engineering/*.md` and `docs/plans/*.md` as documented expectations. They prescribe future product conventions but do not prove that product code, tools, or tests exist.
- Use `docs/` as the authoritative documentation source. Do not use `.planning/archive/` as current authority; the source-of-truth rule is stated in `docs/governance/document-control.md` and the active inventory is `docs/catalog.yaml`.
- The current governed documentation set passes `./scripts/validate-docs.sh`. Preserve that green baseline whenever changing `docs/`, `mkdocs.yml`, `.gitlab-ci.yml`, repository JSON, or `scripts/*.sh` (`scripts/validate-docs.sh`).
- All eight current Mermaid sources under `docs/architecture/diagrams/` render successfully through `scripts/render-diagrams.sh` with the locally available Mermaid CLI 11.16.0. Preserve that green baseline whenever changing a `.mmd` source or the renderer.
- A local strict MkDocs build is not currently runnable because MkDocs is absent from the inspected host Python environment. Install `requirements-docs.txt` from the approved internal source before using `python -m mkdocs build --strict`; treat this as a local setup prerequisite, not as a documentation-content failure (`README.md`, `requirements-docs.txt`).

## Naming Patterns

**Files:**
- Name documentation files with lowercase Latin `kebab-case` and `.md`, without spaces or Arabic characters, following `CONTRIBUTING.md` and `docs/governance/document-control.md`. Examples include `docs/engineering/testing-strategy.md` and `docs/data-security/authorization-model.md`.
- Keep section indexes named `README.md`, as in `docs/engineering/README.md` and `docs/contracts/README.md`; these uppercase names are deliberate index-file exceptions to ordinary document naming.
- Prefix accepted ADR files with a zero-padded sequence and a kebab-case decision name, such as `docs/adr/001-modular-monolith.md`; use `docs/adr/template.md` as the document shape.
- Name contract schemas `<subject>.schema.json`, such as `docs/contracts/schemas/access-decision.schema.json`; keep API/event descriptions at `docs/contracts/api/openapi.yaml` and `docs/contracts/events/asyncapi.yaml`.
- Name Mermaid sources in kebab-case with `.mmd` under `docs/architecture/diagrams/`, such as `docs/architecture/diagrams/document-sequence.mmd`. Do not add rendered `.svg` or `.png` beneath `docs/`; `scripts/validate-docs.sh` rejects them.
- Name repository scripts with kebab-case and `.sh`, as in `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`.

**Functions:**
- Use `snake_case` for Python functions in the embedded validator, following `add_error()`, `parse_frontmatter()`, `markdown_anchors()`, `validate_link()`, and `walk_nav()` in `scripts/validate-docs.sh`.
- Use behavior-oriented function names that state the validation operation. Keep parsing, normalization, and checking in separate helpers as shown in `scripts/validate-docs.sh`.
- No implemented PHP or TypeScript function naming convention can be inferred because no product source exists. Do not present names in `docs/plans/` as implemented code.

**Variables:**
- Use uppercase snake case for Python module constants such as `ROOT`, `DOCS`, `CATALOG`, `REQUIRED_FIELDS`, and `ALLOWED_STATUSES` in `scripts/validate-docs.sh`.
- Use lower snake case for Python locals and parameters such as `raw_target`, `anchor_cache`, and `markdown_paths` in `scripts/validate-docs.sh`.
- Use lower snake case for shell variables and mark stable values `readonly`, following `root` in `scripts/validate-docs.sh` and `source_dir`, `output_dir`, and `staging_dir` in `scripts/render-diagrams.sh`.
- Use canonical lowercase snake-case values in contracts where specified, such as `top_secret` in `docs/contracts/schemas/access-decision.schema.json`.

**Types:**
- No executable product types exist. Use documented PascalCase names only as design vocabulary—such as `WorkRecord`, `AccessDecision`, and `SubmitWorkRecord` in `docs/engineering/vertical-slices.md`—until application source is introduced.
- Name a future vertical slice with a business verb and outcome, not a horizontal service noun: `SubmitWorkRecord` or `ApproveMilestone`, not `RecordService`, as required by `docs/engineering/vertical-slices.md`.
- Preserve stable uppercase document identifiers such as `GOV-DC-001` and `ARC-EN-004`; the grammar and reservation rule are defined in `docs/governance/document-control.md` and enforced by `scripts/validate-docs.sh`.
- Preserve traceability identifiers in plans: `REQ-<release>-<wave>-<number>`, `TEST-<REQ-ID>-<number>`, and `DEF-<number>` are specified in `docs/plans/implementation-roadmap.md`. These are documentation contracts, not implemented test IDs.

## Code Style

**Formatting:**
- Use UTF-8, LF endings, a final newline, and no trailing whitespace by default according to `.editorconfig`; Markdown deliberately permits trailing whitespace in `.editorconfig`.
- Use two-space indentation for YAML, JSON, and shell according to `.editorconfig`. Existing Markdown front matter often uses unindented YAML sequence items; preserve valid local style when editing an existing document.
- Enforce LF normalization for Markdown, YAML, JSON, shell, and Mermaid files with `.gitattributes`.
- Write documentation primarily in Arabic and introduce essential English technical terms consistently with `docs/governance/glossary.md` and `docs/governance/document-control.md`.
- Start every Markdown file beneath `docs/`, plus root `README.md`, with the required metadata fields documented in `docs/governance/document-control.md`. Non-ADR documents may not add extra front-matter fields; ADR-only fields are represented by `docs/adr/template.md`.
- End governed documents with an append-only `## سجل التغيير` table as required by `docs/governance/document-control.md`. The current validator in `scripts/validate-docs.sh` does not enforce this section, so reviewers must check it.
- Prefer tables and numbered lists for requirements and decisions, and attach a measurable acceptance criterion to each requirement, following `docs/governance/document-control.md`.
- Keep Mermaid as text source in `docs/architecture/diagrams/*.mmd`. Write local render output to `build/diagrams/` through `scripts/render-diagrams.sh`; both `build/` and `site/` are ignored by `.gitignore`.

**Linting:**
- Run `./scripts/validate-docs.sh` before review as required by `CONTRIBUTING.md`. It runs `bash -n` over `scripts/*.sh` and then executes the embedded Python validator in `scripts/validate-docs.sh`.
- The custom validator checks YAML and JSON parsing, required front matter, document ID uniqueness, allowed metadata values, SemVer and dates, reviewer roles, internal source/reference paths and fragments, Markdown/HTML links, raw `docs/...` references, unfinished markers, deprecated `doc/` references, forbidden `Requests` module declarations, navigation/catalog completeness, catalog metadata agreement, empty documentation directories, manual rendered diagrams under `docs/`, and `.DS_Store` files (`scripts/validate-docs.sh`).
- Keep explicit stable heading IDs when front matter or cross-document links target release-wave sections. The current working pattern is `### W1.10 ... {#w1-10}` in `docs/plans/release-1-platform.md`, with equivalent `{#w2-7}` and `{#w3-7}` anchors in `docs/plans/release-2-strategy-portfolio.md` and `docs/plans/release-3-risk.md`; `docs/plans/readiness-checklist.md` depends on those exact fragments.
- Build documentation with `python -m mkdocs build --strict` using `mkdocs.yml`; GitLab installs `requirements-docs.txt` and runs this separately from custom validation in `.gitlab-ci.yml`. MkDocs is unavailable in the inspected local Python environment, so install the declared dependencies before attempting the local build.
- `.markdownlint.json` enables default Markdownlint rules while disabling `MD013`, `MD024`, `MD033`, and `MD041`. No Markdownlint dependency or invocation exists in `requirements-docs.txt`, `scripts/validate-docs.sh`, or `.gitlab-ci.yml`; treat the config as editor guidance, not an enforced CI gate.
- No PHP/TypeScript formatter or static-analysis implementation is present. `phpstan`, `pest`, `eslint`, `vitest`, and `tsc` appear only as a future R1 exit gate in `docs/plans/release-1-platform.md`.

## Documentation Workflow

**Metadata and inventory:**
- Register every file beneath `docs/` exactly once in `docs/catalog.yaml`; `scripts/validate-docs.sh` checks complete, non-orphaned coverage, including `docs/catalog.yaml` itself.
- Register every Markdown file beneath `docs/` exactly once in the `nav` tree in `mkdocs.yml`; `scripts/validate-docs.sh` rejects missing, duplicate, orphaned, and nonexistent entries.
- Keep catalog `title`, `status`, and `owner` synchronized with Markdown front matter; these fields are cross-checked by `scripts/validate-docs.sh`.
- Use current repo-relative paths beginning with `docs/` in front-matter `sources` and `references`; use relative links in Markdown bodies, following `CONTRIBUTING.md` and `docs/governance/document-control.md`.
- Treat fragment IDs as public documentation contracts: update the source heading anchor and every dependent `sources`, `references`, or body link in the same change. `scripts/validate-docs.sh` validates front-matter fragments separately from body links.
- Use roles rather than personal names in `owner` and `reviewers`, and provide at least two distinct reviewer roles; `scripts/validate-docs.sh` enforces list shape and cardinality.
- Use ISO `YYYY-MM-DD` dates, SemVer document versions, allowed statuses, and canonical classifications from `docs/governance/document-control.md`; `scripts/validate-docs.sh` enforces these values.

**CI:**
- Keep project CI in GitLab syntax at `.gitlab-ci.yml`. The implemented pipeline has only `validate` and `build` stages.
- `validate-docs` installs `requirements-docs.txt` into `python:3.12-slim` and runs `scripts/validate-docs.sh`; `build-docs` runs strict MkDocs and publishes `site/` for one week (`.gitlab-ci.yml`).
- `validate-mermaid` runs only when `MERMAID_IMAGE` is set, verifies `mmdc`, and renders into `/tmp/validated-diagrams` (`.gitlab-ci.yml`). Do not assume Mermaid validation is unconditional.
- Keep CI jobs interruptible as configured in `.gitlab-ci.yml`; do not log secrets, a documented future pipeline rule in `docs/engineering/ci-cd-and-release.md`.
- Do not describe the five-stage product pipeline in `docs/engineering/ci-cd-and-release.md` as implemented. The repository CI currently lacks the documented `test`, `package`, `verify`, and `publish` stages.

## Import Organization

**Order:**
1. Import Python standard-library modules first, grouped together as in the embedded Python block in `scripts/validate-docs.sh`.
2. Import third-party modules after standard-library imports; `yaml` from PyYAML is the only such import in `scripts/validate-docs.sh`.
3. No project Python modules or application imports exist.

**Path Aliases:**
- Not detected. `scripts/validate-docs.sh` uses `pathlib.Path` and repo-relative paths; no PHP, TypeScript, or Python package alias configuration exists.

## Error Handling

**Patterns:**
- Start shell scripts with `#!/usr/bin/env bash` and `set -euo pipefail`, following both files under `scripts/`.
- Quote shell expansions, use `printf` for diagnostics, and send errors to stderr, following `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`.
- Validate required tools explicitly. `scripts/validate-docs.sh` exits `2` when Python or PyYAML is unavailable; distinguish setup failure from validation failure (`1`).
- Aggregate independent documentation errors in the Python `errors` list, print every finding, then exit once, following `scripts/validate-docs.sh`; do not fail at the first content defect.
- Use `try/finally`-equivalent shell cleanup with `trap`, as `scripts/render-diagrams.sh` does for its temporary staging directory.
- Preserve the current optional-tool policy deliberately: `scripts/render-diagrams.sh` returns success when `mmdc` is absent. Callers requiring Mermaid validation must first assert `command -v mmdc`, as `.gitlab-ci.yml` does.

## Logging

**Framework:** console output only

**Patterns:**
- Prefix validation failures with `ERROR:` and write them to stderr, following `scripts/validate-docs.sh`.
- Print one final pass/fail summary from `scripts/validate-docs.sh`; do not emit sensitive document contents or environment values.
- Keep rendering messages operational and path-oriented, following `scripts/render-diagrams.sh`.
- No application logging framework exists; observability in `docs/engineering/ci-cd-and-release.md` and `docs/architecture/non-functional-requirements.md` is a future requirement only.

## Comments

**When to Comment:**
- Use comments to identify configuration intent, such as the generated-output and local-tool sections in `.gitignore`, rather than narrating obvious commands.
- Keep shell comments sparse; script behavior is expressed through small named variables and direct control flow in `scripts/*.sh`.
- In documentation, explain constraints and rationale through headings, lists, tables, and ADR sections rather than inline source comments; use `docs/adr/template.md` for decisions.

**JSDoc/TSDoc:**
- Not applicable. No implemented JavaScript or TypeScript product source exists.
- Python docstrings are not used in the embedded validator. Keep helper names and type annotations explicit if extending `scripts/validate-docs.sh`.

## Function Design

**Size:**
- Keep validator helpers focused on one operation: YAML loading, path rendering, front-matter parsing, anchor extraction, link validation, or navigation traversal, following `scripts/validate-docs.sh`.
- Move reusable validation logic out of the inline Python block if it acquires an independent test suite; currently all Python validation is embedded in `scripts/validate-docs.sh`.

**Parameters:**
- Pass paths and caches explicitly, as in `validate_link(source, raw_target, anchor_cache)` in `scripts/validate-docs.sh`.
- Use `pathlib.Path` rather than concatenated path strings in Python, following `scripts/validate-docs.sh`.

**Return Values:**
- Return parsed values or `None` for recoverable parse errors and record diagnostics centrally, following `load_yaml()` and `parse_frontmatter()` in `scripts/validate-docs.sh`.
- Use process exit codes for command contracts: success `0`, content validation failure `1`, and missing validation prerequisites `2` in `scripts/validate-docs.sh`.

## Module Design

**Exports:**
- Not applicable to product code. The repository has no importable application modules.
- Treat each governed Markdown file as the owner of one topic and link to it instead of duplicating content, as required by `docs/governance/document-control.md`.
- Keep machine-readable contracts separate by concern under `docs/contracts/api/`, `docs/contracts/events/`, and `docs/contracts/schemas/`.

**Barrel Files:**
- Use section `README.md` files as human navigation indexes, not code barrels; examples are `docs/engineering/README.md` and `docs/architecture/README.md`.
- Use `docs/catalog.yaml` for complete machine-readable inventory and `mkdocs.yml` for publication navigation.

---

*Convention analysis: 2026-07-15*
