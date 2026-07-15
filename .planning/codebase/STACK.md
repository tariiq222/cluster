# Technology Stack

**Analysis Date:** 2026-07-15

## Current Repository Status

- The current product artifact is an Arabic-first documentation and contract repository under `docs/`; no Laravel application tree (`app/`), React source tree (`resources/js/`), root `composer.json`, root `package.json`, container definition, Helm chart, or Kubernetes manifest is present.
- Treat `docs/README.md` and `docs/catalog.yaml` as the active documentation index and catalog. Historical material under `.planning/archive/` is not part of this analysis.
- The accepted target is a Laravel modular monolith plus one React + TypeScript web application in a monorepo, but framework versions and application dependencies remain unselected in `docs/architecture/overview.md`.
- The executable repository surface consists of documentation validation and diagram scripts in `scripts/`, a documentation-only GitLab pipeline in `.gitlab-ci.yml`, and local agent tooling under `.opencode/`.

## Languages

**Primary:**
- Markdown - Product, architecture, security, domain, operations, engineering, plan, and ADR sources of truth under `docs/`, with the repository entry point in `README.md`.
- YAML - Documentation catalog and site/CI configuration in `docs/catalog.yaml`, `mkdocs.yml`, and `.gitlab-ci.yml`; API and event contracts in `docs/contracts/api/openapi.yaml` and `docs/contracts/events/asyncapi.yaml`.

**Secondary:**
- JSON / JSON Schema Draft 2020-12 - Versioned contract schemas in `docs/contracts/schemas/*.schema.json` and markdown lint configuration in `.markdownlint.json`.
- Bash - Documentation validation and Mermaid rendering in `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`.
- Python 3 - An embedded validation program inside `scripts/validate-docs.sh`; it uses the standard library plus `yaml`/PyYAML.
- Mermaid - Editable C4, deployment, and sequence diagrams in `docs/architecture/diagrams/*.mmd`.
- JavaScript (CommonJS) - Local OpenCode/GSD automation under `.opencode/plugins/`, `.opencode/hooks/`, `.opencode/scripts/`, and `.opencode/gsd-core/`; it is development tooling, not product application code.
- PHP - Selected for the future Laravel backend in `docs/architecture/overview.md`; no product PHP source or Composer manifest exists.
- TypeScript - Selected for the future React frontend in `docs/adr/009-unified-react-shell.md`; no product TypeScript source or TypeScript manifest exists.

## Runtime

**Environment:**
- GitLab documentation jobs declare Python 3.12 through `DOCS_PYTHON_IMAGE: "python:3.12-slim"` in `.gitlab-ci.yml`.
- The inspected workstation provides Python 3.9.6 and PyYAML 6.0.3; MkDocs is not installed locally. These host versions are not pinned by repository runtime files.
- The inspected workstation provides Mermaid CLI 11.16.0 and Bash 3.2.57 for `scripts/render-diagrams.sh` and `scripts/validate-docs.sh`; repository files do not pin either version.
- Node.js 22.22.2 and npm 11.18.0 are available for local `.opencode/` tooling. `.opencode/package.json` requires only CommonJS mode and `@opencode-ai/plugin` 1.18.1.
- PHP 8.5.7 and Composer 2.10.1 are installed on the workstation, but they are not project runtimes because the root has no `composer.json`, `composer.lock`, `artisan`, or PHP source.
- The future production runtime is on-premises, air-gapped Kubernetes with replicated Laravel Web/API and worker processes, as specified in `docs/architecture/overview.md` and `docs/operations/kubernetes-platform.md`.

**Package Manager:**
- pip - Documentation dependencies are range-constrained, not locked, in `requirements-docs.txt`: `mkdocs>=1.6,<2` and `mkdocs-material>=9.6,<10`.
- npm - Used only for local `.opencode/` agent tooling. `.opencode/package-lock.json` is lockfile v3 and pins `@opencode-ai/plugin` 1.18.1 plus transitive dependencies.
- Composer - Required by the selected Laravel direction in `docs/governance/assumptions-constraints.md`, but no product Composer manifest or lockfile exists.
- Product lockfile: missing. No root Composer, npm, Python, container-image, or deployment lock is present; `.opencode/package-lock.json` is tooling-only and is ignored by `.opencode/.gitignore`.

