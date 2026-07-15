# Stack Research

**Project:** Third Health Cluster enterprise administrative platform (R1-R3)  
**Domain:** Arabic-first, air-gapped enterprise administrative platform  
**Researched:** 2026-07-15  
**Overall recommendation confidence:** MEDIUM  
**Version-evidence confidence:** LOW under the mandatory GSD provider classifier (`webfetch --verified`), despite using official project pages and registries. Re-verify every patch at the controlled dependency-intake gate.

## Executive Recommendation

Implement the accepted architecture as a deliberately conventional stack: **Laravel 13.20 on PHP 8.5.8**, a separately built **React 19.2.7 + TypeScript 6.0.3 + Vite 8.1.4** SPA, **MySQL 8.4 LTS**, and internal Kubernetes services. Use Laravel's own transactions, queues, policies, encrypted casts, migrations, scheduler, and JSON resources before adding packages. Libraries must assist the 19 canonical modules; they must not define module boundaries, authorization semantics, workflow semantics, or the transactional outbox.

Use **Node 24.18 LTS only in build and test images**. Production web assets are static and served locally; Node is not a runtime dependency. Run PHP-FPM behind a local Nginx tier, Horizon workers as separate Kubernetes workloads, and one scheduler workload. Valkey, OpenSearch, object storage, and all observability endpoints are internal services only.

Treat infrastructure versions below as an **evaluation baseline**, not a reversal of the accepted deferred-decision gates. Kubernetes distribution, object-storage product, MySQL Operator build, backup destination, retention, and detailed observability sizing still require their documented operational and security approval. Once approved, deploy only mirrored artifacts pinned by digest.

## Locked Decisions Preserved

This recommendation does not change the accepted baseline:

- One Laravel modular monolith, one MySQL operational database, and exactly 19 canonical modules.
- One React/TypeScript shell for all roles, Arabic by default with complete English and RTL/LTR support.
- Custom centralized, explainable RBAC + ABAC in the backend; frontend hiding is never an authorization boundary.
- Caller-owned transactions and a custom transactional outbox saved atomically with business state.
- No cross-module table access, ORM leakage, Event Sourcing, early microservices, external identity provider, SaaS, CDN, public runtime registry, or external notification gateway.
- Derived Search, Reporting, Workspace, and Notifications projections are rebuildable and never sources of truth.
- MySQL InnoDB Cluster, Valkey, S3-compatible object storage, OpenSearch, Loki, metrics, Vault, internal package mirrors, and internal OCI registry remain the accepted runtime roles.

## Recommended Application Stack

### Core Technologies

| Technology | 2026 baseline | Purpose | Why this version | Confidence |
|---|---:|---|---|---|
| Laravel | `13.20.0` (`^13.0` plus `composer.lock`) | HTTP/API, application services, queues, scheduler, persistence | Current supported major; Laravel 13 requires PHP 8.3-8.5 and receives security fixes until 2028-03-17. It fits the team's Laravel strength and the accepted monolith. | LOW, official release pages |
| PHP | `8.5.8` | Web, CLI, workers, scheduler | Supported by Laravel 13; PHP 8.5 has active support through 2027 and security support through 2029, giving a greenfield system more runway than 8.4. | LOW, official PHP source |
| Composer | `2.x`, current approved patch | Reproducible PHP dependency resolution | Use `composer.lock`, an internal Composer repository, plugin allowlisting, and exact artifact checksums. Composer is build-time only. | MEDIUM recommendation |
| React / React DOM | `19.2.7` | Unified browser shell | Current documented React 19 line and current registry patch. Use client rendering; SSR adds no value to this authenticated internal platform. | LOW, official docs/registry |
| TypeScript | `6.0.3` | Strict frontend types | Deliberately one major behind current 7.0.2: current `typescript-eslint` 8.64 supports TypeScript `<6.1`, so 6.0.3 preserves type-aware linting. Enable `strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, and project references. | MEDIUM compatibility recommendation |
| Vite | `8.1.4` | Frontend development and production bundle | Current supported release; works with Node 24 and emits static assets with no runtime Vite server. | LOW, official docs/registry |
| Node.js | `24.18.0` LTS | Frontend build, lint, tests, code generation | Official production policy recommends LTS; Node 26 is Current, not LTS. Node must not be present in the final PHP runtime image. | LOW, official release page |
| npm | Version bundled with approved Node 24 image | Frontend package manager | Lowest operational novelty for a small team and Nexus npm proxy. Commit `package-lock.json` and use only `npm ci`. | MEDIUM recommendation |
| MySQL | `8.4 LTS`; validate current `8.4.x` patch (official manual covers through `8.4.9`) | Sole operational database | LTS rather than Innovation releases; matches the accepted MySQL/InnoDB Cluster decision. Exact server, Router, Shell, and Operator builds must be approved as one tested matrix. | LOW, official Oracle manual |
| OpenAPI | `3.1` | API contract between Laravel and React | Gives contract tests and reproducible client generation without GraphQL or a second backend. | MEDIUM recommendation |

### PHP Runtime Profile

Use an internally built Linux image pinned by digest with PHP 8.5.8 FPM and only required extensions:

`bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `intl`, `json`, `mbstring`, `openssl`, `pcntl`, `pdo_mysql`, `redis`, `sodium`, `tokenizer`, and `xml`.

