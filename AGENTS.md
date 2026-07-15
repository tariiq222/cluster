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

## Languages

- Markdown - The product currently exists as architecture, delivery, and implementation-plan documentation under `docs/`; no Laravel or React application source is present at the repository root.
- JavaScript (CommonJS) - Implemented local OpenCode/GSD automation in `.opencode/plugins/gsd-core.js`, `.opencode/hooks/*.js`, `.opencode/scripts/**/*.cjs`, and `.opencode/gsd-core/bin/**/*.cjs`.
- JSON - OpenCode and GSD configuration/state in `.opencode/opencode.json`, `.opencode/settings.json`, `.opencode/gsd-install-state.json`, and `.opencode/gsd-file-manifest.json`.
- PHP - Selected for the future Laravel modular monolith, but no `composer.json`, `artisan`, or `*.php` product files are present; the decision is recorded in `docs/architecture/architecture-blueprint.md` and `docs/architecture/adr/001-modular-monolith.md`.
- TypeScript - Selected for the future React application, but no `package.json`, `tsconfig.json`, or `*.ts`/`*.tsx` product files are present; the decision is recorded in `docs/architecture/adr/009-unified-react-shell.md`.
- Python 3.11+ - Specified for the delivery controller in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`, although the referenced `tools/codex/r3_flow/*.py` implementation is absent from this checkout.

## Runtime

- Node.js v22.22.2 is the detected host runtime for the implemented CommonJS OpenCode/GSD tooling in `.opencode/`.
- Python 3.9.6 is the detected host interpreter; this does not satisfy the Python 3.11+ delivery-controller requirement documented in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`.
- PHP 8.5.7 and Composer 2.10.1 are installed on the host, but the product does not pin or consume them because no Laravel manifest or scaffold exists.
- The future production environment is an air-gapped, on-premises Kubernetes cluster according to `docs/architecture/adr/010-air-gapped-kubernetes.md`.
- npm 11.18.0 is available and `npx` launches the local GSD MCP server from `.opencode/opencode.json`.
- Composer 2.10.1 is available for the selected Laravel stack, but no root `composer.json` exists.
- Lockfile: missing at the repository root; `.opencode/.gitignore` also excludes OpenCode-local `package-lock.json` and `bun.lock` files.

## Frameworks

- OpenCode plugin API - Hosts the implemented GSD adapter in `.opencode/plugins/gsd-core.js`; the local configuration is `.opencode/opencode.json`.
- GSD Core 1.7.0 - Installed development workflow engine, versioned by `.opencode/gsd-core/VERSION` and `.opencode/gsd-file-manifest.json`.
- Laravel, version not pinned - Accepted future backend framework and modular-monolith boundary in `docs/architecture/architecture-blueprint.md`; not yet scaffolded in this checkout.
- React + TypeScript, versions not pinned - Accepted future unified frontend shell in `docs/architecture/adr/009-unified-react-shell.md`; not yet scaffolded in this checkout.
- No executable repository test framework is present in the current checkout.
- Node tooling carries installed GSD runtime code under `.opencode/gsd-core/`, but no project-level test command or dependency manifest is present.
- Python `unittest` is the specified standard-library test runner for the absent delivery controller in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`.
- Future product tests are defined conceptually as Laravel domain/application/HTTP/authorization tests, React feature tests, contract tests, outbox/idempotency tests, and system tests in `docs/architecture/architecture-blueprint.md` and `docs/architecture/vertical-slices.md`.
- OpenCode + local GSD plugin - Agent workflow, hooks, commands, and skills are installed beneath `.opencode/`.
- `@opengsd/gsd-core` MCP server - Launched through `npx -y -p @opengsd/gsd-core gsd-mcp-server` by `.opencode/opencode.json`.
- Git/Make/Codex CLI - Specified for autonomous delivery in `docs/superpowers/specs/2026-07-15-codex-autonomous-delivery-design.md`; the root `Makefile`, `.codex/`, and `tools/codex/` artifacts described there are absent from this checkout.
- OCI images, internal registry, internal Composer/npm mirrors, and signed release bundles are required for future production builds by `docs/architecture/adr/010-air-gapped-kubernetes.md`.

## Key Dependencies

- Node.js standard library (`fs`, `path`, `os`, `child_process`) - Powers the implemented plugin/hook bridge in `.opencode/plugins/gsd-core.js`; no third-party runtime dependency is declared in `.opencode/package.json`.
- `@opengsd/gsd-core` 1.7.0 installed payload - Provides local commands, hooks, skills, and CommonJS runtime under `.opencode/gsd-core/`; the installed version is recorded in `.opencode/gsd-file-manifest.json`.
- Laravel, React, and TypeScript - Product-level framework choices are accepted in `docs/superpowers/specs/2026-07-15-third-health-cluster-enterprise-platform-design.md`, but package versions and concrete dependencies remain unselected.
- MySQL, version not pinned - Chosen operational source of truth in `docs/architecture/architecture-blueprint.md` and `docs/architecture/adr/010-air-gapped-kubernetes.md`.
- Kubernetes, distribution/version not selected - Required on-premises deployment platform in `docs/architecture/adr/010-air-gapped-kubernetes.md`.
- Redis-compatible cache/queue is implied by the `REDIS` deployment node in `docs/architecture/diagrams/c4-and-flows.md`; no product or version is selected.
- S3-compatible object storage, provider/version not selected - Required by `docs/architecture/adr/008-documents-search-reporting.md`.
- Internal search engine, provider/version not selected - Required by `docs/architecture/adr/008-documents-search-reporting.md` and `docs/architecture/diagrams/c4-and-flows.md`.
- Central logs, metrics, alerts, internal file scanning, secret management, and backup storage are required capabilities without selected implementations in `docs/superpowers/specs/2026-07-15-third-health-cluster-enterprise-platform-design.md`.

## Configuration

- Implemented tooling configuration is JSON-based: `.opencode/opencode.json` enables the GSD MCP server and grants access to `.opencode/gsd-core/`; `.opencode/settings.json` is currently empty.
- No `.env` or `.env.*` files were detected. Product environment-variable names are not defined because the application scaffold is absent.
- Optional installed GSD integrations inspect environment keys such as `BRAVE_API_KEY` and GSD runtime controls in `.opencode/gsd-core/bin/lib/config.cjs`; these belong to development tooling, not the product runtime.
- Future product configuration must work offline, use internal package/image sources, pin dependencies, and deny network egress by default as required by `docs/architecture/adr/010-air-gapped-kubernetes.md`.
- `.opencode/package.json` only declares CommonJS module mode and does not define scripts or dependencies.
- No root build files (`composer.json`, product `package.json`, `tsconfig.json`, Vite config, Dockerfile, Helm chart, or Kubernetes manifest) are present.
- The intended application layout is documented, not implemented, in `docs/architecture/architecture-blueprint.md` (`app/Modules/` and `resources/js/`).

## Platform Requirements

- Use Node.js with npm/npx to run the implemented `.opencode/` GSD integration; exact Node/npm versions are not pinned by repository files.
- Python 3.11+ is required before restoring or implementing the delivery controller described in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`; the detected Python 3.9.6 is insufficient.
- Add and lock PHP/Laravel, Composer, Node/React/TypeScript, and frontend build versions when P1-01 scaffolds application code; no usable version contract currently exists.
- Development and verification must not assume public internet access for the product path; internal Composer/npm/OCI mirrors are mandated in `docs/architecture/adr/010-air-gapped-kubernetes.md`.
- Deploy on-premises inside an air-gapped Kubernetes environment with replicated Web/API and queue workers, a singleton or leader-elected scheduler, MySQL HA, cache/queue HA, object storage, search, and observability as shown in `docs/architecture/diagrams/c4-and-flows.md`.
- Capacity targets are 5,000-20,000 accounts and up to 2,000 concurrent users in `docs/superpowers/specs/2026-07-15-third-health-cluster-enterprise-platform-design.md`.
- Recovery targets are RPO at most 15 minutes and RTO at most two hours; backup storage must be encrypted and independent of the Kubernetes failure domain per `docs/architecture/adr/010-air-gapped-kubernetes.md` and the enterprise design specification.
- Product containers and dependencies must come from internal registries/mirrors; releases require SBOMs and signed, verifiable bundles according to `docs/architecture/adr/010-air-gapped-kubernetes.md`.

<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->

## Conventions

## Naming Patterns

- Use lowercase kebab-case for executable JavaScript modules and hooks: `.opencode/gsd-core/bin/lib/normalize-test-command.cjs`, `.opencode/hooks/gsd-worktree-path-guard.js`, and `.opencode/scripts/fix-slash-commands.cjs`.
- Use `.cjs` for explicit CommonJS modules and `.js` for host-loaded hooks/plugins; `.opencode/package.json` declares only `{"type":"commonjs"}`.
- Keep subsystem modules in matching directories instead of generic utility buckets: observability code belongs under `.opencode/gsd-core/bin/lib/observability/`, and changeset code belongs under `.opencode/scripts/changeset/`.
- Name planned Python tests `test_<subject>.py` under `tools/codex/tests/`; this convention is specified in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`. These files are not present in the current tree.
- Use date-prefixed, kebab-case names for approved designs and plans, as in `docs/superpowers/specs/2026-07-15-codex-autonomous-delivery-design.md` and `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`.
- Use lower camelCase for JavaScript functions: `normalizeTestCommand()` in `.opencode/gsd-core/bin/lib/normalize-test-command.cjs`, `evaluateLint()` in `.opencode/scripts/changeset/lint.cjs`, and `resolveRepoRoot()` in `.opencode/plugins/gsd-core.js`.
- Prefix module-private helpers with `_` only where a file deliberately distinguishes internal helpers, as with `_safeStringify()`, `_toAuditRecord()`, and `_appendAuditLine()` in `.opencode/gsd-core/bin/lib/observability/logger.cjs`; otherwise use ordinary camelCase helpers.
- Use verb-led names that state behavior (`validateActiveWorkstreamName`, `stripWatchFlags`, `createDefaultLogger`) rather than generic `handle` or `process` names.
- Use `cmd<Name>` for functions directly exposed as `gsd-tools` command handlers, exemplified by `cmdNormalizeTestCommand()` in `.opencode/gsd-core/bin/lib/normalize-test-command.cjs`.
- Use lower camelCase for local values and parameters (`rawCmd`, `packageDir`, `fragmentFailures`).
- Use uppercase snake case for module constants and frozen reason-code collections: `MAX_COMMAND_LENGTH` in `.opencode/gsd-core/bin/lib/normalize-test-command.cjs`, `LINT_REASON` in `.opencode/scripts/changeset/lint.cjs`, and `ERROR_REASON` in `.opencode/gsd-core/bin/lib/io.cjs`.
- Prefix intentionally module-private mutable state with `_`, such as `_jsonErrorMode` in `.opencode/gsd-core/bin/lib/io.cjs` and `_writeSleepBuf` in the same file.
- Use snake_case lowercase strings for stable machine-readable reason values, such as `config_parse_failed` and `missing_frontmatter` in `.opencode/gsd-core/bin/lib/io.cjs` and `.opencode/scripts/changeset/parse.cjs`.
- Use PascalCase for JavaScript classes and error types, such as `ExitError` in `.opencode/scripts/lib/cli-exit.cjs` and `RuntimeBuildError` in `.opencode/gsd-core/bin/ensure-runtime-build.cjs`.
- Represent closed JavaScript enums as `Object.freeze({...})`, as demonstrated by `FRAGMENT_ERROR` in `.opencode/scripts/changeset/parse.cjs` and `LINT_REASON` in `.opencode/scripts/changeset/lint.cjs`.
- Return discriminated result objects for validation/parsing (`{ ok: true, ... }` or `{ ok: false, reason, ... }`) rather than relying on message text; examples are `parseFragment()` in `.opencode/scripts/changeset/parse.cjs` and `validateActiveWorkstreamName()` in `.opencode/gsd-core/bin/lib/workstream-name-policy.cjs`.
- For the planned Python controller, use PascalCase dataclasses such as `FlowState`, `ActionRecord`, `Gate`, and `GateResult`, with snake_case functions and fields, as prescribed in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`.

## Code Style

- No repository-level Prettier, Biome, EditorConfig, or equivalent formatter configuration is present under `/Users/tariq/code/R3`.
- Preserve subtree-local formatting. Handwritten files under `.opencode/scripts/` and `.opencode/hooks/` consistently use two-space indentation, single quotes, semicolons, and blank lines between declarations; see `.opencode/scripts/changeset/parse.cjs` and `.opencode/hooks/gsd-worktree-path-guard.js`.
- `.opencode/plugins/gsd-core.js` uses two-space indentation, double quotes, semicolons, trailing commas in multiline calls, and wide explanatory comments. Match that file when changing the plugin adapter.
- Files under `.opencode/gsd-core/bin/lib/` often carry emitted TypeScript/CommonJS structure: four-space indentation, `node_*_1.default` imports, optional-chaining transforms, and `Object.defineProperty(exports, ...)`; see `.opencode/gsd-core/bin/lib/config.cjs` and `.opencode/gsd-core/bin/lib/workstream-name-policy.cjs`.
- Treat emitted files that state “TypeScript source of truth” as generated distribution artifacts. Do not normalize them to handwritten style or remove emit scaffolding; the source files are not included in this repository snapshot.
- Run `git diff --check` for whitespace validation when a Git worktree exists; this required check is specified in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`.
- No checked-in ESLint configuration or runnable lint script is present in `.opencode/package.json`; it contains only the CommonJS module type.
- Existing emitted files retain ESLint directives such as `@typescript-eslint/no-require-imports` in `.opencode/gsd-core/bin/lib/config.cjs` and `n/no-process-exit` in `.opencode/gsd-core/bin/gsd-tools.cjs`. Preserve narrowly scoped `eslint-disable-next-line` directives and include the exact rule name.
- Do not call `process.exit()` from reusable CLI logic. Throw `ExitError` and let `runMain()` set `process.exitCode`, following `.opencode/scripts/lib/cli-exit.cjs`. Direct `process.exit()` remains an entrypoint/hook protocol pattern in files such as `.opencode/hooks/gsd-worktree-path-guard.js`.
- Do not introduce an unverified lint command until a manifest and configuration define it. The planned R3 quality gate accepts package-specific build, type-check, and lint commands, but no current command is installed.

## Import Organization

- No path aliases are configured. Use relative CommonJS paths with explicit extensions, such as `require('./io.cjs')` in `.opencode/gsd-core/bin/lib/config.cjs`.
- Planned Python controller imports use the package name `r3_flow` with `PYTHONPATH=tools/codex`, as documented in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`.

## Error Handling

- Use stable reason codes for behavior that tests or callers consume. `error(message, ERROR_REASON.<CODE>)` in `.opencode/gsd-core/bin/lib/io.cjs` can emit `{ ok: false, reason, message }`; parsers return reason enums from `.opencode/scripts/changeset/parse.cjs`.
- Keep pure validation separate from CLI presentation. `evaluateLint()` returns a typed verdict while `main()` chooses stdout/stderr and exit status in `.opencode/scripts/changeset/lint.cjs`.
- Throw for programmer errors or invariant violations, as `assertValidActiveWorkstreamName()` does in `.opencode/gsd-core/bin/lib/workstream-name-policy.cjs`; return a verdict for expected invalid user input.
- Catch only when a fallback policy is explicit. Comments such as “best-effort,” “fail open,” or “fail closed” accompany catches in `.opencode/hooks/gsd-worktree-path-guard.js` and `.opencode/gsd-core/bin/lib/observability/logger.cjs`.
- Never silently convert a security-sensitive or destructive failure into success. The planned controller requirements in `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md` require malformed contracts, failed gates, unsafe Git state, and model mismatches to fail closed.
- Avoid shell interpolation for external commands. Pass executable and argv separately using `spawnSync`, `execFileSync`, or planned Python `subprocess.run(..., shell=False)`; see `.opencode/hooks/gsd-worktree-path-guard.js`, `.opencode/scripts/changeset/lint.cjs`, and the plan document.

## Logging

- Keep stdout machine-readable and reserve stderr for diagnostics. `output()` and `error()` implement this split in `.opencode/gsd-core/bin/lib/io.cjs`.
- Emit one structured JSON object per line for dispatch errors and optional audit events, following `.opencode/gsd-core/bin/lib/observability/logger.cjs`.
- Remain silent on success unless a CLI contract requires output; `createDefaultLogger()` is explicitly silent for successful events in `.opencode/gsd-core/bin/lib/observability/logger.cjs`.
- Redact arguments by default. `.opencode/gsd-core/bin/lib/observability/redaction.cjs` includes them only when `GSD_AUDIT_ARGS === '1'`; new logging must not expose prompts, credentials, tokens, or full environments.
- Use stable log paths and digest references for planned controller evidence rather than embedding raw subprocess output, per `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`.

## Comments

- Explain invariants, security boundaries, platform differences, bounded algorithms, and non-obvious protocol decisions. Strong examples are the linear-time regex rationale in `.opencode/scripts/changeset/parse.cjs` and worktree containment rationale in `.opencode/hooks/gsd-worktree-path-guard.js`.
- Annotate intentional catches and no-op branches (`/* best-effort */`, `/* intentionally empty */`, or a concrete fail-open reason) rather than leaving empty catches unexplained.
- Reference issue/ADR identifiers only when they explain a durable constraint; emitted modules such as `.opencode/gsd-core/bin/lib/io.cjs` consistently preserve these links.
- Do not narrate obvious assignments or restate function names.
- Use file-level JSDoc to describe module responsibility and runtime contract, as in `.opencode/gsd-core/bin/lib/normalize-test-command.cjs`.
- Add JSDoc to exported or behaviorally complex functions, documenting parameters, return shape, side effects, and safety limits.
- Handwritten CommonJS may use JSDoc type syntax, as `runMain()` does in `.opencode/scripts/lib/cli-exit.cjs`; emitted files retain TSDoc generated from their absent TypeScript source.

## Function Design

- Prefer an options object when a function has multiple optional or related inputs, as in `evaluateLint({ changedFiles, labels, fragmentFailures })` and `createDefaultLogger({ cwd, config })`.
- Inject paths, environment-sensitive dependencies, and executors where tests need isolation; avoid hidden global reads except at narrow runtime boundaries.
- Normalize and validate external strings at the boundary before filesystem or command use, following `.opencode/gsd-core/bin/lib/workstream-name-policy.cjs`.
- Return plain serializable objects for command and validation results.
- Use `{ ok, reason, ... }` discriminants for expected outcomes and frozen reason enums for machine contracts.
- Preserve caller input unchanged when a conservative transformation cannot classify it, as `normalizeTestCommand()` does in `.opencode/gsd-core/bin/lib/normalize-test-command.cjs`.

## Module Design

- Use explicit named CommonJS exports at the end of handwritten modules: `module.exports = { ... }`, as in `.opencode/scripts/changeset/parse.cjs` and `.opencode/scripts/lib/cli-exit.cjs`.
- Export pure logic alongside `main()` so tests can invoke behavior without spawning a process; guard execution with `if (require.main === module)`, as in `.opencode/scripts/changeset/lint.cjs`.
- Keep leaf modules dependent only on Node built-ins where possible; `.opencode/gsd-core/bin/lib/normalize-test-command.cjs` documents this constraint directly.
- No conventional JavaScript barrel-index pattern is present. Import the owning module directly rather than introducing an `index.cjs` aggregator.
- `.opencode/gsd-core/bin/gsd-tools.cjs` is a command dispatch entrypoint, not a general-purpose barrel; do not route internal dependencies through it.

<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->

## Architecture

## System Overview

```text

