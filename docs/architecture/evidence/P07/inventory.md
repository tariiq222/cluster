# Cluster E2E Runner Readiness Inventory (P07 Phase A)
#
> Source-grounded inventory of existing E2E runner surfaces, configs, and
> scripts. Inventory only; no source change.
>
> Approved plan: 2026-07-26-cluster-e2e-runner-readiness.md (removed 2026-08-01; see git history)
> Source commit: df2588c

version: 1
baseline_date: '2026-07-27'
source_commit: df2588c
plan: 2026-07-26-cluster-e2e-runner-readiness.md (removed 2026-08-01; see git history)

# ----------------------------------------------------------------------------
# 1. Existing E2E runner surfaces
# ----------------------------------------------------------------------------

runner_surfaces:
  - path: infra/platform/production/run-local-e2e.sh
    type: shell wrapper
    purpose: bounded lifecycle start/run/stop
    status: existing; P07 modifies after all dependency handoffs
  - path: infra/platform/production/compose.yaml
    type: docker compose
    purpose: production topology
    status: existing; P07 read-only
  - path: infra/platform/production/compose.test.yaml
    type: docker compose
    purpose: bounded test topology
    status: existing; P07 read-only
  - path: infra/platform/production/.env.example
    type: environment
    purpose: production env template
    status: existing; P07 read-only
  - path: infra/platform/production/Caddyfile
    type: reverse proxy
    purpose: HTTPS termination
    status: existing; P07 read-only
  - path: infra/platform/production/verify-images.sh
    type: shell
    purpose: image verification
    status: existing; P07 read-only
  - path: infra/platform/production/test-caddyfile.sh
    type: shell
    purpose: Caddyfile test
    status: existing; P07 read-only

# ----------------------------------------------------------------------------
# 2. Web e2e surfaces
# ----------------------------------------------------------------------------

web_e2e:
  - path: apps/web/e2e/capability-navigation.spec.ts
  - path: apps/web/e2e/dashboard-navigation-browser-qa.spec.ts
  - path: apps/web/e2e/day2-workflow.spec.ts
  - path: apps/web/e2e/day3-r1.spec.ts
  - path: apps/web/e2e/documents.spec.ts
  - path: apps/web/e2e/login.spec.ts
  - path: apps/web/e2e/org-hierarchy-tree.spec.ts
  - path: apps/web/e2e/personal-work.spec.ts
  - path: apps/web/e2e/platform-settings-comprehensive.spec.ts
  - path: apps/web/e2e/shell.spec.ts (P05 source ref)
  - path: apps/web/playwright.production.config.ts
    type: playwright config
    status: existing; P07 modifies after P05 handoff

# ----------------------------------------------------------------------------
# 3. CI runners
# ----------------------------------------------------------------------------

ci:
  - path: .github/workflows/ci.yml
  - path: .github/workflows/ci-e2e.yml

# ----------------------------------------------------------------------------
# 4. Status
# ----------------------------------------------------------------------------

status:
  plan_status: blocked
  start_dependencies:
    - ARCHITECTURE-CLOSURE:T13-HANDOFF  # released; commit df2588c + ARCHITECTURE-CLOSURE.md
    - P01  # blocked
    - P02  # blocked
    - P03  # blocked
    - M07  # blocked
  ungated_inventory_lane: open
  implementation_lane: blocked until dependencies complete

# ----------------------------------------------------------------------------
# 5. Inventory totals
# ----------------------------------------------------------------------------

totals:
  runner_surfaces: 7
  web_e2e_specs: 10
  ci_workflows: 2
  scripts_to_create_in_implementation: 7
  scripts_to_modify_in_implementation: 2

# ----------------------------------------------------------------------------
# 6. Approval
# ----------------------------------------------------------------------------

approval:
  decision: pending-user-authorization
  recorded_in: docs/architecture/evidence/P07/inventory.md
  not_authorized:
    - Modifying infra/platform/production/run-local-e2e.sh
    - Modifying apps/web/playwright.production.config.ts
    - Creating scripts/emit-connection-manifest.mjs
    - Creating scripts/validate-connection-manifest.mjs
    - Creating scripts/validate-production-e2e-manifest.mjs
    - Implementing the bounded lifecycle