## Frameworks

**Core:**
- MkDocs `>=1.6,<2` - Builds the active documentation site from `docs/` according to `mkdocs.yml` and `requirements-docs.txt`.
- Material for MkDocs `>=9.6,<10` - Arabic documentation theme selected in `mkdocs.yml` and declared in `requirements-docs.txt`.
- Laravel, version not pinned - Accepted target backend and modular-monolith boundary in `docs/architecture/overview.md` and `docs/adr/001-modular-monolith.md`; not implemented.
- React + TypeScript, versions not pinned - Accepted target unified frontend in `docs/adr/009-unified-react-shell.md`; not implemented.

**Testing:**
- Custom Bash/Python documentation validator - `scripts/validate-docs.sh` checks shell syntax, YAML, JSON, front matter, links/fragments, catalog coverage, MkDocs navigation, unfinished markers, and prohibited artifacts.
- No executable product test framework is installed because application source and manifests are absent. The accepted roadmap expects Pest tests and architecture/contract gates in `docs/plans/implementation-roadmap.md`.
- The current documentation validator passes: `./scripts/validate-docs.sh` completed successfully on 2026-07-15, including the front-matter fragments from `docs/plans/readiness-checklist.md` to the explicit `#w1-10`, `#w2-7`, and `#w3-7` anchors in `docs/plans/release-1-platform.md`, `docs/plans/release-2-strategy-portfolio.md`, and `docs/plans/release-3-risk.md`.
- A local strict MkDocs build was not run because the inspected workstation does not have MkDocs installed; `.gitlab-ci.yml` remains the implemented `python -m mkdocs build --strict` gate.

**Build/Dev:**
- GitLab CI - The implemented pipeline in `.gitlab-ci.yml` has `validate-docs`, `build-docs`, and conditional `validate-mermaid` jobs only.
- MkDocs strict build - `.gitlab-ci.yml` runs `python -m mkdocs build --strict` and publishes the generated `site/` directory for one week.
- Mermaid CLI (`mmdc`) - `scripts/render-diagrams.sh` converts `docs/architecture/diagrams/*.mmd` into generated SVG files under `build/diagrams/`; `build/` is ignored by `.gitignore`.
- OpenCode plugin API and GSD Core 1.7.0 - Local development workflow tooling is configured by `.opencode/opencode.json`, `.opencode/package.json`, and `.opencode/gsd-core/VERSION`.
- PHPStan, Laravel Pint, Pest, frontend build tooling, SBOM generation, image signing, and air-gap verification are future delivery gates recorded in `docs/plans/implementation-roadmap.md`; no runnable commands or manifests implement them yet.

## Key Dependencies

