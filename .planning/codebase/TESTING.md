# Testing Patterns

**Analysis Date:** 2026-07-15

## Current Test Scope

- The implemented verification surface is documentation validation only: `scripts/validate-docs.sh`, strict MkDocs build configuration in `mkdocs.yml`, and Mermaid rendering through `scripts/render-diagrams.sh`.
- No Laravel, React, or delivery-controller source is present, and no executable product test suite exists. There is no root `composer.json`, product `package.json`, `phpunit.xml`, Vitest/Jest configuration, Playwright/Cypress configuration, or checked-in `tests/` tree.
- Product test requirements in `docs/engineering/testing-strategy.md`, `docs/architecture/non-functional-requirements.md`, and `docs/plans/*.md` are expectations for future implementation. They are not evidence of tests, coverage, CI gates, fixtures, or passing behavior in this checkout.
- `.planning/archive/` is not an authoritative source for current testing expectations; use active documents registered in `docs/catalog.yaml`.

## Test Framework

**Runner:**
- Custom Bash + embedded Python validator - `scripts/validate-docs.sh`; it syntax-checks shell files and validates the governed documentation set.
- MkDocs strict build - configured by `mkdocs.yml` and `.gitlab-ci.yml`, but not runnable in the inspected local Python environment because MkDocs is not installed. Repository dependencies are declared only as ranges in `requirements-docs.txt`.
- Mermaid CLI (`mmdc`) 11.16.0 - diagram parser/renderer invoked by `scripts/render-diagrams.sh`; all eight current sources under `docs/architecture/diagrams/` render successfully. GitLab runs this check only when `MERMAID_IMAGE` is configured in `.gitlab-ci.yml`, and the repository does not pin the CLI version.
- Product test runner: Not detected. Pest is named only in the future exit gate at `docs/plans/release-1-platform.md`; no Pest/PHPUnit installation or configuration exists.
- Frontend test runner: Not detected. Vitest is named only in `docs/plans/release-1-platform.md`; no product `package.json` or Vitest configuration exists.
- Config: `.gitlab-ci.yml`, `mkdocs.yml`, `.markdownlint.json`, and `.editorconfig`. `.markdownlint.json` is not wired to a runner.

**Assertion Library:**
- None. `scripts/validate-docs.sh` records explicit diagnostics in an `errors` list and exits nonzero rather than using a test assertion library.

**Run Commands:**
```bash
python -m pip install -r requirements-docs.txt  # Install documentation build/validation dependencies
./scripts/validate-docs.sh                      # Validate shell syntax and governed documentation
python -m mkdocs build --strict                 # Build the documentation site with warnings as failures
./scripts/render-diagrams.sh                    # Render Mermaid locally when mmdc is available
./scripts/render-diagrams.sh /tmp/diagrams      # Render into an explicit validation directory
```

- The install and validation commands are documented in `README.md`; contributor review requires `./scripts/validate-docs.sh` in `CONTRIBUTING.md`.
- There are no all-tests, watch-mode, or coverage commands for product code because no product test framework is installed.

## Implemented Validation Behavior

**Shell syntax:**
- `scripts/validate-docs.sh` runs `bash -n` against every `scripts/*.sh` file before content validation.

