# Deferred Items

## 2026-07-15 — Pre-existing documentation-validator JSON failures

- **Files:** `apps/web/tsconfig.app.json`, `apps/web/tsconfig.node.json`
- **Finding:** `./scripts/validate-docs.sh` treats TypeScript JSONC comments as invalid JSON.
- **Scope:** Introduced by the preceding scaffold plan; this plan does not modify either TypeScript configuration file.
- **Disposition:** Deferred outside Plan 01-03 to preserve task scope. `make test-web` still passes, confirming TypeScript accepts the intended JSONC configuration.

## 2026-07-15 — Browser E2E runner remains an explicit Wave 0 dependency

- **Files:** `apps/web/e2e/walking-skeleton.spec.ts`, `apps/web/package.json`
- **Finding:** The locked web toolchain has no approved Playwright package or configuration (`@playwright/test` is absent).
- **Scope:** Plan 01-03 adds the browser acceptance specification but cannot execute it without changing the locked dependency intake outside its listed files.
- **Disposition:** Deferred to the approved frontend E2E-toolchain intake. `make test-e2e-w1-1-red` executes the equivalent backend acceptance contract and confirms the required route-level RED state; it does not claim a browser run passed.