- **Web pods:** Nginx `1.28.x` stable sidecar/front tier + PHP-FPM. Do not use Octane, RoadRunner, or FrankenPHP in R1; long-lived application memory adds state-reset and operational risks the team does not need.
- **Worker pods:** one `php artisan horizon` process per container; scale replicas with Kubernetes. Do not run Supervisor inside the container.
- **Scheduler:** one `php artisan schedule:work` workload, with `onOneServer`/`withoutOverlapping` locks in Valkey for protected jobs.
- **CLI/migrations:** a release Job using the same immutable application image. Migrations are not run in web-pod startup probes.

## Recommended Laravel Packages

### Runtime Packages

| Package | Baseline | Purpose | Prescriptive use |
|---|---:|---|---|
| `laravel/fortify` | `1.37.2` | Local login, password reset, TOTP MFA endpoints | Use as an adapter inside `Identity`; `Identity` remains owner of accounts, recovery approval, lockout, privileged-account policy, and audit events. |
| `laravel/sanctum` | `4.3.2` | Same-origin SPA cookie authentication and CSRF | Use stateful cookie/session mode, not long-lived browser bearer tokens. Secure, HTTP-only, SameSite cookies and server-side session revocation are mandatory. |
| `laravel/horizon` | `5.47.2` | Valkey-backed queue supervision and metrics | Use for async outbox delivery and projections. Horizon does not replace the custom outbox table, inbox/idempotency records, or business retry policy. |
| `league/flysystem-aws-s3-v3` | `3.x`, approved patch | S3-compatible document object storage | Hide behind `Documents` storage ports. Store object keys/checksums in MySQL; never expose public buckets or trust object URLs as authorization. |
| `opensearch-project/opensearch-php` | `2.6.0` | OpenSearch client | Only the `Search` infrastructure adapter imports it. Add compatibility tests against the approved OpenSearch 3.x cluster before promotion. |
| `zircote/swagger-php` | `6.4.0` | Generate OpenAPI 3.1 from PHP attributes | Keep operation/schema metadata near API DTOs, generate a reviewed canonical spec in CI, and fail on contract drift. Do not annotate domain entities or expose ORM models. |
| `open-telemetry/api`, `open-telemetry/sdk`, OTLP exporter | Current mutually compatible `1.x` set | Traces and metrics export | Instrument HTTP, queue, database, outbox, and projection lag. Export only to the internal Collector and redact PII, payloads, tokens, and document names. Verify exact package set together at intake. |

### Laravel and PHP Development Tools

| Package/tool | Baseline | Purpose | Gate |
|---|---:|---|---|
| Pest | `4.7.5` | Primary PHP test syntax | Unit, application, contract, API, authorization, and architecture tests. |
| PHPUnit | `13.2.4` | Pest engine and lower-level test framework | Pin the Pest-compatible version selected by Composer; do not independently force an incompatible patch. |
| `pestphp/pest-plugin-laravel` | Laravel-13-compatible `4.x` | Laravel test integration | Use real MySQL for database behavior tests; SQLite is not an adequate substitute for JSON, locking, collation, or transaction semantics. |
| Larastan | `3.10.0` | Laravel-aware static analysis | PHPStan level 8 initially, then level 9/maximum after baseline cleanup; no growing baseline file. Release notes confirm PHPStan 2.2 compatibility. |
| Laravel Pint | `1.29.3` | PHP formatting | Run in CI with a committed configuration. |
| Infection | `0.34.0` | Mutation testing | Run against changed critical domain/authorization/workflow code and enforce the documented 80% critical mutation target. |
| Xdebug or PCOV | Current PHP-8.5-compatible approved patch | Coverage | Use PCOV for fast CI coverage; keep Xdebug in development/debug images only. Neither enters production. |

