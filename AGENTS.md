# Repository Guidelines

## Project Overview

Cluster is a healthcare-cluster administrative platform implemented as a Laravel modular monolith with a React/Vite web client. It provides identity/session management, RBAC+ABAC authorization, organization structure, independent organization-scoped tasks, documents, notifications, search, reporting, and platform settings. WorkRecords, WorkDefinitions, and Workflow are retired. The API and web client share versioned OpenAPI contracts.

## Architecture & Data Flow

- **API entry**: `apps/api/routes/web.php` exposes `/api/v1` routes. `apps/api/bootstrap/app.php` wires the route surface and JSON API exception handling.
- **Request boundary**: correlation IDs, cookie sessions, and local/testing fixture bearer auth are handled by `IdentitySessionMiddleware`; mutations additionally pass `IdentityCsrfMiddleware` with `X-CSRF-Token`.
- **Module flow**: module-owned controller → validation/capability check → feature handler/application service → module-owned persistence adapter. Keep business controllers, SQL/table access, transactions, and outbox ownership inside the owning module.
- **API semantics**: mutations commonly require `Idempotency-Key` and `If-Match`; responses use JSON envelopes, `application/problem+json`, correlation IDs, ETags, and lock versions.
- **Dependency injection**: `apps/api/app/Providers/AppServiceProvider.php` is the composition root for authorization, persistence, outbox/Redis streams, storage, malware scanning, and integration adapters.
- **Web flow**: `apps/web/src/main.tsx` creates the React root; `App.tsx` restores the cookie-backed session and handles global 401 expiry. `apps/web/src/router.tsx` declares the React Router route tree; `AppShell` renders the capability-filtered sidebar and the `Outlet`.
- **Web API access**: screens use domain wrappers under `apps/web/src/api/`; generated Orval clients route through `apps/web/src/api/fetcher.ts` and the shared transport in `apps/web/src/api/http.ts`. Do not build raw request headers in screens.
- **Module boundaries**: `apps/api/tests/Architecture/ModuleBoundariesTest.php` enforces module ranks, import direction, table ownership, controller placement, and transaction/outbox rules.

## Key Directories

- `apps/api/app/` — Laravel application infrastructure, middleware, providers, and shared API concerns.
- `apps/api/Modules/` — business modules, organized by feature/application, domain contracts, and infrastructure/persistence.
- `apps/api/Shared/` — cross-module contracts and shared infrastructure; avoid importing module internals.
- `apps/api/routes/` — API route declarations, primarily `routes/web.php`.
- `apps/api/tests/` — PHPUnit unit/feature/security tests and architecture guards.
- `apps/web/src/` — React/Vite application, shell, routes, API wrappers, generated clients, and feature screens.
- `apps/web/e2e/` — Playwright browser journeys (`*.spec.ts`).
- `apps/web/.orval/` — generated API reference output; do not hand-edit generated output.
- `docs/contracts/api/openapi.yaml` — the single authoritative OpenAPI contract; Orval consumes it directly.
- `scripts/` — documentation/contract validation, route inventory, reconciliation, security and production checks.
- `infra/` — local development and production Docker/Compose workflows.

## Development Commands

Run from the repository root unless noted:

```sh
make verify-intake       # verify lockfiles and manifest/package integrity
make test-api            # Laravel/PHPUnit suite
make test-web            # web build, lint, and coverage
make test-web-unit       # Vitest only
make verify-boundaries   # module architecture guard
make lint-api            # Laravel Pint check
make analyse-api         # PHPStan/Larastan
make test-e2e-w1-1       # local W1.1 browser workflow
make verify-w1-1         # broad local quality and integration gate
```

API commands:

```sh
cd apps/api && composer setup
cd apps/api && composer dev
cd apps/api && composer test
composer --working-dir=apps/api lint
composer --working-dir=apps/api analyse
```

Web commands:

```sh
npm --prefix apps/web ci
npm --prefix apps/web run dev
npm --prefix apps/web run build
npm --prefix apps/web run lint
npm --prefix apps/web run test:unit
npm --prefix apps/web run coverage
npm --prefix apps/web run api:check
npm --prefix apps/web run api:generate
npm --prefix apps/web run test:e2e:list
npm --prefix apps/web run test:e2e:local
```

Contract documentation validation is driven by `scripts/validate-docs.sh`; it requires `python3` and the referenced validation scripts/contracts to exist in the checkout.

## Code Conventions & Common Patterns

