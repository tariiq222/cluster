# Codebase Structure

**Analysis Date:** 2026-07-15

## Directory Layout

The true repository root is `/Users/tariq/code/R3/cluster`. It is currently a documentation repository with local workflow tooling; it is not yet the product monorepo described by `docs/architecture/overview.md`.

```text
cluster/
├── .git/                       # Git metadata
├── .gitlab/                    # Documentation CODEOWNERS
├── .opencode/                  # Local OpenCode/GSD workflow installation, not product code
├── .planning/                  # GSD project state; archive is historical
│   ├── archive/                # Historical/non-authoritative planning artifacts
│   ├── codebase/               # Generated codebase maps
│   └── research/               # Planning research
├── build/
│   └── diagrams/               # Generated Mermaid SVGs; ignored build output
├── docs/                        # Authoritative current documentation and contracts
│   ├── adr/                    # Official architecture decision record
│   ├── architecture/           # Target overview, boundaries, DAG, C4, NFRs
│   │   └── diagrams/           # Authoritative editable Mermaid sources
│   ├── contracts/              # OpenAPI, AsyncAPI, JSON Schemas, contract rules
│   ├── data-security/          # Data, access, privacy, retention, threat model
│   ├── domain/                 # Target module specifications
│   ├── engineering/            # Target implementation/testing/release rules
│   ├── governance/             # Document control, glossary, RACI, traceability
│   ├── operations/             # Proposed air-gapped operations design
│   ├── plans/                  # Accepted R1-R3 implementation roadmap
│   ├── product/                # Product scope, journeys, releases, metrics
│   ├── catalog.yaml            # Governed artifact catalog and authority metadata
│   └── README.md               # Sole entry point for approved documentation
├── scripts/                     # Documentation validation and diagram rendering
├── .editorconfig                # Repository text formatting settings
├── .gitattributes               # Git path/line-ending attributes
├── .gitignore                   # Ignores generated docs, caches, local tooling output
├── .gitlab-ci.yml               # Current docs-only GitLab pipeline
├── .markdownlint.json           # Markdown lint configuration
├── AGENTS.md                     # Agent project instructions
├── CONTRIBUTING.md              # Documentation contribution rules
├── mkdocs.yml                    # Arabic Material MkDocs site and navigation
├── NOTICE.md                     # Ownership/notices
├── README.md                     # Repository entry point
├── requirements-docs.txt         # Python documentation dependencies
└── SECURITY.md                   # Security reporting/content rules
```

Not present at the root: Laravel `artisan`, `composer.json`, PHP source, React/TypeScript source, product `package.json`, product tests, database migrations, Dockerfiles, Helm/Kustomize, Kubernetes manifests, or application configuration. The gap matters because `docs/architecture/overview.md` describes a target monorepo while `README.md` accurately describes the checked-in artifact as the current documentation package.

## Directory Purposes

### `docs/`

- Purpose: Hold the authoritative current product, architecture, domain, security, engineering, operations, contract, and delivery package.
- Contains: Governed Markdown with front matter, YAML API/event contracts, JSON Schemas, and Mermaid sources.
- Key files: `docs/README.md`, `docs/catalog.yaml`, `docs/architecture/overview.md`.
- Authority: Use this subtree over `.planning/archive/`; precedence is defined in `docs/README.md` and `docs/architecture/overview.md`.

### `docs/architecture/`

- Purpose: Define the binding documented target architecture.
- Contains: System overview, context map, canonical module catalog, dependency rules, C4/sequence source index, NFRs, and Mermaid diagrams.
- Key files: `docs/architecture/overview.md`, `docs/architecture/context-map.md`, `docs/architecture/module-catalog.md`, `docs/architecture/dependency-rules.md`, `docs/architecture/non-functional-requirements.md`.
- Add target architecture changes here only with the ADR/authority process in `docs/governance/document-control.md` and `docs/adr/README.md`.

### `docs/architecture/diagrams/`

- Purpose: Store the editable source for system, container, module, deployment, and sequence diagrams.
- Contains: Eight `.mmd` Mermaid files indexed by `docs/architecture/c4-and-flows.md`.
- Key files: `docs/architecture/diagrams/modules.mmd`, `docs/architecture/diagrams/deployment.mmd`, `docs/architecture/diagrams/outbox-sequence.mmd`.
- Generate SVGs with `scripts/render-diagrams.sh`; do not manually maintain `build/diagrams/`.

### `docs/adr/`