### Laravel Primitives to Implement In-House

Do not add packages for these locked concerns:

- **Modules:** PSR-4 namespaces and module service providers under `app/Modules/<CanonicalModule>/...`; Composer, PHPStan, Pest architecture tests, and custom table-ownership tests enforce the DAG.
- **Authorization:** custom `Authorization` module returning `AccessDecision`, `ScopePredicate`, field decisions, and explanations; Laravel gates/policies are adapters at request boundaries.
- **Outbox/inbox:** MySQL tables, transaction-aware publisher, `SELECT ... FOR UPDATE SKIP LOCKED` claiming, retry/dead-letter policy, and consumer `event_id` uniqueness. No broker is required.
- **Workflow and definitions:** constrained, versioned interpreters. Never accept PHP, JavaScript, SQL, templates, callbacks, or dynamic imports from definitions.
- **Audit:** append-only tables and controlled query endpoints. Application logs and Loki are not the audit ledger.
- **PII encryption:** Laravel encrypted casts/encrypter with versioned keys delivered from Vault; use approved deterministic hashes only for explicitly required exact-match lookup. Do not invent searchable encryption casually.

## Recommended React/TypeScript Packages

### Runtime Libraries

| Library | Baseline | Purpose | Prescriptive use |
|---|---:|---|---|
| `react-router-dom` | `7.18.1` | Route registry and navigation | Modules export route descriptors to the shell. Keep routes lazy and capability metadata declarative; the API still re-authorizes every request. |
| `@tanstack/react-query` | `5.101.2` | Server-state cache, mutations, invalidation | The default async-state layer. Query keys include organization/scope and locale where relevant; clear all cached data on logout or identity change. |
| `react-hook-form` | `7.81.0` | Performant forms | Use for both fixed forms and the constrained dynamic form renderer. Server validation remains authoritative. |
| `@hookform/resolvers` | `5.4.0` | Form/schema bridge | Use the Zod resolver for developer-authored fixed forms only. |
| `zod` | `4.4.3` | Runtime validation of static UI data and generated boundaries | Validate configuration and non-generated boundary data. Do not duplicate the dynamic WorkDefinitions DSL in arbitrary Zod schemas. |
| `i18next` | `26.3.6` | Translation catalog and locale selection | Bundle Arabic and English resources into the image. No remote translation backend or runtime fetch outside the application origin. |
| `react-i18next` | `17.0.9` | React i18n bindings | Set `lang` and `dir` at the document root; verify runtime language switching without reload. |
| `@mui/material` | `9.2.0` | Accessible enterprise component baseline | Use MUI Core only, with one owned design theme. Do not use MUI X commercial grids/charts unless a later licensing ADR approves them. |
| Emotion (`@emotion/react` `11.14.0`, `@emotion/styled` `11.14.1`, `@emotion/cache` `11.14.0`) | Exact listed patches | MUI styling and RTL cache | Create separate LTR/RTL caches with `stylis-plugin-rtl` `2.1.1`. Prefer CSS logical properties and test mirrored interaction, not just text alignment. |
| `@tanstack/react-table` | `8.21.3` | Headless data tables | Combine with MUI primitives for server-side pagination/sort/filter. Do not download all rows into the browser. |
| Apache ECharts | `6.1.0` | R2/R3 dashboards and charts | Import only required chart modules, self-host assets, provide accessible text/table alternatives, and format Arabic labels through `Intl`. |

### Frontend Tooling and Tests