**Critical:**
- PyYAML - Imported directly as `yaml` by `scripts/validate-docs.sh`; it is currently available locally as 6.0.3 but is not declared directly in `requirements-docs.txt`.
- MkDocs and Material for MkDocs - The only declared repository-level dependencies in `requirements-docs.txt`; exact versions are not locked.
- OpenAPI 3.1, AsyncAPI 3.1, and JSON Schema 2020-12 - Contract formats stored in `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, and `docs/contracts/schemas/`.
- `@opencode-ai/plugin` 1.18.1 - The only direct npm dependency in `.opencode/package.json`; it is local development tooling and not a product dependency.

**Infrastructure:**
- MySQL InnoDB Cluster, version not selected - Required future operational source of truth and HA database in `docs/operations/kubernetes-platform.md`.
- Valkey HA, version not selected - Required future cache, queues, and Valkey-compatible Streams transport in `docs/operations/kubernetes-platform.md` and `docs/contracts/events/asyncapi.yaml`.
- S3-compatible object storage or MinIO, product/version not selected - Required future document and backup storage in `docs/operations/ha-dr-backup.md`.
- OpenSearch, version not selected - Named target for user-facing derived search in `docs/operations/kubernetes-platform.md`; it is not a source of truth.
- Loki, version not selected - Named target for internal operational logs in `docs/operations/kubernetes-platform.md`.
- Vault, version not selected - Named target for secrets and internal PKI in `docs/operations/kubernetes-platform.md`.
- Kubernetes or fallback RKE2, distribution/version not finalized - The internally managed platform is preferred; three-control-plane-node RKE2 is the documented fallback in `docs/operations/kubernetes-platform.md`.
- GitLab, Harbor, Nexus, and a GitOps controller - Required internal platform roles in `docs/operations/air-gap-supply-chain.md`; repository documentation explicitly does not assert that concrete services are provisioned.

## Configuration

**Environment:**
- No `.env` or `.env.*` file is present under the repository root, and no product environment-variable contract exists because the application has not been scaffolded.
- `.gitlab-ci.yml` defines `DOCS_PYTHON_IMAGE` with a default image reference and conditionally consumes `MERMAID_IMAGE`; these are the only implemented CI environment controls relevant to the active product documentation.
- `.opencode/opencode.json` configures a local Node-based GSD MCP process; `.opencode/settings.json` and `.opencode/` settings are developer workflow configuration, not production configuration.
- Future environment-specific hosts, credentials, and secrets must remain outside these documents in governed configuration, as stated in `docs/operations/README.md`.

**Build:**
- `mkdocs.yml` defines `docs/` as input, `site/` as generated output, Arabic Material theme settings, strict navigation, and Markdown extensions.
- `.gitlab-ci.yml` defines the current documentation build and validation pipeline.
- `requirements-docs.txt` defines unpinned-range documentation dependencies.
- `.editorconfig` and `.markdownlint.json` define repository text and Markdown conventions; no formatter or linter package manifest installs markdownlint.
- No root Dockerfile, Compose file, Makefile, Helm/Kustomize configuration, Kubernetes manifest, Vite config, TypeScript config, Laravel config, or product CI stages are present.

## Platform Requirements

**Development:**
- Install Python and resolve `requirements-docs.txt` from an approved internal package source before running `./scripts/validate-docs.sh` or `mkdocs build --strict`, per `README.md`.
- Provide Mermaid CLI as the `mmdc` executable to validate/render `docs/architecture/diagrams/*.mmd` through `scripts/render-diagrams.sh`; the script exits successfully without rendering when the CLI is absent.
- Use Node/npm only when operating local `.opencode/` automation. Its lockfile currently records public npm registry URLs in `.opencode/package-lock.json`, so it is not evidence of an air-gap-compliant product supply chain.
- Add exact PHP/Laravel, Composer, Node/React/TypeScript, frontend bundler, test, and static-analysis versions when application scaffolding begins; current target documents such as `docs/architecture/overview.md` do not pin them.

**Production:**
- Deploy only inside the Third Health Cluster data center on internally managed, air-gapped Kubernetes; no runtime internet, CDN, public script/font, or SaaS dependency is allowed by `docs/governance/assumptions-constraints.md`.
- Resolve Composer/npm packages and base images through approved internal mirrors/registries; artifacts must be immutable, signed, tied to a digest, and accompanied by SBOM and provenance according to `docs/operations/air-gap-supply-chain.md`.
- Enforce default-deny egress, internal DNS, internal PKI, least-privilege service accounts, and GitOps-only writes to staging/production according to `docs/operations/kubernetes-platform.md`.
- Support 5,000-20,000 accounts and up to 2,000 concurrent users, with `RPO <= 15 minutes` and `RTO <= 2 hours`, as required by `docs/architecture/overview.md` and `docs/operations/ha-dr-backup.md`.
- Keep encrypted backups outside the Kubernetes failure domain and exercise restoration quarterly according to `docs/operations/ha-dr-backup.md`.

---

*Stack analysis: 2026-07-15*