- Purpose: Maintain the official decision record and supersession history.
- Contains: `docs/adr/template.md`, 22 numbered ADRs, and `docs/adr/README.md`.
- Key files: `docs/adr/001-modular-monolith.md`, `docs/adr/003-module-boundaries.md`, `docs/adr/011-lightweight-cqrs-and-transactions.md`.
- Add a new numbered ADR rather than changing the meaning of an accepted one, following `docs/adr/README.md`.

### `docs/domain/`

- Purpose: Specify target domain behavior for the 19 canonical modules.
- Contains: Module/group specifications with commands, queries, invariants, events, authorization facts, transaction behavior, and tests.
- Key files: `docs/domain/work-records.md`, `docs/domain/workflow.md`, `docs/domain/authorization.md`, `docs/domain/strategy.md`, `docs/domain/risk.md`.
- Keep module names aligned with `docs/architecture/module-catalog.md`; do not add `Requests` or `Indicators` specifications.

### `docs/contracts/`

- Purpose: Define machine-readable client and asynchronous boundaries before implementation.
- Contains: `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, `docs/contracts/schemas/*.schema.json`, and `docs/contracts/module-contracts.md`.
- Key files: `docs/contracts/README.md`, `docs/contracts/schemas/record-facts.schema.json`, `docs/contracts/schemas/event-envelope.schema.json`.
- Update the owning contract and compatibility/version metadata when changing a public shape, according to `docs/contracts/module-contracts.md`.

### `docs/engineering/`

- Purpose: Translate accepted architecture into implementation constraints.
- Contains: Vertical-slice layout, module boundaries, tests, migrations, CI/release, and constrained definition DSL rules.
- Key files: `docs/engineering/vertical-slices.md`, `docs/engineering/coding-and-module-boundaries.md`, `docs/engineering/testing-strategy.md`.
- Treat these documents as target implementation rules; they are draft source-of-truth documents in `docs/catalog.yaml`, not evidence that product code exists.

### `docs/operations/`

- Purpose: Describe the proposed target air-gapped platform and operating model.
- Contains: Kubernetes, physical topology, HA/DR, observability, supply chain, incident response, and runbooks.
- Key files: `docs/operations/README.md`, `docs/operations/kubernetes-platform.md`, `docs/operations/ha-dr-backup.md`.
- Treat product names as target/proposed operational decisions, not deployed repository assets; no manifests exist here (`docs/operations/README.md`).

### `docs/plans/`

- Purpose: Sequence the accepted R1, R2, and R3 implementation waves and release gates.
- Contains: Main roadmap, release plans, and readiness checklist.
- Key files: `docs/plans/implementation-roadmap.md`, `docs/plans/release-1-platform.md`, `docs/plans/release-2-strategy-portfolio.md`, `docs/plans/release-3-risk.md`.
- Plans describe future deliverables; do not use them as proof of implemented files.

### `scripts/`

- Purpose: Provide the current executable maintenance surface for documentation.
- Contains: `scripts/validate-docs.sh` and `scripts/render-diagrams.sh`.
- Key files: `scripts/validate-docs.sh` is the local and CI validation entry point; `scripts/render-diagrams.sh` is a best-effort Mermaid renderer.
- Add documentation automation here only when it supports the current repository package and remains usable in the offline model described by `README.md`.

### `build/`

- Purpose: Hold generated local artifacts.
- Contains: SVG outputs in `build/diagrams/` rendered from `docs/architecture/diagrams/*.mmd`.
- Key files: Generated `build/diagrams/*.svg`; sources remain under `docs/architecture/diagrams/`.
- Generated: Yes, by `scripts/render-diagrams.sh`.
- Committed: No by policy; `build/` is ignored in `.gitignore` even if local files exist.

### `.gitlab/` and `.gitlab-ci.yml`

- Purpose: Own current documentation review routing and CI jobs.
- Contains: `.gitlab/CODEOWNERS` plus validation/build jobs in `.gitlab-ci.yml`.
- Key files: `.gitlab-ci.yml`, `.gitlab/CODEOWNERS`.
- Current scope: Documentation validation and publication only; the fuller target product pipeline is documented, not implemented, in `docs/engineering/ci-cd-and-release.md`.

### `.planning/`

- Purpose: Hold GSD project state, roadmap, requirements, research, and generated codebase maps.
- Contains: `.planning/PROJECT.md`, `.planning/REQUIREMENTS.md`, `.planning/ROADMAP.md`, `.planning/STATE.md`, `.planning/research/`, `.planning/codebase/`, and `.planning/archive/`.
- Key files: `.planning/PROJECT.md`, `.planning/STATE.md`; these assist planning but do not supersede `docs/`.
- Authority: `.planning/archive/` is historical/non-authoritative for this map; do not derive current architecture from it.

### `.opencode/`

- Purpose: Provide local OpenCode/GSD agent workflow configuration and installed tooling.
- Contains: Agents, commands, hooks, plugins, skills, GSD runtime, package metadata, and local dependencies.
- Key files: `.opencode/opencode.json`, `.opencode/package.json`, `.opencode/skills/`.
- Product boundary: Do not place Laravel/React domain code here; it is developer tooling, not the platform implementation.

## Key File Locations

### Entry Points

- `README.md`: True repository entry point and local validation instructions.
- `docs/README.md`: Sole entry point for approved documentation.
- `docs/architecture/overview.md`: Binding target architecture entry point.
- `docs/contracts/README.md`: Contract package entry point.
- `scripts/validate-docs.sh`: Current executable validation entry point.

### Configuration

- `mkdocs.yml`: Arabic Material MkDocs navigation and strict build configuration.
- `.gitlab-ci.yml`: Current documentation CI stages and jobs.
- `.markdownlint.json`: Markdown lint rules.
- `.editorconfig`: Repository text formatting configuration.
- `requirements-docs.txt`: Documentation-site Python dependencies.
- `docs/catalog.yaml`: Governed document metadata and source-of-truth declarations.

### Core Logic

- `scripts/validate-docs.sh`: Implemented repository validation logic.
- `scripts/render-diagrams.sh`: Implemented Mermaid rendering logic.
- `docs/architecture/module-catalog.md`: Canonical target module responsibilities and names.
- `docs/architecture/context-map.md`: Target dependency matrix, fact ownership, and integration patterns.
- `docs/architecture/dependency-rules.md`: Target rank DAG, transaction, and boundary enforcement rules.
- `docs/contracts/api/openapi.yaml`: Target HTTP interface contract.
- `docs/contracts/events/asyncapi.yaml`: Target event transport/channel contract.

### Testing

- `scripts/validate-docs.sh`: Current repository validation suite; no separate automated test files exist.
- `.gitlab-ci.yml`: Runs documentation validation and strict documentation build.
- `docs/engineering/testing-strategy.md`: Target product testing strategy only.
- `docs/architecture/non-functional-requirements.md`: Target architecture verification goals only.

## Naming Conventions

### Files

- Use lowercase kebab-case for governed Markdown and Mermaid sources, such as `docs/architecture/dependency-rules.md` and `docs/architecture/diagrams/outbox-sequence.mmd`, following `CONTRIBUTING.md`.
- Use zero-padded numeric prefixes for ADRs, such as `docs/adr/022-portfolio-projects-and-risk-boundaries.md`, following `docs/adr/README.md`.
- Use `README.md` as the index within major documentation directories, as in `docs/architecture/README.md` and `docs/domain/README.md`.
- Use `.schema.json` for reusable JSON Schema resources, as in `docs/contracts/schemas/record-facts.schema.json`.
- Use lower camelCase `operationId` values in OpenAPI, such as `submitWorkRecord` in `docs/contracts/api/openapi.yaml`.
- Use past-tense PascalCase domain event names, such as `WorkRecordSubmitted`, in `docs/contracts/events/asyncapi.yaml` and `docs/architecture/module-catalog.md`.

### Directories

- Use lowercase kebab-case for documentation subject directories, such as `docs/data-security/`.
- Use canonical PascalCase module names in the future logical `Modules/<Module>/` tree, such as `Modules/WorkRecords/`, exactly as listed in `docs/architecture/module-catalog.md`.
- Use PascalCase business verbs for future slice directories, such as `Features/SubmitWorkRecord/`, based on `docs/engineering/vertical-slices.md`.
- Keep generated outputs separate from sources: `build/diagrams/` receives SVGs from `docs/architecture/diagrams/` via `scripts/render-diagrams.sh`.

## Where to Add New Code

### Current Repository Feature: Documentation

- Primary specification: Add or update the owning subject document under `docs/<subject>/`, using ownership and status from `docs/catalog.yaml`.
- Architecture decision: Add a new numbered file under `docs/adr/` from `docs/adr/template.md`; update `docs/adr/README.md`, `docs/catalog.yaml`, and `mkdocs.yml`.
- Domain behavior: Update the owning file under `docs/domain/` and keep module ownership aligned with `docs/architecture/module-catalog.md`.
- API contract: Update `docs/contracts/api/openapi.yaml` and relevant schemas under `docs/contracts/schemas/`.
- Event contract: Update `docs/contracts/events/asyncapi.yaml` and relevant versioned schema under `docs/contracts/schemas/`.
- Architecture diagram: Add/edit Mermaid source in `docs/architecture/diagrams/`, index it in `docs/architecture/c4-and-flows.md`, then render locally with `scripts/render-diagrams.sh`.
- Validation automation: Add documentation-only checks to `scripts/validate-docs.sh` and invoke them from `.gitlab-ci.yml` when CI separation is needed.

### Future Backend Feature

- Primary code: Use logical `Modules/<CanonicalModule>/Features/<BusinessVerb>/` from `docs/engineering/vertical-slices.md`.
- Shared module domain: Use logical `Modules/<CanonicalModule>/Domain/` only for stable rules reused by that module.
- Published synchronous API: Use logical `Modules/<CanonicalModule>/Contracts/` with stable DTOs and declared errors.
- Published events: Use logical `Modules/<CanonicalModule>/Events/` and keep schemas compatible with `docs/contracts/events/asyncapi.yaml`.
- Infrastructure: Use logical `Modules/<CanonicalModule>/Infrastructure/`; module-owned migrations belong with that owner according to `docs/engineering/database-migrations.md`.
- Tests: Co-locate slice tests in logical `Modules/<CanonicalModule>/Features/<BusinessVerb>/Tests/`, plus module-level architecture/contract tests as required by `docs/engineering/testing-strategy.md`.
- Important: `Modules/<Module>/` is the only filesystem shape currently documented. The concrete Laravel parent directory and Composer namespace are not yet specified; establish them during the product scaffold rather than silently assuming `app/Modules/` (`docs/engineering/vertical-slices.md`).

### Future Frontend Feature

- Implementation: Add it to the single React + TypeScript application mandated by `docs/adr/009-unified-react-shell.md`.
- Module contribution: Keep route/navigation registration behind module contracts so the shell does not know each module's details (`docs/adr/009-unified-react-shell.md`).
- Security: Consume backend-filtered responses and treat hidden routes/actions only as UX; do not implement sensitive policy as a client-side boundary (`docs/architecture/overview.md`).
- Important: No authoritative document names the frontend source directory or test directory, so choose and document those paths as part of the initial scaffold before adding features.

### Utilities

- Shared backend helpers: Limit future shared code to clocks, identifiers, transaction/Outbox primitives, and neutral technical types, as required by `docs/architecture/module-catalog.md`.
- Documentation helpers: Keep current maintenance scripts in `scripts/` and avoid embedding product runtime logic there (`README.md`).
- Do not create a generic cross-module `Services/`, `Repositories/`, or domain `Shared/` bucket; use the owner module and its feature slice (`docs/engineering/vertical-slices.md`).

## Special Directories

### `docs/`

- Purpose: Authoritative current repository content and target-system source of truth.
- Generated: No; governed source files are manually maintained, with metadata in `docs/catalog.yaml`.
- Committed: Yes.

### `docs/architecture/diagrams/`

- Purpose: Authoritative editable Mermaid source.
- Generated: No.
- Committed: Yes.

### `docs/contracts/schemas/`

- Purpose: Machine-readable target contract resources.
- Generated: No according to `docs/catalog.yaml`.
- Committed: Yes.

### `build/`

- Purpose: Local rendered documentation artifacts.
- Generated: Yes, by `scripts/render-diagrams.sh`.
- Committed: No; ignored by `.gitignore`.

### `site/`

- Purpose: MkDocs output configured by `mkdocs.yml`.
- Generated: Yes, by `mkdocs build --strict` in `.gitlab-ci.yml`.
- Committed: No; ignored by `.gitignore` and retained only as a one-week CI artifact.

### `.planning/archive/`

- Purpose: Historical GSD planning material.
- Generated: Workflow-managed.
- Committed: Repository-dependent, but always non-authoritative for current architecture in this mapping; use `docs/` instead.

### `.planning/codebase/`

- Purpose: Generated codebase maps consumed by GSD planning and execution.
- Generated: Yes.
- Committed: Workflow-dependent; do not treat it as a replacement for `docs/` authority.

### `.opencode/`

- Purpose: Local agent/workflow runtime and configuration.
- Generated: Mixed installed and configured tooling.
- Committed: Repository-dependent; not product source regardless of Git status.

---

*Structure analysis: 2026-07-15*