| Tool | Baseline | Purpose | Notes |
|---|---:|---|---|
| `@vitejs/plugin-react` | `6.0.3` | React Fast Refresh and Vite compilation | Compatible with Vite 8. Do not enable experimental React Compiler until a measured, reversible ADR approves it. |
| ESLint | `9.39.4` | Type-aware linting and React rules | Use 9 rather than current 10 because current JSX accessibility tooling declares support through ESLint 9. Flat config is mandatory. |
| `typescript-eslint` | `8.64.0` | Type-aware TypeScript parser/rules | Supports ESLint 9 and TypeScript `>=4.8.4 <6.1.0`; this compatibility range is the reason to baseline TypeScript 6.0.3 rather than 7. |
| `eslint-plugin-react-hooks` | `7.1.1` | Hooks correctness | Enable recommended rules; compatible with ESLint 9. |
| `eslint-plugin-jsx-a11y` | `6.10.2` | Static JSX accessibility rules | Enable its strict/recommended rules. Its peer range stops at ESLint 9, so do not force ESLint 10. |
| Vitest | `4.1.10` | Unit/component tests | Current package declares Vite 8 support. Use `jsdom` only for component tests. |
| React Testing Library | `16.3.2` | Behavior-focused component tests | Query by accessible role/name in Arabic and English. |
| MSW | `2.15.0` | API mocks in component tests | Generate fixtures from committed contracts; do not let mocks become a second undocumented API. |
| Playwright | `1.61.1` | Browser E2E | Mirror the Playwright image/browser binaries internally. Test Chromium plus the organization's approved browser matrix, Arabic/English, RTL/LTR, permissions, upload/download, and session expiry. |
| `@axe-core/playwright` | `4.12.1` | Automated accessibility checks | Add to critical Playwright journeys; retain manual keyboard, screen-reader, contrast, and RTL review. |
| OpenAPI Generator | `7.23.0` internal OCI image | Generate TypeScript `typescript-fetch` client | Run in CI from the canonical OpenAPI spec. Commit generated output or package it as an internal artifact and fail when regeneration produces a diff. |

### Frontend State Rule

Use React Router for navigational state, TanStack Query for server state, React Hook Form for form state, and local component/context state for shell concerns. **Do not add Redux, Zustand, MobX, Apollo, or another global store in R1.** Add one only after a documented state problem cannot be represented by those four layers.

## Recommended Platform Evaluation Baseline

These versions are current 2026 evaluation targets. They must not bypass the accepted platform-selection, capacity, support, backup, licensing, and security gates.

| Capability | Evaluation baseline | Recommendation and air-gap implication | Confidence |
|---|---:|---|---|
| Kubernetes fallback distribution | RKE2 `v1.36.2+rke2r1` / Kubernetes `1.36.2` | Greenfield baseline with support to 2027-06-28. Import the complete RKE2 air-gap archives and checksums to Harbor. Use bundled Traefik; ingress-nginx retired in 2026 and is not a greenfield choice. | LOW, official sources |
| Ingress | Bundled Traefik `3.7.4` in RKE2 1.36.2 | Avoid a separately managed ingress lifecycle unless the enterprise Kubernetes distribution supplies one. Internal TLS only. | LOW, RKE2 release |
| MySQL HA | MySQL `8.4 LTS` InnoDB Cluster + MySQL Router | Select an Oracle-supported Operator/server/router/shell matrix. The publicly indexed `mysql/mysql-operator` tags observed were stale, so exact Operator version is a mandatory unresolved gate, not something to guess. | LOW / unresolved operator patch |
| Cache and queue | Valkey `9.1.0` HA | Current release; verify PhpRedis, Horizon, failover, persistence, eviction separation, and queue-loss behavior. Use separate logical/runtime policies for cache, session, queue, and locks. | LOW, official source |
| User-facing search | OpenSearch `3.7.0` with security distribution | Current official release. Use only as an authorized derived index. Do not deploy the minimal distribution because it lacks important security features. | LOW, official source |
| Operational logs | Loki `3.7.3` | Keep labels low-cardinality and redact sensitive fields at source. Use an OpenTelemetry Collector/approved log agent; Promtail support was removed in Loki 3.7. | LOW, official release |
| Metrics | Prometheus `3.13.1` LTS + Alertmanager `0.33.1` | Internal scrape/alert path. Size retention and HA at the observability gate; do not use remote-write SaaS. | LOW, official source |
| Dashboards | Grafana `13.1.0` | Internal operations UI only; provision data sources/dashboards from Git, disable external calls, public sharing, telemetry, and plugin downloads. | LOW, official release |
| Telemetry gateway | OpenTelemetry Collector Contrib `0.156.0` | Receive OTLP internally and route to approved metrics/log/trace backends. Mirror the image and configuration schemas. | LOW, official release |
| Secrets/PKI role | Vault `2.0.3` candidate | Accepted role for secrets and PKI. Licensing/support, HA, unseal, backup, and disaster recovery require explicit approval. Applications receive short-lived/injected secrets; no internet validation. | LOW, official release |
| Object storage | **Product deferred**; S3-compatible with versioning, encryption, lifecycle, and Object Lock | Do not lock MinIO or another product in application planning. Code only to the S3 adapter and test the selected product at the platform gate. Backup storage remains outside the Kubernetes failure domain. | HIGH alignment with accepted docs |