**Structured files:**
- `scripts/validate-docs.sh` parses `.gitlab-ci.yml`, `mkdocs.yml`, and all `docs/**/*.yaml`/`docs/**/*.yml` files with PyYAML.
- `scripts/validate-docs.sh` parses repository JSON except files below `.opencode`; this covers `.markdownlint.json` and `docs/contracts/schemas/*.json` syntactically.
- The validator does not perform OpenAPI, AsyncAPI, or JSON Schema semantic/meta-schema validation for `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, or `docs/contracts/schemas/*.schema.json`.

**Document governance:**
- `scripts/validate-docs.sh` requires front matter on root `README.md` and all `docs/**/*.md` files.
- `scripts/validate-docs.sh` checks the twelve required metadata fields, allowed types/statuses/classifications, stable document-ID syntax and uniqueness, SemVer, ISO dates, at least two distinct reviewer roles, and repo-local `sources`/`references` paths.
- `scripts/validate-docs.sh` permits extra front-matter fields only for ADR documents, matching `docs/adr/template.md`.
- `scripts/validate-docs.sh` cross-checks catalog `title`, `status`, and `owner` against front matter.
- `scripts/validate-docs.sh` does not enforce the required append-only change-log section from `docs/governance/document-control.md`, owner/reviewer role matrices, review-cycle vocabulary, catalog category/phase/source-of-truth fields, or version-to-change-log consistency.

**Links and inventory:**
- `scripts/validate-docs.sh` checks Markdown and HTML file links, Markdown fragments, raw `docs/...` references, and front-matter reference fragments.
- `scripts/validate-docs.sh` requires every Markdown document exactly once in the `mkdocs.yml` navigation and every file beneath `docs/` exactly once in `docs/catalog.yaml`.
- `scripts/validate-docs.sh` rejects orphaned catalog/navigation entries, empty documentation directories, the deprecated `doc/` path, unfinished `TODO`/`TBD`/`FIXME` markers, forbidden `Requests` module declarations, rendered images under `docs/`, and `.DS_Store` files.

**Publication and diagrams:**
- `.gitlab-ci.yml` runs custom validation and strict MkDocs build as separate jobs.
- `.gitlab-ci.yml` stores `site/` as a one-week artifact even on build failure.
- `.gitlab-ci.yml` makes Mermaid checking conditional on `MERMAID_IMAGE`; `scripts/render-diagrams.sh` itself exits successfully when `mmdc` is absent.
- `scripts/render-diagrams.sh` renders all `docs/architecture/diagrams/*.mmd` files through a temporary staging directory and replaces output SVGs only after successful rendering.

## Current Verification Results

- `./scripts/validate-docs.sh` currently exits `0` with `Documentation validation passed.` This verifies shell syntax plus the governed documentation, references, fragments, catalog, and navigation checks implemented in `scripts/validate-docs.sh`.
- `./scripts/render-diagrams.sh <temporary-output-directory>` currently exits `0` with Mermaid CLI 11.16.0 after rendering all eight files in `docs/architecture/diagrams/`, including `docs/architecture/diagrams/document-sequence.mmd`.
- `python3 -m mkdocs --version` currently exits nonzero with `No module named mkdocs`; therefore no local strict MkDocs result is claimed. Install `requirements-docs.txt` from the approved internal source before running the strict build described by `README.md` and `mkdocs.yml`.
- `.gitlab-ci.yml` installs `requirements-docs.txt` before its strict MkDocs job, but no local pipeline result is present in this checkout. Treat the committed job as configured verification, not evidence of a current pass.
- Documentation validation and Mermaid rendering are green on the current content. Mermaid enforcement in GitLab remains conditional on `MERMAID_IMAGE`, so `.gitlab-ci.yml` can still omit that check when the variable is absent.

## Test File Organization

**Location:**
- No `*.test.*`, `*.spec.*`, `test_*.py`, PHPUnit/Pest tests, or dedicated test directories are present.
- Documentation validation logic is embedded in `scripts/validate-docs.sh`, not organized as independently runnable unit tests.
- Mermaid fixtures are the production diagram sources under `docs/architecture/diagrams/*.mmd`; there are no isolated valid/invalid fixture cases.

**Naming:**
- No implemented test-file naming convention can be inferred.
- Future acceptance-test IDs use `TEST-<REQ-ID>-<number>` by documentation policy in `docs/plans/implementation-roadmap.md`; this is a traceability convention, not a file naming implementation.

**Structure:**
```text
scripts/
├── validate-docs.sh          # Shell syntax checks plus inline Python validation
└── render-diagrams.sh        # Optional Mermaid rendering

docs/architecture/diagrams/
└── *.mmd                     # Production Mermaid inputs, not test fixtures

# No product tests/ directory is present.
```

## Test Structure

**Suite Organization:**
```bash
for script in scripts/*.sh; do
  bash -n "$script"
done

python3 - <<'PY'
errors: list[str] = []
# Run all documentation checks, aggregate diagnostics, then exit once.
PY
```

- The actual pattern is a single end-to-end validation command in `scripts/validate-docs.sh`; checks are not registered as named test cases.
- Validation helpers such as `parse_frontmatter()`, `markdown_anchors()`, and `validate_link()` in `scripts/validate-docs.sh` are pure enough to unit test but are not importable from a test module while embedded in shell.

**Patterns:**
- Setup pattern: resolve the repository root from `BASH_SOURCE`, change to it, and validate from a stable working directory (`scripts/validate-docs.sh`).
- Teardown pattern: create a Mermaid staging directory with `mktemp` and remove it through `trap` (`scripts/render-diagrams.sh`).
- Assertion pattern: call `add_error()`, aggregate all failures, print each with `ERROR:`, and raise `SystemExit(1)` at the end (`scripts/validate-docs.sh`).
- Prerequisite pattern: use exit `2` for missing Python/PyYAML (`scripts/validate-docs.sh`); Mermaid absence is treated as a successful skip (`scripts/render-diagrams.sh`).

## Mocking

**Framework:** Not used

**Patterns:**
```text
No mocks, stubs, spies, fake clock implementation, HTTP interception, database isolation, or process fakes are present.
```

**What to Mock:**
- No current code-level mocking guidance is implemented.
- When product tests exist, inject a controllable Clock and use isolated fixtures rather than system time, shared data, or external networks, as required by `docs/engineering/testing-strategy.md`.
- Replace external object storage, queues, scanning, and network boundaries with contract-faithful local adapters in unit/application tests; retain real internal components for contract and E2E verification according to the layers in `docs/engineering/testing-strategy.md`.

**What NOT to Mock:**
- Do not mock domain invariants, authorization decisions, transaction/outbox atomicity, event schema compatibility, or module-boundary guards; these are the behaviors explicitly required by `docs/engineering/testing-strategy.md` and `docs/engineering/coding-and-module-boundaries.md`.
- Do not use mocks as evidence for recovery, performance, air-gap, or browser-to-database acceptance. Those requirements need environment-level proof under `docs/architecture/non-functional-requirements.md` and `docs/plans/release-1-platform.md`.

## Fixtures and Factories

**Test Data:**
```text
Not implemented. The validator runs directly against the live repository documentation tree.
```

**Location:**
- No fixture/factory directory exists.
- Future deterministic tests are required to use isolated fixtures and a controlled Clock by `docs/engineering/testing-strategy.md`, but no concrete PHP/TypeScript fixture pattern or path is selected.
- Future slice-local tests are documented under `Modules/<Module>/Features/<BusinessVerb>/Tests` in `docs/engineering/vertical-slices.md`; that directory is a target shape and is absent.

## Coverage

**Requirements:**
- Current documentation validation has no line, branch, mutation, or changed-lines coverage measurement. No coverage tool or artifact is configured in `.gitlab-ci.yml`.
- The draft future strategy requires at least 80% line coverage of changed product lines, with generated files, diagrams, and pure DDL migrations excludable by reviewer approval (`docs/engineering/testing-strategy.md`).
- The draft future strategy requires at least an 80% mutation score for changed critical files and blocks mutants that remove authorization, invariants, state transitions, or classification constraints (`docs/engineering/testing-strategy.md`).
- Neither future threshold is implemented or enforced by the current two-stage pipeline in `.gitlab-ci.yml`.

**View Coverage:**
```bash
# Not available: no product coverage command or report is configured.
```

## Test Types

**Unit Tests:**
- Current: Not used. The helper functions embedded in `scripts/validate-docs.sh` have no direct unit tests.
- Future documented scope: Domain invariants, Value Objects, transitions, and edge cases in `docs/engineering/testing-strategy.md`.

**Application Tests:**
- Current: Not used.
- Future documented scope: Handler behavior, authorization, owner-led transaction boundaries, Outbox atomicity, and consumer idempotency in `docs/engineering/testing-strategy.md`.

**Contract Tests:**
- Current: Not used. Contract files exist under `docs/contracts/`, but custom validation checks only YAML/JSON syntax and references.
- Future documented scope: every synchronous contract plus event schema/compatibility, with provider and consumer boundary enforcement in `docs/engineering/testing-strategy.md` and `docs/architecture/dependency-rules.md`.

**Architecture Tests:**
- Current: Not used.
- Future documented scope: dependency DAG, forbidden imports, table ownership, cross-module SQL/JOIN prevention, derived-store write prevention, and contract/event test presence in `docs/engineering/coding-and-module-boundaries.md`.

**Integration Tests:**
- Current: strict MkDocs build integrates navigation, extensions, and content through `mkdocs.yml`; Mermaid CLI integrates all diagram sources through `scripts/render-diagrams.sh`.
- Future documented scope: database migrations against an empty database and representative previous release, idempotent reruns where required, and restore verification in `docs/engineering/database-migrations.md`.

**E2E Tests:**
- Current: No browser, API, queue, database, or product E2E framework exists.
- Future documented scope: at least one end-to-end scenario per business module and critical UI-to-persistence-to-queue/projection journeys in `docs/plans/implementation-roadmap.md` and `docs/engineering/testing-strategy.md`.
- The future R1 walking skeleton names Pest, Vitest, PHPStan, ESLint, and TypeScript checks and one staging E2E in `docs/plans/release-1-platform.md`; none are installed here.

**Security Tests:**
- Current: `CONTRIBUTING.md` and `SECURITY.md` prohibit secrets and sensitive data, but `scripts/validate-docs.sh` does not implement secret detection; no secret scanner, SAST, dependency scan, IDOR test, or authorization harness is configured in `.gitlab-ci.yml`.
- Future documented scope: deny-by-default, organizational scope, classification, field access, delegation, IDOR, malicious input, sessions, and negative API/search/report/export/download cases in `docs/engineering/testing-strategy.md` and `docs/architecture/non-functional-requirements.md`.

**Performance and Recovery Tests:**
- Current: Not used.
- Future documented scope: load baselines for critical slices, release validation for 2,000 concurrent users, actual isolated restores, `RPO <= 15 minutes`, and `RTO <= 2 hours` in `docs/engineering/testing-strategy.md` and `docs/architecture/non-functional-requirements.md`.

**Usability and Localization Tests:**
- Current: Not used.
- Future documented scope: each role, screen size, and UI direction across Arabic-default bilingual RTL/LTR behavior in `docs/architecture/non-functional-requirements.md`.

## Common Patterns

**Async Testing:**
```text
Future requirement only: commit business state and Outbox atomically, deliver at least once,
replay the same event, and assert one idempotent consumer effect.
```
- The required behavior is documented in `docs/engineering/testing-strategy.md`, `docs/architecture/dependency-rules.md`, and module examples such as `docs/domain/collaboration-tasks-workspace.md`; no executable async test exists.

**Error Testing:**
```python
errors: list[str] = []

def add_error(message: str) -> None:
    errors.append(message)

if errors:
    for error in errors:
        print(f"ERROR: {error}", file=sys.stderr)
    raise SystemExit(1)
```
- This is the implemented aggregate-failure pattern in `scripts/validate-docs.sh`.
- Future product slices require both authorization-failure and invariant-failure tests before a slice is complete, as stated in `docs/engineering/vertical-slices.md`.

## Test Gaps and Prescriptive Guidance

- Add regression tests around `scripts/validate-docs.sh` before expanding its rules. Extract the Python validator into an importable module and cover valid/invalid front matter, duplicate IDs, Arabic anchors, duplicate headings, escaped paths, nav/catalog mismatches, and forbidden module wording; no such tests exist today.
- Add semantic contract validation for `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, and `docs/contracts/schemas/*.schema.json`; YAML/JSON parsing in `scripts/validate-docs.sh` cannot detect invalid OpenAPI/AsyncAPI operations or broken schema references.
- Wire `.markdownlint.json` into an explicit dependency and CI command if Markdown style is intended to block merges. At present neither `requirements-docs.txt` nor `.gitlab-ci.yml` runs it.
- Make Mermaid validation unconditional for protected branches or explicitly record it as skipped. The conditional `MERMAID_IMAGE` rule in `.gitlab-ci.yml` and success-on-missing behavior in `scripts/render-diagrams.sh` permit diagram syntax defects to escape the gate.
- Keep regression coverage for explicit release-wave anchors referenced by `docs/plans/readiness-checklist.md`. The current fragments pass `scripts/validate-docs.sh`, but the validator itself has no fixture-based unit test proving duplicate, Arabic, explicit, and renamed-anchor behavior.
- Add an isolated Mermaid regression for `docs/architecture/diagrams/document-sequence.mmd`. All current diagrams render successfully, but the repository has no fixture-based test that would localize a future syntax regression to the changed source.
- Install `requirements-docs.txt` in the local verification environment and run `python -m mkdocs build --strict` before making claims about current MkDocs output; MkDocs is currently unavailable on the inspected host.
- Enforce the governance rules not covered by `scripts/validate-docs.sh`, especially the required change-log section from `docs/governance/document-control.md`, review-cycle vocabulary, owner/reviewer matrices, and all relevant catalog metadata.
- Pin and hash documentation dependencies for reproducibility. `requirements-docs.txt` currently uses broad version ranges and `.gitlab-ci.yml` performs a plain pip install.
- Introduce product runners, configuration, commands, fixtures, and CI stages only when Laravel/React source is scaffolded. Keep documented test targets in `docs/engineering/testing-strategy.md` labeled as expectations until executable evidence exists.
- Trace each future acceptance test to its requirement using the ID policy in `docs/plans/implementation-roadmap.md`; do not claim plan checklist text is an executed test result.

---

*Testing analysis: 2026-07-15*
