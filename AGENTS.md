<!-- GSD:project-start source:PROJECT.md -->

## Project

**منصة التجمع الصحي الثالث**

منصة إدارية داخلية موحدة للتجمع الصحي الثالث ومنشآته، تستبدل الأعمال المتفرقة عبر البريد وExcel والورق بسجلات رقمية محكومة وقابلة للبحث والقياس والتدقيق. تخدم الموظفين والمديرين ومسؤولي التجمع ومالكي الاستراتيجية والمشاريع والمخاطر من واجهة عربية افتراضياً تدعم الإنجليزية وRTL/LTR، وتُسلّم عبر ثلاثة إصدارات مترابطة: المنصة العامة R1، الاستراتيجية والمحافظ R2، والمخاطر المؤسسية R3.

**Core Value:** تمكين المستخدم من إتمام عمل إداري مؤسسي كامل داخل سجل رقمي آمن وقابل للتتبع، مع عزل تنظيمي وقرار وصول مُفسّر، دون العودة إلى البريد أو الملفات المتفرقة.

### Constraints

- **Architecture**: Laravel modular monolith بحدود موديولات صارمة، vertical slices، light DDD/CQRS، وقاعدة MySQL تشغيلية واحدة — لتقليل التعقيد مع الحفاظ على الملكية والاستقلال.
- **Frontend**: تطبيق React + TypeScript موحد لكل الأدوار، عربي افتراضياً مع إنجليزية كاملة وRTL/LTR — لا تُنشأ واجهات منفصلة للإدارة والمستخدمين.
- **Authorization**: RBAC + ABAC مركزي ومُفسّر في الخلفية؛ إخفاء عناصر React ليس حداً أمنياً — يجب تطبيق القرار نفسه على API والبحث والتقارير والتصدير والتنزيل.
- **Module boundaries**: لا استعلامات أو joins مباشرة بين جداول موديولات الأعمال، ولا تسرب ORM أو Infrastructure — التعاون فقط عبر contracts وevents وIDs وread models المحكومة.
- **Consistency**: كل تغيير أعمال وOutbox event يُحفظان في transaction واحدة، والمستهلكون idempotent — لا تمتد المعاملة إلى عامل أو خدمة خارجية.
- **Versioning**: إصدارات أنواع الأعمال والمسارات المنشورة غير قابلة للتعديل، والسجلات الجارية تبقى مثبتة على إصدارها — لا ترحيل صامت.
- **Air gap**: لا إنترنت أو CDN أو SaaS أو اعتماد وقت التشغيل على مصادر عامة — جميع الحزم والصور والخدمات داخلية.
- **Security and compliance**: تصنيف بيانات رباعي، تشفير PII، تدقيق غير قابل للتعديل، SBOM وصور موقعة، وضوابط PDPL وNCA ECC وNDMO — أي فشل أمني يمنع الإصدار.
- **Recovery**: RPO لا يتجاوز 15 دقيقة وRTO لا يتجاوز ساعتين، مع نسخ مشفر مستقل عن Kubernetes واختبارات استعادة دورية.
- **Performance**: قراءات R1 عند P95 ≤ 1.5s، لوحات R2 وتقييم R3 عند P95 ≤ 2s، ودعم 2,000 مستخدم متزامن.
- **Delivery**: لا يبدأ إصدار جديد قبل اجتياز بوابة السابق إلا بقرار صريح من راعي المنصة؛ كل متطلب واختبار وانحراف قابل للتتبع بمعرف.
- **Governance**: القرارات المؤجلة في خارطة التنفيذ تتحول إلى بوابات حسم في المرحلة التي تسبق اعتمادها، ولا تُفترض كقرارات نهائية بلا توثيق.

<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->

## Technology Stack

## Current Repository Status

- The current product artifact is an Arabic-first documentation and contract repository under `docs/`; no Laravel application tree (`app/`), React source tree (`resources/js/`), root `composer.json`, root `package.json`, container definition, Helm chart, or Kubernetes manifest is present.
- Treat `docs/README.md` and `docs/catalog.yaml` as the active documentation index and catalog. Historical material under `.planning/archive/` is not part of this analysis.
- The accepted target is a Laravel modular monolith plus one React + TypeScript web application in a monorepo, but framework versions and application dependencies remain unselected in `docs/architecture/overview.md`.
- The executable repository surface consists of documentation validation and diagram scripts in `scripts/`, a documentation-only GitLab pipeline in `.gitlab-ci.yml`, and local agent tooling under `.opencode/`.

## Languages

- Markdown - Product, architecture, security, domain, operations, engineering, plan, and ADR sources of truth under `docs/`, with the repository entry point in `README.md`.
- YAML - Documentation catalog and site/CI configuration in `docs/catalog.yaml`, `mkdocs.yml`, and `.gitlab-ci.yml`; API and event contracts in `docs/contracts/api/openapi.yaml` and `docs/contracts/events/asyncapi.yaml`.
- JSON / JSON Schema Draft 2020-12 - Versioned contract schemas in `docs/contracts/schemas/*.schema.json` and markdown lint configuration in `.markdownlint.json`.
- Bash - Documentation validation and Mermaid rendering in `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`.
- Python 3 - An embedded validation program inside `scripts/validate-docs.sh`; it uses the standard library plus `yaml`/PyYAML.
- Mermaid - Editable C4, deployment, and sequence diagrams in `docs/architecture/diagrams/*.mmd`.
- JavaScript (CommonJS) - Local OpenCode/GSD automation under `.opencode/plugins/`, `.opencode/hooks/`, `.opencode/scripts/`, and `.opencode/gsd-core/`; it is development tooling, not product application code.
- PHP - Selected for the future Laravel backend in `docs/architecture/overview.md`; no product PHP source or Composer manifest exists.
- TypeScript - Selected for the future React frontend in `docs/adr/009-unified-react-shell.md`; no product TypeScript source or TypeScript manifest exists.