## Air-Gapped Supply Chain Stack

### Prescriptive Toolchain

| Function | Baseline | Use |
|---|---:|---|
| Source and CI | GitLab Self-Managed `19.1.x` | Internal Git, merge requests, runners, release evidence. Pin runners and helper images; disable public shared runners and external includes. |
| Composer/npm mirror | Nexus Repository `3.94.x` | Hosted + proxy/staged Composer and npm repositories. Production builds resolve only internal URLs. Preserve approved package tarballs for disaster recovery. |
| OCI registry | Harbor `2.15.2` | Store base, build, application, Kubernetes, Playwright, scanner, and GitOps images. Enforce immutable tags while deployments use digests. |
| GitOps | Argo CD `3.4.5` | Pull from internal GitLab and Harbor only. `Staging`/`Prod` writes occur through reviewed Git changes; break-glass follows the same audited path. |
| Signing/attestation | Cosign `3.1.1` | Key-based offline signing with keys protected by Vault/HSM. Store signatures, SBOM attestations, and bundles in Harbor; do not depend on public Fulcio/Rekor/TUF at runtime. |
| SBOM | Syft `1.46.0` | Emit CycloneDX JSON and SPDX for each immutable image and release artifact. Attach by digest. |
| Vulnerability/license/IaC scan | Trivy `0.72.0` | One scanner for images, filesystems, secrets, config, and licenses; mirror its vulnerability databases through the controlled intake process. Do not also mandate Grype unless independent verification is explicitly required. |
| Admission policy | Kyverno `1.18.2` | Verify approved registry, digest use, signatures/attestations, non-root settings, resource limits, probes, NetworkPolicy, and forbidden capabilities. Keep policies in GitOps. |

### Required Offline Build Pattern

1. Controlled intake downloads source packages, exact tarballs/images, signatures, checksums, licenses, and vulnerability databases outside production.
2. Security and architecture approve the dependency/version and import it into Nexus/Harbor.
3. GitLab Runner has egress only to internal GitLab, Nexus, Harbor, Vault, and required internal services.
4. Build uses `composer install` and `npm ci` from lockfiles; package-manager configuration fails closed if an external URL is encountered.
5. Multi-stage images compile React with Node 24, install PHP production vendors, and copy only built assets/vendor/application files into the runtime image.
6. Syft creates SBOMs; Trivy scans source, dependencies, image, IaC, licenses, and secrets using mirrored databases.
7. Cosign signs image digest and attestations with an internal key. Kyverno verifies them before admission.
8. RKE2, Playwright browsers, Helm charts, fonts, timezone data, antivirus definitions, and scanner databases are treated as artifacts in the same intake flow—not downloaded by CI or pods.

## Installation Baseline

Versions shown are initial constraints. The committed lockfiles and mirrored artifact hashes are the actual build inputs.