```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Architecture package index | Defines source-of-truth order and summarizes accepted decisions | `docs/architecture/README.md` |
| Architecture blueprint | Defines logical layers, target source layout, data flow, deployment, and test strategy | `docs/architecture/architecture-blueprint.md` |
| Module catalog | Assigns data, contracts, events, and dependencies to each bounded module | `docs/architecture/module-boundaries.md` |
| Vertical-slice rules | Defines write/read slice shape, frontend feature shape, and completion rules | `docs/architecture/vertical-slices.md` |
| C4 and flow model | Defines system, container, component, deployment, sequence, and state diagrams | `docs/architecture/diagrams/c4-and-flows.md` |
| ADR set | Records ten accepted architectural constraints | `docs/architecture/adr/README.md` |
| Delivery roadmap | Orders walking skeleton and later module plans | `docs/architecture/implementation-roadmap.md` |
| Enterprise product specification | Defines users, functional boundaries, security model, and rollout phases | `docs/superpowers/specs/2026-07-15-third-health-cluster-enterprise-platform-design.md` |
| Delivery design | Defines autonomous delivery architecture and operational safeguards | `docs/superpowers/specs/2026-07-15-codex-autonomous-delivery-design.md` |
| P1-00 plan/report | Documents the delivery-controller implementation contract and evidence, but referenced implementation files are absent from this checkout | `docs/superpowers/plans/2026-07-15-p1-00-codex-delivery-foundation.md`, `docs/delivery/phase-1/P1-00.md` |

## Pattern Overview

- Treat the module as the highest code and data ownership boundary; place target backend modules under `app/Modules/<Module>/` as prescribed by `docs/architecture/architecture-blueprint.md`.
- Group a module's changing use-case files under `Features/<BusinessAction>/`; keep reusable rules for that module in its own `Domain/`, as prescribed by `docs/architecture/vertical-slices.md`.
- Use Commands and Handlers for writes and Queries plus Read Models for reads. There is one operational MySQL database initially, not separate read/write stores and not event sourcing (`docs/architecture/architecture-blueprint.md`).
- Publish synchronous interfaces in each module's `Contracts/` and facts in `Events/`; never reach into another module's `Infrastructure/` or tables (`docs/architecture/adr/003-module-boundaries.md`).
- Use a single React shell with module route/navigation contributions rather than separate admin and user applications (`docs/architecture/adr/009-unified-react-shell.md`).
- Deploy one application as multiple Web/API and worker replicas; do not introduce business microservices without the extraction criteria in `docs/architecture/adr/001-modular-monolith.md`.

## Layers

- Purpose: Provide one adaptive Arabic-first, bilingual, RTL/LTR-capable user interface for all roles.
- Location: Target `resources/js/app/`, `resources/js/modules/`, `resources/js/platform/`, and `resources/js/shared/`; these directories are not present.
- Contains: Shell, router, session/scope handling, module features/pages, shared platform UI, API clients, and types.
- Depends on: Laravel HTTP API and backend-derived authorization/session data.
- Used by: Employees, managers, cluster officers, and super administrators described in `docs/superpowers/specs/2026-07-15-third-health-cluster-enterprise-platform-design.md`.
- Purpose: Own stable enterprise identity and organizational facts.
- Location: Target `app/Modules/Organization/`, `app/Modules/Identity/`, and `app/Modules/Authorization/`; these directories are not present.
- Contains: Organization tree, positions, supervisory relationships, users/sessions, roles, capabilities, delegations, classification, and field-access policy.
- Depends on: Only technical primitives in target `app/Shared/`; Authorization may consume published Organization and Identity contracts.
- Used by: Platform capabilities and business modules through contracts listed in `docs/architecture/module-boundaries.md`.
- Purpose: Provide reusable work definitions, workflows, tasks, documents, notifications, search, reporting, and audit without owning business meaning.
- Location: Target sibling modules under `app/Modules/`; exact catalog is in `docs/architecture/module-boundaries.md`.
- Contains: Published contracts, module-owned domain, vertical slices, events, persistence adapters, routes, and tests.
- Depends on: Core contracts and other explicitly listed platform contracts only.
- Used by: Requests, Strategy, PortfolioProjects, Risk, and later business modules.
- Purpose: Own specialist business state and terminology.
- Location: Target `app/Modules/Requests/`, `app/Modules/Strategy/`, `app/Modules/PortfolioProjects/`, and `app/Modules/Risk/`; these directories are not present.
- Contains: Module-specific domain models and use-case slices; Requests owns request closure semantics while Workflow owns workflow execution.
- Depends on: Platform and core contracts in the dependency matrix at `docs/architecture/module-boundaries.md`.
- Used by: React module pages and authorized inter-module consumers.
- Purpose: Adapt module contracts to Eloquent/MySQL, queue transport, object storage, search, clocks, IDs, and events.
- Location: Target per-module `Infrastructure/` plus intentionally small `app/Shared/`.
- Contains: Persistence mappings, providers, adapters, and neutral primitives only.
- Depends on: Runtime services; Domain code must not know HTTP, queues, search, or storage.
- Used by: Feature handlers through module-owned interfaces (`docs/architecture/vertical-slices.md`).
- Purpose: Current implemented repository surface; records architecture, plans, and operational reports.
- Location: `docs/architecture/`, `docs/superpowers/`, and `docs/delivery/`.
- Contains: Accepted decisions, diagrams, design specifications, an implementation plan, operator guide, and P1-00 report.
- Depends on: Cross-links between Markdown documents and image assets.
- Used by: Planners, implementers, reviewers, and operators.

## Data Flow

### Primary Write Request Path

### Authorized Read Path

### Cross-Module Event Flow

- Server-side operational state belongs to module-owned MySQL tables; table ownership is cataloged in `docs/architecture/module-boundaries.md`.
- Dynamic work records use a relational envelope, version-bound payload, typed projections, and explicit relation tables (`docs/architecture/adr/005-dynamic-work-data.md`).
- Workflow definitions are immutable after publication; running records pin `workflow_version_id` (`docs/architecture/adr/006-workflow-versioning.md`).
- React owns presentation/session context only and must not reproduce sensitive authorization rules (`docs/architecture/adr/009-unified-react-shell.md`).
- Search indexes and reporting Read Models are derived state, never a write-side source of truth (`docs/architecture/adr/008-documents-search-reporting.md`).

## Key Abstractions

- Purpose: Assign one owner to business meaning, contracts, events, tables, and migrations.
- Examples: Target `app/Modules/Requests/`, `app/Modules/Workflow/`; catalog at `docs/architecture/module-boundaries.md`.
- Pattern: Bounded context inside a deployable modular monolith.
- Purpose: Co-locate files that implement one user-visible outcome.
- Examples: Target `app/Modules/Requests/Features/SubmitRequest/` and `resources/js/modules/requests/features/create-request/`.
- Pattern: Command/Query handler with thin endpoint, explicit authorization, transaction, DTO, events, and co-owned tests (`docs/architecture/vertical-slices.md`).
- Purpose: Allow synchronous collaboration without exposing another module's internals or tables.
- Examples: `DecideAccess`, `ResolveDirectManager`, `StartWorkflow`, and `CreateTask` in `docs/architecture/module-boundaries.md`.
- Pattern: Consumer depends on an interface in the owner's target `Contracts/`; the owner supplies the implementation from its Infrastructure layer.
- Purpose: Protect invariants and meaningful state transitions inside the owning module.
- Examples: Proposed `Request.php`, `RequestStatus.php`, and `RequestNumber.php` in `docs/architecture/architecture-blueprint.md:183`.
- Pattern: Light DDD; introduce aggregates only for real consistency rules and do not create generic factories/repositories speculatively.
- Purpose: Reliably bridge a committed business change to asynchronous consumers.
- Examples: `RequestSubmitted`, `WorkflowCompleted`, and `ProjectHealthChanged` in `docs/architecture/module-boundaries.md`.
- Pattern: Versioned event envelope plus idempotent consumer and retry/dead-letter review (`docs/architecture/adr/007-events-and-outbox.md`).
- Purpose: Produce an explainable allow/deny and field-level result for every channel.
- Examples: `DecideAccess`, `FilterReadableOrganizationScopes`, `ResolveFieldAccess`, and `ExplainAccessDecision` in `docs/architecture/module-boundaries.md`.
- Pattern: Central RBAC capability constrained by ABAC context and module record-state policy (`docs/architecture/adr/004-authorization-and-isolation.md`).

## Entry Points

- Location: `docs/architecture/README.md`
- Triggers: A developer or planner starts architecture work.
- Responsibilities: Orders the authoritative design, boundaries, diagrams, roadmap, and ADR references.
- Location: `docs/superpowers/specs/2026-07-15-third-health-cluster-enterprise-platform-design.md`
- Triggers: Product, domain, scope, authorization, or rollout questions.
- Responsibilities: Defines intent and functional constraints that architecture must satisfy.
- Location: `docs/delivery/README.md`
- Triggers: An operator prepares to use the documented `r3-flow` process.
- Responsibilities: Documents prerequisites, commands, evidence, failure handling, and pilot acceptance. Its referenced `Makefile`, `.codex/`, and `tools/codex/` entry points are absent from this checkout.
- Location: Per-slice Controller and module `Routes/` under target `app/Modules/<Module>/`; not implemented.
- Triggers: Browser requests from the unified React application.
- Responsibilities: Validate transport input, dispatch command/query handlers, and serialize response DTOs.
- Location: Target `resources/js/app/` shell/router; not implemented.
- Triggers: Internal browser navigation.
- Responsibilities: Establish session and organizational scope, assemble module route/navigation contributions, and render pages.
- Location: Laravel queue workers and scheduler configured around module event consumers; not implemented.
- Triggers: Outbox records, queued jobs, and scheduled work.
- Responsibilities: Deliver events idempotently, update projections, notify users, index records, and run scheduled workflow actions.

## Architectural Constraints

- **Threading:** Request handling is process-based through replicated Laravel Web/API containers; asynchronous work runs in queue-worker replicas. A singleton or leader-elected scheduler handles scheduled jobs (`docs/architecture/architecture-blueprint.md:371`).
- **Global state:** No application global mutable state is implemented. Target runtime uses one operational MySQL database plus shared queue/cache services; ownership remains module-local even in shared infrastructure.
- **Circular imports:** No source imports exist to inspect. The required direction is `Business Modules -> Platform Contracts -> Core Contracts`; enforce it with architecture tests described in `docs/architecture/module-boundaries.md:361`.
- **Transactions:** One Handler owns the use-case transaction. Never extend it across a worker or external service; persist the outbox record in the same transaction (`docs/architecture/architecture-blueprint.md:272`).
- **Data ownership:** A module must not query or join another business module's tables. Use contracts, events, IDs, or governed Read Models (`docs/architecture/adr/003-module-boundaries.md`).
- **Security boundary:** React visibility is not authorization. Apply the same backend access decision to API, search, reporting, export, and document download (`docs/architecture/adr/004-authorization-and-isolation.md`).
- **Deployment:** All components operate on-premises in air-gapped Kubernetes with default-deny egress, internal registries/mirrors, signed artifacts, and independent backups (`docs/architecture/adr/010-air-gapped-kubernetes.md`).
- **Capacity:** Design target is 5,000–20,000 accounts and up to 2,000 concurrent users (`docs/architecture/architecture-blueprint.md:14`).
- **Current checkout:** There is no executable application or delivery controller in the filesystem. Validate architecture against source once target `app/`, `resources/js/`, and runtime manifests exist.

## Anti-Patterns

### Application-Wide Horizontal Service Buckets

### Cross-Module Persistence Access

### Frontend Authorization

### Unbounded Shared Folder

### Events as Hidden Commands

### Derived Stores as Sources of Truth

## Error Handling

- Reject malformed transport input in the slice's request validator and domain-invalid transitions in the Aggregate (`docs/architecture/vertical-slices.md`).
- Return stable response DTOs rather than leaking Eloquent models or infrastructure errors.
- Roll back state and outbox together when the use-case transaction fails (`docs/architecture/adr/007-events-and-outbox.md`).
- Retry background delivery without duplicating effects; move repeated failures to a reviewable terminal-error list.
- Keep the original transaction valid if notification or indexing fails (`docs/superpowers/specs/2026-07-15-third-health-cluster-enterprise-platform-design.md:656`).
- Pin workflow and work-definition versions so later configuration changes do not silently alter in-flight records (`docs/architecture/adr/006-workflow-versioning.md`).

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