## Runtime

- GitLab documentation jobs declare Python 3.12 through `DOCS_PYTHON_IMAGE: "python:3.12-slim"` in `.gitlab-ci.yml`.
- The inspected workstation provides Python 3.9.6 and PyYAML 6.0.3; MkDocs is not installed locally. These host versions are not pinned by repository runtime files.
- The inspected workstation provides Mermaid CLI 11.16.0 and Bash 3.2.57 for `scripts/render-diagrams.sh` and `scripts/validate-docs.sh`; repository files do not pin either version.
- Node.js 22.22.2 and npm 11.18.0 are available for local `.opencode/` tooling. `.opencode/package.json` requires only CommonJS mode and `@opencode-ai/plugin` 1.18.1.
- PHP 8.5.7 and Composer 2.10.1 are installed on the workstation, but they are not project runtimes because the root has no `composer.json`, `composer.lock`, `artisan`, or PHP source.
- The future production runtime is on-premises, air-gapped Kubernetes with replicated Laravel Web/API and worker processes, as specified in `docs/architecture/overview.md` and `docs/operations/kubernetes-platform.md`.
- pip - Documentation dependencies are range-constrained, not locked, in `requirements-docs.txt`: `mkdocs>=1.6,<2` and `mkdocs-material>=9.6,<10`.
- npm - Used only for local `.opencode/` agent tooling. `.opencode/package-lock.json` is lockfile v3 and pins `@opencode-ai/plugin` 1.18.1 plus transitive dependencies.
- Composer - Required by the selected Laravel direction in `docs/governance/assumptions-constraints.md`, but no product Composer manifest or lockfile exists.
- Product lockfile: missing. No root Composer, npm, Python, container-image, or deployment lock is present; `.opencode/package-lock.json` is tooling-only and is ignored by `.opencode/.gitignore`.

## Frameworks

- MkDocs `>=1.6,<2` - Builds the active documentation site from `docs/` according to `mkdocs.yml` and `requirements-docs.txt`.
- Material for MkDocs `>=9.6,<10` - Arabic documentation theme selected in `mkdocs.yml` and declared in `requirements-docs.txt`.
- Laravel, version not pinned - Accepted target backend and modular-monolith boundary in `docs/architecture/overview.md` and `docs/adr/001-modular-monolith.md`; not implemented.
- React + TypeScript, versions not pinned - Accepted target unified frontend in `docs/adr/009-unified-react-shell.md`; not implemented.
- Custom Bash/Python documentation validator - `scripts/validate-docs.sh` checks shell syntax, YAML, JSON, front matter, links/fragments, catalog coverage, MkDocs navigation, unfinished markers, and prohibited artifacts.
- No executable product test framework is installed because application source and manifests are absent. The accepted roadmap expects Pest tests and architecture/contract gates in `docs/plans/implementation-roadmap.md`.
- The current documentation validator passes: `./scripts/validate-docs.sh` completed successfully on 2026-07-15, including the front-matter fragments from `docs/plans/readiness-checklist.md` to the explicit `#w1-10`, `#w2-7`, and `#w3-7` anchors in `docs/plans/release-1-platform.md`, `docs/plans/release-2-strategy-portfolio.md`, and `docs/plans/release-3-risk.md`.
- A local strict MkDocs build was not run because the inspected workstation does not have MkDocs installed; `.gitlab-ci.yml` remains the implemented `python -m mkdocs build --strict` gate.
- GitLab CI - The implemented pipeline in `.gitlab-ci.yml` has `validate-docs`, `build-docs`, and conditional `validate-mermaid` jobs only.
- MkDocs strict build - `.gitlab-ci.yml` runs `python -m mkdocs build --strict` and publishes the generated `site/` directory for one week.
- Mermaid CLI (`mmdc`) - `scripts/render-diagrams.sh` converts `docs/architecture/diagrams/*.mmd` into generated SVG files under `build/diagrams/`; `build/` is ignored by `.gitignore`.
- OpenCode plugin API and GSD Core 1.7.0 - Local development workflow tooling is configured by `.opencode/opencode.json`, `.opencode/package.json`, and `.opencode/gsd-core/VERSION`.
- PHPStan, Laravel Pint, Pest, frontend build tooling, SBOM generation, image signing, and air-gap verification are future delivery gates recorded in `docs/plans/implementation-roadmap.md`; no runnable commands or manifests implement them yet.

## Key Dependencies