```bash
# Backend runtime (from internal Composer mirror)
composer require \
  laravel/framework:^13.0 \
  laravel/fortify:^1.37 \
  laravel/sanctum:^4.3 \
  laravel/horizon:^5.47 \
  league/flysystem-aws-s3-v3:^3.0 \
  opensearch-project/opensearch-php:^2.6 \
  zircote/swagger-php:^6.4

# Backend development/test
composer require --dev \
  pestphp/pest:^4.7 \
  pestphp/pest-plugin-laravel:^4.0 \
  larastan/larastan:^3.10 \
  laravel/pint:^1.29 \
  infection/infection:^0.34

# Frontend runtime (from internal npm registry)
npm install --save-exact \
  react@19.2.7 react-dom@19.2.7 react-router-dom@7.18.1 \
  @tanstack/react-query@5.101.2 @tanstack/react-table@8.21.3 \
  react-hook-form@7.81.0 @hookform/resolvers@5.4.0 zod@4.4.3 \
  i18next@26.3.6 react-i18next@17.0.9 \
  @mui/material@9.2.0 echarts@6.1.0 \
  @emotion/react@11.14.0 @emotion/styled@11.14.1 \
  @emotion/cache@11.14.0 stylis-plugin-rtl@2.1.1

# Frontend development/test
npm install --save-dev --save-exact \
  typescript@6.0.3 vite@8.1.4 @vitejs/plugin-react@6.0.3 \
  eslint@9.39.4 typescript-eslint@8.64.0 \
  eslint-plugin-react-hooks@7.1.1 eslint-plugin-jsx-a11y@6.10.2 \
  vitest@4.1.10 \
  @testing-library/react@16.3.2 msw@2.15.0 \
  @playwright/test@1.61.1 @axe-core/playwright@4.12.1
```

Before executing this baseline, verify Fortify, the Pest Laravel plugin, Emotion/RTL packages, OpenTelemetry packages, PHP extensions, and every transitive dependency against PHP 8.5, TypeScript 6, React 19, licensing policy, and internal mirror availability. `composer.lock` and `package-lock.json` must be produced once in the controlled intake/build environment and committed.

## Version Compatibility and Upgrade Rules

| Relationship | Rule |
|---|---|
| Laravel 13 ↔ PHP | Laravel officially supports PHP 8.3-8.5; baseline PHP 8.5.8. Run the complete test suite on every PHP patch intake. |
| React 19 ↔ MUI 9 | MUI 9 declares React 17/18/19 peers; baseline React 19.2.7. |
| Vite 8 ↔ Node | Vite requires Node `^20.19 || >=22.12`; use Node 24 LTS, not Node 26 Current. |
| Vite 8 ↔ Vitest 4 | Vitest 4.1.10 declares Vite 6/7/8 compatibility. |
| TypeScript 6 ↔ lint/codegen | `typescript-eslint` 8.64 accepts TypeScript `<6.1`; generate with the pinned OpenAPI Generator image and compile generated output under TS 6. Do not move to TypeScript 7 until the complete lint/codegen/test matrix declares or demonstrates compatibility. |
| Laravel ↔ Valkey | Use the Redis protocol through PhpRedis and Horizon; run failover, delayed-job, lock, session-revocation, and prefix-isolation tests against Valkey 9.1. |
| OpenSearch PHP 2.6 ↔ OpenSearch 3.7 | Treat as a tested adapter contract, not assumed major lockstep. Index templates and query DSL get integration tests against the exact cluster image. |
| MySQL server ↔ Operator | Approve server, Router, Shell, backup image, and Operator together. Never independently bump one component in production. |
| Kubernetes ↔ add-ons | Validate every Operator, CSI, CNI, Kyverno, Argo CD, monitoring chart, and CRD against Kubernetes 1.36 before promotion. |

Patch policy:

- Application libraries: lock exact resolved versions; review monthly and expedite security fixes.
- Laravel major: annual planned upgrade; never remain beyond security support.
- PHP: remain on a supported branch; patch through the controlled intake flow.
- Kubernetes/RKE2: stay on a supported minor and plan upgrades before EOL; rehearse air-gap and rollback procedures in Staging.
- Stateful services: backup, restore, failover, rollback compatibility, and performance tests are mandatory before upgrade.
- Frontend generated output: regeneration diff, type-check, component tests, and E2E must pass before a generator/spec bump.

## Alternatives Considered