- **PHP namespaces/autoloading**: PSR-4 `App\\` → `apps/api/app`, `Modules\\` → `apps/api/Modules`, `Shared\\` → `apps/api/Shared`.
- **Module ownership**: depend on `Contracts`/`Events`, not another module’s domain or infrastructure internals. Direct cross-owner SQL, foreign keys, and table access are prohibited by architecture tests.
- **HTTP handlers**: validate at the module HTTP boundary, authorize capabilities early, then delegate to a handler/service. Follow existing typed exceptions and `problem+json` conversion.
- **Persistence/concurrency**: use module-owned adapters, transactions, row locks where required, optimistic `lock_version`/ETag checks, cursor pagination, and the shared transactional outbox contract.
- **Frontend state**: prefer local React hooks (`useState`, `useEffect`, `useCallback`) with explicit loading/success/denied/error/unsupported states. Use `Promise.all` only for genuinely independent requests.
- **Frontend design**: `docs/design/DESIGN-RULES.md` is binding for every new screen or component — theme tokens, the four allowed layout patterns, RTL logical properties, and the seven resource states. `docs/design/PAGES.md` specifies what each page is and how it must render.
- **Frontend routing/access**: routes are declared in `apps/web/src/router.tsx`; the sidebar is filtered by capabilities from `/me`. Hiding is cosmetic — the server is the only guard. Feature-gated destinations are absent entirely when the flag is off, because the API answers with a non-disclosing 404.
- **Generated API code**: update the authoritative contract and run the API generation/check scripts. Never edit `apps/web/src/api/generated` or `.orval` output by hand.
- **Naming**: API tests use PHPUnit conventions; web unit tests use `*.test.ts`/`*.test.tsx`; Playwright journeys use `*.spec.ts`.
- **Error handling**: centralize transport parsing in `http.ts`; map 401/403/404/409/412 consistently rather than duplicating response handling in screens.

## Important Files

- `Makefile` — canonical local verification targets and integration gates.
- `apps/api/composer.json` — PHP/Laravel versions, PSR-4 mappings, Composer scripts.
- `apps/api/bootstrap/app.php` — Laravel/API bootstrap.
- `apps/api/routes/web.php` — route contract and middleware groups.
- `apps/api/app/Providers/AppServiceProvider.php` — dependency-injection bindings.
- `apps/api/app/Http/Middleware/IdentitySessionMiddleware.php` — session/correlation boundary.
- `apps/api/app/Http/Middleware/IdentityCsrfMiddleware.php` — mutation CSRF boundary.
- `apps/api/tests/Architecture/ModuleBoundariesTest.php` — architectural rules.
- `apps/web/src/main.tsx` and `apps/web/src/App.tsx` — web entry/session bootstrap.
- `apps/web/src/router.tsx` — route tree; `apps/web/src/app/AppShell.tsx` — capability-filtered navigation.
- `docs/design/DESIGN-RULES.md` and `docs/design/PAGES.md` — binding design system and page specifications.
- `apps/web/src/api/http.ts` and `apps/web/src/api/fetcher.ts` — shared API transport/mutator.
- `apps/web/package.json` — web build, test, lint, contract, and E2E scripts.
- `apps/web/vitest.config.ts` and `apps/web/playwright.config.ts` — test discovery and browser settings.
- `.github/workflows/ci.yml` — CI quality gates.

## Runtime/Tooling Preferences

- **API**: PHP `^8.3`, Laravel `^13.8`, Composer, PHPUnit 12.5, PHPStan/Larastan, Laravel Pint.
- **Web**: Node.js 22 in CI, npm with `apps/web/package-lock.json`, TypeScript ~6, React 19, Vite 8, Vitest 4, Playwright 1.61.1, oxlint, Prettier.
- **Other tooling**: Python 3.12 is used by production/contract scripts; `gitleaks` is a CI/local secret scan.
- Prefer existing Make targets and package scripts over ad hoc commands. Keep generated artifacts reproducible from the contract sources.
- Local infrastructure may require MySQL, Redis, MinIO, and ClamAV via `infra/` workflows. Default API tests avoid external services with SQLite/array/synchronous test infrastructure.

## Testing & QA

- **API**: `cd apps/api && composer test`; suites cover `tests/Unit`, `tests/Feature`, and module-owned tests. Default configuration uses in-memory SQLite, array cache/session/mail, synchronous queues, and deterministic fixtures.
- **Architecture**: run `make verify-boundaries` after moving modules, controllers, persistence, or cross-module references. Keep `ModulePlacementInventory.php` synchronized with approved module placement.
- **Web unit/component**: `npm --prefix apps/web run test:unit`; Vitest discovers `src/**/*.test.ts` and `src/**/*.test.tsx`. `coverage` emits text/LCOV and enforces 100% coverage for the configured `src/api.ts` include.
- **Browser**: `npm --prefix apps/web run test:e2e:list` needs no running API; local execution uses localhost-only `W1_1_API_ORIGIN` and starts Vite on `W1_1_WEB_PORT` (default 4173). Tests are serial, one worker, zero retries, `forbidOnly`, Arabic locale by default, and retain traces on failure.
- **Database integration**: `make verify-mysql-integration` targets the MySQL suite in `apps/api/phpunit.mysql.xml` for walking-skeleton and concurrency behavior; it may skip when `pdo_mysql`/MySQL is unavailable.
- **CI**: `.github/workflows/ci.yml` runs API validation, Pint, PHPStan, dependency audit, PHPUnit, architecture checks, web build/lint/coverage, secret scanning, and production-bundle verification.
- Use semantic assertions based on observable API/UI behavior. For browser tests, prefer role/label locators and explicit localhost fixtures; do not leave focused tests in committed code.