- PyYAML - Imported directly as `yaml` by `scripts/validate-docs.sh`; it is currently available locally as 6.0.3 but is not declared directly in `requirements-docs.txt`.
- MkDocs and Material for MkDocs - The only declared repository-level dependencies in `requirements-docs.txt`; exact versions are not locked.
- OpenAPI 3.1, AsyncAPI 3.1, and JSON Schema 2020-12 - Contract formats stored in `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, and `docs/contracts/schemas/`.
- `@opencode-ai/plugin` 1.18.1 - The only direct npm dependency in `.opencode/package.json`; it is local development tooling and not a product dependency.
- MySQL InnoDB Cluster, version not selected - Required future operational source of truth and HA database in `docs/operations/kubernetes-platform.md`.
- Valkey HA, version not selected - Required future cache, queues, and Valkey-compatible Streams transport in `docs/operations/kubernetes-platform.md` and `docs/contracts/events/asyncapi.yaml`.
- S3-compatible object storage or MinIO, product/version not selected - Required future document and backup storage in `docs/operations/ha-dr-backup.md`.
- OpenSearch, version not selected - Named target for user-facing derived search in `docs/operations/kubernetes-platform.md`; it is not a source of truth.
- Loki, version not selected - Named target for internal operational logs in `docs/operations/kubernetes-platform.md`.
- Vault, version not selected - Named target for secrets and internal PKI in `docs/operations/kubernetes-platform.md`.
- Kubernetes or fallback RKE2, distribution/version not finalized - The internally managed platform is preferred; three-control-plane-node RKE2 is the documented fallback in `docs/operations/kubernetes-platform.md`.
- GitLab, Harbor, Nexus, and a GitOps controller - Required internal platform roles in `docs/operations/air-gap-supply-chain.md`; repository documentation explicitly does not assert that concrete services are provisioned.

## Configuration

- No `.env` or `.env.*` file is present under the repository root, and no product environment-variable contract exists because the application has not been scaffolded.
- `.gitlab-ci.yml` defines `DOCS_PYTHON_IMAGE` with a default image reference and conditionally consumes `MERMAID_IMAGE`; these are the only implemented CI environment controls relevant to the active product documentation.
- `.opencode/opencode.json` configures a local Node-based GSD MCP process; `.opencode/settings.json` and `.opencode/` settings are developer workflow configuration, not production configuration.
- Future environment-specific hosts, credentials, and secrets must remain outside these documents in governed configuration, as stated in `docs/operations/README.md`.
- `mkdocs.yml` defines `docs/` as input, `site/` as generated output, Arabic Material theme settings, strict navigation, and Markdown extensions.
- `.gitlab-ci.yml` defines the current documentation build and validation pipeline.
- `requirements-docs.txt` defines unpinned-range documentation dependencies.
- `.editorconfig` and `.markdownlint.json` define repository text and Markdown conventions; no formatter or linter package manifest installs markdownlint.
- No root Dockerfile, Compose file, Makefile, Helm/Kustomize configuration, Kubernetes manifest, Vite config, TypeScript config, Laravel config, or product CI stages are present.

## Platform Requirements

- Install Python and resolve `requirements-docs.txt` from an approved internal package source before running `./scripts/validate-docs.sh` or `mkdocs build --strict`, per `README.md`.
- Provide Mermaid CLI as the `mmdc` executable to validate/render `docs/architecture/diagrams/*.mmd` through `scripts/render-diagrams.sh`; the script exits successfully without rendering when the CLI is absent.
- Use Node/npm only when operating local `.opencode/` automation. Its lockfile currently records public npm registry URLs in `.opencode/package-lock.json`, so it is not evidence of an air-gap-compliant product supply chain.
- Add exact PHP/Laravel, Composer, Node/React/TypeScript, frontend bundler, test, and static-analysis versions when application scaffolding begins; current target documents such as `docs/architecture/overview.md` do not pin them.
- Deploy only inside the Third Health Cluster data center on internally managed, air-gapped Kubernetes; no runtime internet, CDN, public script/font, or SaaS dependency is allowed by `docs/governance/assumptions-constraints.md`.
- Resolve Composer/npm packages and base images through approved internal mirrors/registries; artifacts must be immutable, signed, tied to a digest, and accompanied by SBOM and provenance according to `docs/operations/air-gap-supply-chain.md`.
- Enforce default-deny egress, internal DNS, internal PKI, least-privilege service accounts, and GitOps-only writes to staging/production according to `docs/operations/kubernetes-platform.md`.
- Support 5,000-20,000 accounts and up to 2,000 concurrent users, with `RPO <= 15 minutes` and `RTO <= 2 hours`, as required by `docs/architecture/overview.md` and `docs/operations/ha-dr-backup.md`.
- Keep encrypted backups outside the Kubernetes failure domain and exercise restoration quarterly according to `docs/operations/ha-dr-backup.md`.

<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->

## Conventions

## Current Implementation Scope

- Treat this checkout as a documentation repository with validation automation, not as an implemented Laravel/React product. The executable project-owned code is `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`; there is no root `composer.json`, product `package.json`, `phpunit.xml`, `tsconfig.json`, or application source tree.
- Treat `docs/engineering/*.md` and `docs/plans/*.md` as documented expectations. They prescribe future product conventions but do not prove that product code, tools, or tests exist.
- Use `docs/` as the authoritative documentation source. Do not use `.planning/archive/` as current authority; the source-of-truth rule is stated in `docs/governance/document-control.md` and the active inventory is `docs/catalog.yaml`.
- The current governed documentation set passes `./scripts/validate-docs.sh`. Preserve that green baseline whenever changing `docs/`, `mkdocs.yml`, `.gitlab-ci.yml`, repository JSON, or `scripts/*.sh` (`scripts/validate-docs.sh`).
- All eight current Mermaid sources under `docs/architecture/diagrams/` render successfully through `scripts/render-diagrams.sh` with the locally available Mermaid CLI 11.16.0. Preserve that green baseline whenever changing a `.mmd` source or the renderer.
- A local strict MkDocs build is not currently runnable because MkDocs is absent from the inspected host Python environment. Install `requirements-docs.txt` from the approved internal source before using `python -m mkdocs build --strict`; treat this as a local setup prerequisite, not as a documentation-content failure (`README.md`, `requirements-docs.txt`).

## Naming Patterns

- Name documentation files with lowercase Latin `kebab-case` and `.md`, without spaces or Arabic characters, following `CONTRIBUTING.md` and `docs/governance/document-control.md`. Examples include `docs/engineering/testing-strategy.md` and `docs/data-security/authorization-model.md`.
- Keep section indexes named `README.md`, as in `docs/engineering/README.md` and `docs/contracts/README.md`; these uppercase names are deliberate index-file exceptions to ordinary document naming.
- Prefix accepted ADR files with a zero-padded sequence and a kebab-case decision name, such as `docs/adr/001-modular-monolith.md`; use `docs/adr/template.md` as the document shape.
- Name contract schemas `<subject>.schema.json`, such as `docs/contracts/schemas/access-decision.schema.json`; keep API/event descriptions at `docs/contracts/api/openapi.yaml` and `docs/contracts/events/asyncapi.yaml`.
- Name Mermaid sources in kebab-case with `.mmd` under `docs/architecture/diagrams/`, such as `docs/architecture/diagrams/document-sequence.mmd`. Do not add rendered `.svg` or `.png` beneath `docs/`; `scripts/validate-docs.sh` rejects them.
- Name repository scripts with kebab-case and `.sh`, as in `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`.
- Use `snake_case` for Python functions in the embedded validator, following `add_error()`, `parse_frontmatter()`, `markdown_anchors()`, `validate_link()`, and `walk_nav()` in `scripts/validate-docs.sh`.
- Use behavior-oriented function names that state the validation operation. Keep parsing, normalization, and checking in separate helpers as shown in `scripts/validate-docs.sh`.
- No implemented PHP or TypeScript function naming convention can be inferred because no product source exists. Do not present names in `docs/plans/` as implemented code.
- Use uppercase snake case for Python module constants such as `ROOT`, `DOCS`, `CATALOG`, `REQUIRED_FIELDS`, and `ALLOWED_STATUSES` in `scripts/validate-docs.sh`.
- Use lower snake case for Python locals and parameters such as `raw_target`, `anchor_cache`, and `markdown_paths` in `scripts/validate-docs.sh`.
- Use lower snake case for shell variables and mark stable values `readonly`, following `root` in `scripts/validate-docs.sh` and `source_dir`, `output_dir`, and `staging_dir` in `scripts/render-diagrams.sh`.
- Use canonical lowercase snake-case values in contracts where specified, such as `top_secret` in `docs/contracts/schemas/access-decision.schema.json`.
- No executable product types exist. Use documented PascalCase names only as design vocabulary—such as `WorkRecord`, `AccessDecision`, and `SubmitWorkRecord` in `docs/engineering/vertical-slices.md`—until application source is introduced.
- Name a future vertical slice with a business verb and outcome, not a horizontal service noun: `SubmitWorkRecord` or `ApproveMilestone`, not `RecordService`, as required by `docs/engineering/vertical-slices.md`.
- Preserve stable uppercase document identifiers such as `GOV-DC-001` and `ARC-EN-004`; the grammar and reservation rule are defined in `docs/governance/document-control.md` and enforced by `scripts/validate-docs.sh`.
- Preserve traceability identifiers in plans: `REQ-<release>-<wave>-<number>`, `TEST-<REQ-ID>-<number>`, and `DEF-<number>` are specified in `docs/plans/implementation-roadmap.md`. These are documentation contracts, not implemented test IDs.

## Code Style

- Use UTF-8, LF endings, a final newline, and no trailing whitespace by default according to `.editorconfig`; Markdown deliberately permits trailing whitespace in `.editorconfig`.
- Use two-space indentation for YAML, JSON, and shell according to `.editorconfig`. Existing Markdown front matter often uses unindented YAML sequence items; preserve valid local style when editing an existing document.
- Enforce LF normalization for Markdown, YAML, JSON, shell, and Mermaid files with `.gitattributes`.
- Write documentation primarily in Arabic and introduce essential English technical terms consistently with `docs/governance/glossary.md` and `docs/governance/document-control.md`.
- Start every Markdown file beneath `docs/`, plus root `README.md`, with the required metadata fields documented in `docs/governance/document-control.md`. Non-ADR documents may not add extra front-matter fields; ADR-only fields are represented by `docs/adr/template.md`.
- End governed documents with an append-only `## سجل التغيير` table as required by `docs/governance/document-control.md`. The current validator in `scripts/validate-docs.sh` does not enforce this section, so reviewers must check it.
- Prefer tables and numbered lists for requirements and decisions, and attach a measurable acceptance criterion to each requirement, following `docs/governance/document-control.md`.
- Keep Mermaid as text source in `docs/architecture/diagrams/*.mmd`. Write local render output to `build/diagrams/` through `scripts/render-diagrams.sh`; both `build/` and `site/` are ignored by `.gitignore`.
- Run `./scripts/validate-docs.sh` before review as required by `CONTRIBUTING.md`. It runs `bash -n` over `scripts/*.sh` and then executes the embedded Python validator in `scripts/validate-docs.sh`.
- The custom validator checks YAML and JSON parsing, required front matter, document ID uniqueness, allowed metadata values, SemVer and dates, reviewer roles, internal source/reference paths and fragments, Markdown/HTML links, raw `docs/...` references, unfinished markers, deprecated `doc/` references, forbidden `Requests` module declarations, navigation/catalog completeness, catalog metadata agreement, empty documentation directories, manual rendered diagrams under `docs/`, and `.DS_Store` files (`scripts/validate-docs.sh`).
- Keep explicit stable heading IDs when front matter or cross-document links target release-wave sections. The current working pattern is `### W1.10 ... {#w1-10}` in `docs/plans/release-1-platform.md`, with equivalent `{#w2-7}` and `{#w3-7}` anchors in `docs/plans/release-2-strategy-portfolio.md` and `docs/plans/release-3-risk.md`; `docs/plans/readiness-checklist.md` depends on those exact fragments.
- Build documentation with `python -m mkdocs build --strict` using `mkdocs.yml`; GitLab installs `requirements-docs.txt` and runs this separately from custom validation in `.gitlab-ci.yml`. MkDocs is unavailable in the inspected local Python environment, so install the declared dependencies before attempting the local build.
- `.markdownlint.json` enables default Markdownlint rules while disabling `MD013`, `MD024`, `MD033`, and `MD041`. No Markdownlint dependency or invocation exists in `requirements-docs.txt`, `scripts/validate-docs.sh`, or `.gitlab-ci.yml`; treat the config as editor guidance, not an enforced CI gate.
- No PHP/TypeScript formatter or static-analysis implementation is present. `phpstan`, `pest`, `eslint`, `vitest`, and `tsc` appear only as a future R1 exit gate in `docs/plans/release-1-platform.md`.

## Documentation Workflow

- Register every file beneath `docs/` exactly once in `docs/catalog.yaml`; `scripts/validate-docs.sh` checks complete, non-orphaned coverage, including `docs/catalog.yaml` itself.
- Register every Markdown file beneath `docs/` exactly once in the `nav` tree in `mkdocs.yml`; `scripts/validate-docs.sh` rejects missing, duplicate, orphaned, and nonexistent entries.
- Keep catalog `title`, `status`, and `owner` synchronized with Markdown front matter; these fields are cross-checked by `scripts/validate-docs.sh`.
- Use current repo-relative paths beginning with `docs/` in front-matter `sources` and `references`; use relative links in Markdown bodies, following `CONTRIBUTING.md` and `docs/governance/document-control.md`.
- Treat fragment IDs as public documentation contracts: update the source heading anchor and every dependent `sources`, `references`, or body link in the same change. `scripts/validate-docs.sh` validates front-matter fragments separately from body links.
- Use roles rather than personal names in `owner` and `reviewers`, and provide at least two distinct reviewer roles; `scripts/validate-docs.sh` enforces list shape and cardinality.
- Use ISO `YYYY-MM-DD` dates, SemVer document versions, allowed statuses, and canonical classifications from `docs/governance/document-control.md`; `scripts/validate-docs.sh` enforces these values.
- Keep project CI in GitLab syntax at `.gitlab-ci.yml`. The implemented pipeline has only `validate` and `build` stages.
- `validate-docs` installs `requirements-docs.txt` into `python:3.12-slim` and runs `scripts/validate-docs.sh`; `build-docs` runs strict MkDocs and publishes `site/` for one week (`.gitlab-ci.yml`).
- `validate-mermaid` runs only when `MERMAID_IMAGE` is set, verifies `mmdc`, and renders into `/tmp/validated-diagrams` (`.gitlab-ci.yml`). Do not assume Mermaid validation is unconditional.
- Keep CI jobs interruptible as configured in `.gitlab-ci.yml`; do not log secrets, a documented future pipeline rule in `docs/engineering/ci-cd-and-release.md`.
- Do not describe the five-stage product pipeline in `docs/engineering/ci-cd-and-release.md` as implemented. The repository CI currently lacks the documented `test`, `package`, `verify`, and `publish` stages.

## Import Organization

- Not detected. `scripts/validate-docs.sh` uses `pathlib.Path` and repo-relative paths; no PHP, TypeScript, or Python package alias configuration exists.

## Error Handling

- Start shell scripts with `#!/usr/bin/env bash` and `set -euo pipefail`, following both files under `scripts/`.
- Quote shell expansions, use `printf` for diagnostics, and send errors to stderr, following `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`.
- Validate required tools explicitly. `scripts/validate-docs.sh` exits `2` when Python or PyYAML is unavailable; distinguish setup failure from validation failure (`1`).
- Aggregate independent documentation errors in the Python `errors` list, print every finding, then exit once, following `scripts/validate-docs.sh`; do not fail at the first content defect.
- Use `try/finally`-equivalent shell cleanup with `trap`, as `scripts/render-diagrams.sh` does for its temporary staging directory.
- Preserve the current optional-tool policy deliberately: `scripts/render-diagrams.sh` returns success when `mmdc` is absent. Callers requiring Mermaid validation must first assert `command -v mmdc`, as `.gitlab-ci.yml` does.

## Logging

- Prefix validation failures with `ERROR:` and write them to stderr, following `scripts/validate-docs.sh`.
- Print one final pass/fail summary from `scripts/validate-docs.sh`; do not emit sensitive document contents or environment values.
- Keep rendering messages operational and path-oriented, following `scripts/render-diagrams.sh`.
- No application logging framework exists; observability in `docs/engineering/ci-cd-and-release.md` and `docs/architecture/non-functional-requirements.md` is a future requirement only.

## Comments

- Use comments to identify configuration intent, such as the generated-output and local-tool sections in `.gitignore`, rather than narrating obvious commands.
- Keep shell comments sparse; script behavior is expressed through small named variables and direct control flow in `scripts/*.sh`.
- In documentation, explain constraints and rationale through headings, lists, tables, and ADR sections rather than inline source comments; use `docs/adr/template.md` for decisions.
- Not applicable. No implemented JavaScript or TypeScript product source exists.
- Python docstrings are not used in the embedded validator. Keep helper names and type annotations explicit if extending `scripts/validate-docs.sh`.

## Function Design

- Keep validator helpers focused on one operation: YAML loading, path rendering, front-matter parsing, anchor extraction, link validation, or navigation traversal, following `scripts/validate-docs.sh`.
- Move reusable validation logic out of the inline Python block if it acquires an independent test suite; currently all Python validation is embedded in `scripts/validate-docs.sh`.
- Pass paths and caches explicitly, as in `validate_link(source, raw_target, anchor_cache)` in `scripts/validate-docs.sh`.
- Use `pathlib.Path` rather than concatenated path strings in Python, following `scripts/validate-docs.sh`.
- Return parsed values or `None` for recoverable parse errors and record diagnostics centrally, following `load_yaml()` and `parse_frontmatter()` in `scripts/validate-docs.sh`.
- Use process exit codes for command contracts: success `0`, content validation failure `1`, and missing validation prerequisites `2` in `scripts/validate-docs.sh`.

## Module Design

- Not applicable to product code. The repository has no importable application modules.
- Treat each governed Markdown file as the owner of one topic and link to it instead of duplicating content, as required by `docs/governance/document-control.md`.
- Keep machine-readable contracts separate by concern under `docs/contracts/api/`, `docs/contracts/events/`, and `docs/contracts/schemas/`.
- Use section `README.md` files as human navigation indexes, not code barrels; examples are `docs/engineering/README.md` and `docs/architecture/README.md`.
- Use `docs/catalog.yaml` for complete machine-readable inventory and `mkdocs.yml` for publication navigation.

<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->

## Architecture

## System Overview

```text

```

```text

```

## Component Responsibilities

### Implemented Repository Components

| Component | Responsibility | File |
|-----------|----------------|------|
| Documentation entry point | Declares `docs/` as the current package and directs readers to architecture and governance | `README.md` |
| Documentation index | Provides the sole entry point to approved documentation and its subject-specific sources of truth | `docs/README.md` |
| Catalog | Records status, owner, phase, source-of-truth status, and generated status for governed artifacts | `docs/catalog.yaml` |
| Architecture package | Defines target boundaries, module DAG, deployment, flows, and non-functional verification goals | `docs/architecture/README.md` |
| ADR registry | Records accepted, superseded, proposed, and rejected architectural decisions | `docs/adr/README.md` |
| Domain specifications | Defines commands, queries, invariants, events, ownership, and failure behavior for target modules | `docs/domain/README.md` |
| Contract package | Defines the target HTTP API, event transport, and JSON schemas | `docs/contracts/README.md` |
| Engineering package | Converts architecture decisions into target coding, testing, migration, and release rules | `docs/engineering/README.md` |
| Operations package | Defines the proposed air-gapped runtime, resilience, observability, and recovery design | `docs/operations/README.md` |
| Documentation validator | Validates front matter, catalog coverage, links, YAML/JSON, shell syntax, and repository documentation rules | `scripts/validate-docs.sh` |
| Diagram renderer | Converts authoritative Mermaid sources into local SVG output | `scripts/render-diagrams.sh` |
| Documentation CI | Runs validation, strict MkDocs build, and optional Mermaid rendering | `.gitlab-ci.yml` |

### Documented Target Components

| Component | Responsibility | File |
|-----------|----------------|------|
| Unified React shell | Serves all roles from one Arabic-first, bilingual, RTL/LTR application; module routes and navigation contribute through contracts | `docs/adr/009-unified-react-shell.md` |
| Laravel modular monolith | Hosts all business and platform modules in one horizontally scalable application | `docs/architecture/overview.md` |
| Canonical module set | Owns the only 19 legal module names, responsibilities, contracts, events, tables, migrations, and tests | `docs/architecture/module-catalog.md` |
| Authorization | Produces explainable RBAC + ABAC decisions from actor, capability, organization, classification, state, delegation, and trusted record facts | `docs/architecture/module-catalog.md` |
| WorkDefinitions | Owns immutable published work-type schemas and typed projection metadata | `docs/architecture/module-catalog.md` |
| WorkRecords | Owns dynamic work instances, including general requests; persists relational envelope, version-bound payload, state, and lock version | `docs/architecture/module-catalog.md` |
| Workflow | Owns immutable workflow definitions and execution instances, but not the source module's business completion meaning | `docs/architecture/module-catalog.md` |
| Documents | Owns document metadata, versions, classification, storage references, links, and authorized download | `docs/architecture/module-catalog.md` |
| Derived consumers | `Notifications`, `Search`, `Reporting`, and `Workspace` consume events/projection feeds without owning or mutating source truth | `docs/architecture/context-map.md` |
| Runtime data services | Provide MySQL HA, internal cache/queue, S3-compatible object storage, internal search, and observability | `docs/architecture/diagrams/containers.mmd` |

## Pattern Overview

- Use one deployable Laravel application and one React application; do not split business modules into services merely because module boundaries exist (`docs/adr/001-modular-monolith.md`, `docs/adr/009-unified-react-shell.md`).
- Treat the module as the highest code/data ownership boundary and a feature slice as one complete use case inside it (`docs/engineering/vertical-slices.md`).
- Keep synchronous module dependencies acyclic and directed only toward lower-ranked canonical modules (`docs/architecture/dependency-rules.md`).
- Use Commands/Handlers for writes and Queries/Read Models for reads without event sourcing (`docs/architecture/overview.md`).
- Persist source state and its Outbox event in the same caller-owned MySQL transaction; deliver events at least once to idempotent consumers (`docs/architecture/dependency-rules.md`).
- Keep search, reports, workspace, and notifications derived and rebuildable; never use them as write-side truth (`docs/architecture/context-map.md`).
- Centralize authorization in the backend and apply the same decision to API, search, reporting, exports, document downloads, and field serialization (`docs/architecture/overview.md`).

## Layers

### Current Documentation Layer

- Purpose: Maintain the accepted architecture, domain, security, delivery, and operations decisions that future implementation must follow.
- Location: `docs/`
- Contains: Markdown specifications, Mermaid sources, OpenAPI, AsyncAPI, and JSON Schema documents cataloged by `docs/catalog.yaml`.
- Depends on: Governance precedence in `docs/README.md` and accepted/superseding decisions in `docs/adr/README.md`.
- Used by: Documentation validation in `scripts/validate-docs.sh`, publication through `mkdocs.yml`, and future product implementation planning.

### Target Presentation Layer

- Purpose: Provide one session, router, navigation system, design system, and Arabic-first bilingual experience for all roles.
- Location: Not assigned to a concrete source directory; the monorepo and unified-app decision are binding in `docs/architecture/overview.md` and `docs/adr/009-unified-react-shell.md`.
- Contains: React + TypeScript shell plus module-owned route and navigation contributions.
- Depends on: Authorization-filtered Laravel HTTP responses defined by `docs/contracts/api/openapi.yaml`.
- Used by: Internal users over the cluster network shown in `docs/architecture/diagrams/containers.mmd`.

### Target Feature Slice Layer

- Purpose: Implement one business action end-to-end inside its owning module.
- Location: Logical `Modules/<Module>/Features/<BusinessVerb>/` from `docs/engineering/vertical-slices.md`; concrete Laravel root not yet specified.
- Contains: `Command` or `Query`, `Handler`, `Http`, and co-owned `Tests`.
- Depends on: The owning module's `Domain/`, published lower-rank `Contracts/`, and neutral technical primitives only (`docs/engineering/coding-and-module-boundaries.md`).
- Used by: HTTP endpoints, internal command/query dispatch, and module-owned workers.

### Target Domain and Contract Layer

- Purpose: Protect module invariants and publish stable collaboration boundaries.
- Location: Logical `Modules/<Module>/Domain/`, `Modules/<Module>/Contracts/`, and `Modules/<Module>/Events/` from `docs/engineering/vertical-slices.md`.
- Contains: Aggregates/value objects where warranted, contract DTOs/interfaces, and versioned past-tense events.
- Depends on: No other module internals; cross-module imports are limited to published contracts/events (`docs/engineering/coding-and-module-boundaries.md`).
- Used by: Feature handlers and authorized consumers.

### Target Infrastructure Layer

- Purpose: Adapt module-owned contracts to MySQL, event delivery, object storage, search, clocks, and identifiers.
- Location: Logical `Modules/<Module>/Infrastructure/` from `docs/engineering/vertical-slices.md`.
- Contains: Persistence adapters, module-owned migrations, Outbox/Inbox integration, and external service adapters.
- Depends on: Internal runtime services depicted in `docs/architecture/diagrams/containers.mmd`.
- Used by: The owning module's handlers; never imported directly by another module (`docs/architecture/dependency-rules.md`).

### Target Derived Read Layer

- Purpose: Provide user-facing search, dashboards, reports, notifications, and personal workspace projections.
- Location: Canonical `Search`, `Reporting`, `Notifications`, and `Workspace` modules from `docs/architecture/module-catalog.md`; concrete filesystem root not yet implemented.
- Contains: Event Inbox/deduplication state, projection checkpoints, authorized read models, and rebuild operations.
- Depends on: Published events/projection feeds plus `Authorization`; never source tables (`docs/architecture/context-map.md`).
- Used by: Authorized query endpoints and the unified React application.

## Data Flow

### Primary Write Request Path

### Authorized Read Path

### Asynchronous Outbox Flow

### Document Upload and Download Flow

- Store operational truth in module-owned MySQL tables and use optimistic concurrency through `lock_version`/`ETag`/`If-Match` (`docs/architecture/non-functional-requirements.md`, `docs/contracts/module-contracts.md`).
- Pin running records to immutable published work-definition and workflow versions (`docs/architecture/overview.md`).
- Keep client state presentational; backend decisions remain authoritative (`docs/adr/009-unified-react-shell.md`).
- Treat derived projections as eventually consistent and rebuildable (`docs/architecture/context-map.md`).

## Key Abstractions

### Canonical Module

- Purpose: Assign exactly one owner to domain meaning, tables, migrations, contracts, events, and tests.
- Examples: `WorkRecords`, `Strategy`, and `Risk` in `docs/architecture/module-catalog.md`.
- Pattern: Bounded context inside one modular monolith, constrained by the rank DAG in `docs/architecture/dependency-rules.md`.

### Vertical Slice

- Purpose: Co-locate one business outcome rather than grouping application code into cross-module horizontal service buckets.
- Examples: Logical `Modules/WorkRecords/Features/SubmitWorkRecord/` and `Modules/PortfolioProjects/Features/ApproveMilestone/` based on `docs/engineering/vertical-slices.md`.
- Pattern: Command/Query + Handler + HTTP adapter + tests, with shared module invariants in `Domain/`.

### Published Contract

- Purpose: Support an immediate result or shared invariant without exposing persistence or module internals.
- Examples: `DecideAccess`, `ResolveOrganizationScope`, and `StartWorkflow` in `docs/architecture/module-catalog.md`.
- Pattern: Owner-published interface with stable DTOs and declared errors; never ORM models or query builders (`docs/engineering/coding-and-module-boundaries.md`).

### RecordFacts and AccessDecision

- Purpose: Keep centralized authorization independent from business-module tables while still using trusted record context.
- Examples: Schema at `docs/contracts/schemas/record-facts.schema.json` and decision schema at `docs/contracts/schemas/access-decision.schema.json`.
- Pattern: Owner builds facts; `Authorization` returns an explainable decision and field set; no callback from `Authorization` to the business owner (`docs/architecture/context-map.md`).

### Transactional Outbox and Consumer Inbox

- Purpose: Preserve source consistency while enabling reliable asynchronous projections and notifications.
- Examples: Event envelope at `docs/contracts/schemas/event-envelope.schema.json` and channels at `docs/contracts/events/asyncapi.yaml`.
- Pattern: Atomic source + Outbox write, at-least-once Valkey-compatible Streams delivery, Inbox deduplication, explicit acknowledgement, and DLQ (`docs/contracts/module-contracts.md`).

### WorkRecord

- Purpose: Represent dynamic administrative work, including general requests, without creating a separate Requests module.
- Examples: API resource at `docs/contracts/api/openapi.yaml` and schema at `docs/contracts/schemas/work-record.schema.json`.
- Pattern: Relational envelope + version-bound payload + typed projections + explicit references (`docs/architecture/overview.md`).

## Entry Points

### Current Documentation Entry

- Location: `README.md`
- Triggers: Developer, reviewer, operator, or planner opens the repository.
- Responsibilities: Identify `docs/` as the current package and direct readers to `docs/architecture/overview.md`, `docs/governance/document-control.md`, and `docs/README.md`.

### Current Architecture Entry

- Location: `docs/architecture/overview.md`
- Triggers: Any architecture, module-boundary, deployment, or implementation decision.
- Responsibilities: Define binding target decisions and precedence over non-authoritative material.

### Current Contract Entry

- Location: `docs/contracts/README.md`
- Triggers: HTTP client, synchronous module boundary, event producer, or event consumer design.
- Responsibilities: Direct implementation to OpenAPI, AsyncAPI, JSON Schema, UUIDv7, correlation, classification, concurrency, and idempotency requirements.

### Current Validation Entry

- Location: `scripts/validate-docs.sh`
- Triggers: Local validation or the `validate-docs` GitLab job in `.gitlab-ci.yml`.
- Responsibilities: Fail invalid documentation structure, metadata, links, catalog coverage, schemas, and repository rules.

### Target HTTP Entry

- Location: Contract-only `docs/contracts/api/openapi.yaml`; no Laravel route/controller implementation exists.
- Triggers: Authenticated calls below `/api/v1` plus unauthenticated login.
- Responsibilities: Correlation, authentication, idempotency, optimistic concurrency, command/query dispatch, authorization-filtered serialization, and RFC 7807 errors.

### Target Worker Entry

- Location: Contract-only `docs/contracts/events/asyncapi.yaml`; no queue worker implementation exists.
- Triggers: Valkey-compatible Stream delivery and scheduled Outbox relay work.
- Responsibilities: Inbox deduplication, derived updates, explicit acknowledgement, retry, and DLQ routing.

## Architectural Constraints

- **Implementation status:** Product code is absent; only documentation tooling and machine-readable contracts are implemented (`README.md`, `docs/contracts/README.md`).
- **Threading:** Target HTTP work scales through multiple Web/API replicas; async work runs in worker replicas; the scheduler is singleton or leader-elected (`docs/architecture/diagrams/deployment.mmd`).
- **Global state:** Target operational state is one MySQL source of truth with module ownership, plus shared queue/cache and derived stores; no product global mutable state exists yet (`docs/architecture/overview.md`).
- **Circular imports:** Synchronous dependencies must follow the rank DAG and may target only a lower rank; same-rank dependencies are forbidden (`docs/architecture/dependency-rules.md`).
- **Transactions:** The write Handler owns begin/commit/rollback; synchronous contracts join it; workers, search, storage, and network services are never inside it (`docs/architecture/dependency-rules.md`).
- **Data ownership:** Never query, join, constrain, or migrate another module's business tables; collaborate through contracts, events, IDs, references, or governed read models (`docs/engineering/coding-and-module-boundaries.md`).
- **Module names:** Use only the 19 canonical names; `Requests` and `Indicators` are explicitly invalid module boundaries (`docs/architecture/overview.md`, `docs/architecture/module-catalog.md`).
- **Security boundary:** React visibility is not authorization; apply backend decisions consistently to API, search, reporting, export, and document download (`docs/architecture/overview.md`).
- **Runtime isolation:** Target build and runtime cannot depend on Internet, CDN, public package registries, or public images (`docs/engineering/ci-cd-and-release.md`).
- **Recovery:** Verify `RPO <= 15 minutes` and `RTO <= 2 hours` by actual restore, with backups outside the Kubernetes failure domain (`docs/architecture/non-functional-requirements.md`).
- **Repository authority:** Treat `docs/` as authoritative and `.planning/archive/` as historical/non-authoritative; document precedence is defined by `docs/README.md` and `docs/architecture/overview.md`.

## Anti-Patterns

### Treating the Target as Implemented

### Application-Wide Horizontal Service Buckets

### Cross-Module Persistence Access

### Frontend Authorization

### Events as Hidden Commands

### Derived Stores as Sources of Truth

### Unbounded Shared Folder

## Error Handling

- Return RFC 7807 `application/problem+json` errors and preserve `X-Correlation-ID` across request/response boundaries (`docs/contracts/README.md`).
- Return `409` for conflicting idempotency replays and `412` for stale `If-Match` values (`docs/contracts/module-contracts.md`).
- Roll back source state and Outbox together when validation, invariant, authorization, required synchronous contract, or Outbox persistence fails (`docs/architecture/dependency-rules.md`).
- Deny access when trusted authorization facts are unavailable, especially for linked documents (`docs/architecture/diagrams/document-sequence.mmd`).
- Acknowledge duplicate events without repeating effects; route invalid or exhausted events to a reviewable DLQ rather than discarding them (`docs/contracts/module-contracts.md`).
- Use expand-migrate-verify-contract and forward fixes rather than destructive production down migrations (`docs/engineering/database-migrations.md`).

## Cross-Cutting Concerns

<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->

## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->

## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:

- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->

<!-- GSD:profile-start -->

## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