| Recommended | Alternative | Why not for R1-R3 |
|---|---|---|
| Laravel 13 modular monolith | Microservices | Contradicts accepted architecture and burdens a 2-4 person team with distributed transactions and operations. |
| PHP-FPM + Nginx | Octane/RoadRunner/FrankenPHP | Useful only after profiling proves FPM cannot meet targets; long-lived workers create state-reset and extension-compatibility risks. |
| React SPA + Vite | Next.js/Remix SSR | Authenticated internal pages do not need SEO/SSR; adds a Node runtime and deployment surface. React Router is sufficient. |
| REST/OpenAPI | GraphQL | Adds schema/resolver authorization complexity and makes field-level leakage harder to govern. |
| Custom Authorization module + Laravel policies | `spatie/laravel-permission` or client roles | Generic role packages cannot own the accepted explainable RBAC+ABAC, record facts, scope predicates, classification, delegations, and field decisions. |
| Custom outbox/inbox on MySQL | Kafka/RabbitMQ/event sourcing | No external broker is needed for the target scale; accepted architecture requires MySQL atomicity and at-least-once delivery, not an event-sourced system. |
| OpenSearch adapter | Laravel Scout with an Elasticsearch-only/community driver | Search authorization, projection schema, checkpoints, and rebuild behavior are domain-specific and must remain in `Search`. |
| MUI Core + TanStack Table | MUI X Data Grid | Avoid commercial/runtime licensing ambiguity and vendor-specific grid coupling. |
| Argo CD | Direct `kubectl` from CI | Accepted operations require GitOps as the only write path to Staging/Prod. |
| RKE2 1.36 + Traefik fallback | New ingress-nginx deployment | ingress-nginx retired in March 2026; RKE2 1.36 defaults to Traefik. |

## What NOT to Use

| Avoid | Why | Use instead |
|---|---|---|
| `nwidart/laravel-modules` or similar as the architecture | Folder generation does not enforce the canonical DAG, data ownership, contracts, or no-cross-table rules. | Explicit module namespaces/providers plus static, architecture, and database guards. |
| `spatie/laravel-permission` as authorization truth | Role/permission tables alone cannot implement the locked contextual and explainable ABAC model. | Custom `Authorization` module and Laravel policy adapters. |
| Event Sourcing/CQRS frameworks | Event Sourcing is explicitly out of scope; framework aggregates tend to obscure transaction ownership. | Light commands/queries and a custom transactional outbox. |
| Redux/Zustand by default | Duplicates server state and increases invalidation/leakage risk across organization scope changes. | Router + TanStack Query + form/local state. |
| Axios | Native Fetch and generated `typescript-fetch` are sufficient; another HTTP abstraction adds interceptors and duplicate semantics. | Generated Fetch client with one owned auth/error wrapper. |
| Public CDNs, remote fonts, remote maps, analytics, error SaaS, feature-flag SaaS | Violate air-gap and may leak metadata. | Bundle approved fonts/assets and use internal telemetry/configuration. |
| Mutable OCI tags such as `latest` | Not reproducible and cannot bind evidence/SBOM/signature to deployed bytes. | Internal immutable tag for humans plus digest in manifests. |
| Package install during pod startup | Violates immutability and air-gap acceptance. | Vendor/build everything in CI from internal mirrors. |
| SQLite as the main automated integration database | Hides MySQL collation, JSON, locking, transaction, and DDL behavior. | Ephemeral MySQL 8.4 for integration/contract tests. |
| OpenSearch minimal distribution | Official page warns that it lacks important security features. | Full security-enabled OpenSearch distribution. |
| Promtail in a new deployment | Loki 3.7 removed Promtail support. | OpenTelemetry Collector or another approved internal log agent. |
| Vault Transit call on every row read | Creates a hard network dependency in the synchronous data path. | Inject/version envelope keys securely; reserve online Vault operations for lifecycle/rotation workflows. |
| Browser bearer tokens in `localStorage` | XSS makes tokens retrievable and undermines server-side session controls. | Sanctum stateful HTTP-only session cookies + CSRF. |

## Roadmap Implications

1. **Foundation phase:** approve PHP/Node base images, package constraints, monorepo layout, module guard tests, OpenAPI generation, internal mirrors, lockfile policy, and reproducible offline build before feature work.
2. **Identity/Authorization phase:** integrate Fortify/Sanctum only behind canonical modules; build custom access decisions and broad denial tests before exposing business records.
3. **Transactional infrastructure phase:** implement MySQL migrations, outbox/inbox, Horizon/Valkey, audit, object-storage adapter, and deterministic integration tests.
4. **Unified shell phase:** establish MUI theme, Arabic/English catalogs, RTL/LTR caches, route registry, generated API client, accessibility gates, and query-cache isolation before module screens multiply.
5. **Platform gate:** resolve the exact MySQL Operator matrix and object-storage product; approve RKE2/enterprise Kubernetes, Vault, OpenSearch, observability, backup, and supply-chain versions with restore and air-gap evidence.
6. **Every later phase:** use the same stack; adding a runtime service, framework, global state store, module package, or external integration requires an ADR rather than local developer preference.

## Open Verification Gates

- **MySQL Operator exact version:** official MySQL documentation endpoints returned access errors and indexed public Docker tags were stale. Operations must verify the current Oracle-supported Operator/server/router/shell/backup matrix before roadmap commitment.
- **Object storage:** product selection remains intentionally deferred. Validate S3 API behavior, versioning, Object Lock, encryption, HA, backup independence, support, and licensing.
- **Vault 2 licensing/support:** the accepted role is preserved, but the chosen edition and support model require procurement/security approval.
- **PHP 8.5 transitive compatibility:** Laravel supports it, but all extensions and optional packages must pass the intake matrix before the base image is frozen.
- **TypeScript 7 adoption:** current type-aware lint tooling excludes TypeScript 7. Keep 6.0.3 until `typescript-eslint`, Vite, test tools, generated client, and application all pass a dedicated upgrade branch; then record the major bump normally.
- **Arabic search:** validate OpenSearch analyzers, normalization, authorization filters, highlight suppression, index rebuild, and relevance on representative Arabic data before Search acceptance.

## Sources

### Canonical project sources (HIGH confidence for locked decisions)

- `.planning/PROJECT.md`
- `docs/architecture/overview.md`
- `docs/architecture/module-catalog.md`
- `docs/operations/air-gap-supply-chain.md`
- `docs/operations/kubernetes-platform.md`
- `docs/engineering/testing-strategy.md`
- Accepted ADRs 009, 012, 014, 018, and 019.

### Official version sources (GSD provider tier LOW; checked 2026-07-15)

- Laravel 13 releases/support: https://laravel.com/docs/13.x/releases
- Laravel framework current release: https://github.com/laravel/framework/releases/latest
- PHP supported branches: https://www.php.net/supported-versions.php
- PHP 8.5 release JSON: https://www.php.net/releases/index.php?json&version=8.5&max=1
- React versions: https://react.dev/versions
- Node releases: https://nodejs.org/en/about/previous-releases
- Vite releases/requirements: https://vite.dev/releases and https://vite.dev/guide/
- TypeScript package: https://registry.npmjs.org/typescript/latest
- TypeScript 6.0.3 and lint compatibility metadata: https://registry.npmjs.org/typescript/6.0.3, https://registry.npmjs.org/typescript-eslint/8.64.0, https://registry.npmjs.org/eslint/9.39.4, and https://registry.npmjs.org/eslint-plugin-jsx-a11y/6.10.2
- Fortify Laravel-13 compatibility metadata: https://repo.packagist.org/p2/laravel/fortify.json
- Emotion/RTL compatibility metadata: https://registry.npmjs.org/@emotion%2freact/11.14.0, https://registry.npmjs.org/@emotion%2fstyled/11.14.1, https://registry.npmjs.org/@emotion%2fcache/11.14.0, and https://registry.npmjs.org/stylis-plugin-rtl/2.1.1
- MySQL 8.4 manual: https://docs.oracle.com/cd/E17952_01/mysql-8.4-en/
- Kubernetes releases: https://kubernetes.io/releases/
- RKE2 releases and air-gap guide: https://github.com/rancher/rke2/releases and https://docs.rke2.io/install/airgap
- Valkey downloads: https://valkey.io/download/
- OpenSearch downloads: https://opensearch.org/downloads.html
- Loki: https://github.com/grafana/loki/releases/latest
- Prometheus: https://prometheus.io/download/
- Grafana: https://github.com/grafana/grafana/releases/latest
- Vault: https://github.com/hashicorp/vault/releases/latest
- OpenTelemetry Collector: https://github.com/open-telemetry/opentelemetry-collector-releases/releases/latest
- Harbor, Argo CD, Cosign, Syft, Trivy, Kyverno official GitHub release pages.
- npm registry `latest` metadata for the listed React libraries and test tools.

---
*Stack research for the Third Health Cluster platform; all external version snapshots require controlled re-verification before import.*
