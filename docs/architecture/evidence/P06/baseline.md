# Cluster Quality and Performance Baseline (P06 Phase A)
#
> Source-grounded baseline of API/web tool output, bundle measurements,
> and existing query-count or artifact-size data. Baseline only; no source
> change.
>
> Approved plan: 2026-07-26-cluster-quality-performance-hardening.md (removed 2026-08-01; see git history)
> Source commit: df2588c

version: 1
baseline_date: '2026-07-27'
source_commit: df2588c
plan: 2026-07-26-cluster-quality-performance-hardening.md (removed 2026-08-01; see git history)

# ----------------------------------------------------------------------------
# 1. Tool inventory
# ----------------------------------------------------------------------------

tools:
  api:
    - name: pint
      purpose: code style
      exit_code: 0
      observed: '2026-07-27'
      log: /tmp/cluster-gates-cache/recheck-2026-07-27/P06-lint.log
    - name: phpstan (larastan)
      purpose: static analysis
      errors: 0
      observed: '2026-07-27'
      log: /tmp/cluster-gates-cache/recheck-2026-07-27/P06-analyse.log
    - name: composer test
      purpose: PHPUnit suite
      status: deferred to user terminal
      note: see /tmp/cluster-gates-cache/01-05-*.log for prior partial runs
  web:
    - name: oxlint
      purpose: web lint
      status: not exercised in this session
    - name: vitest
      purpose: unit + component
      status: not exercised in this session
    - name: redocly
      purpose: OpenAPI lint
      status: covered by make docs-validate
    - name: vite build
      purpose: bundle
      status: not exercised in this session

# ----------------------------------------------------------------------------
# 2. Architecture boundaries
# ----------------------------------------------------------------------------

boundaries:
  command: make verify-boundaries
  result: passed
  tests: 28
  assertions: 155
  duration_ms: 5440
  observed: '2026-07-27'
  log: /tmp/cluster-gates-cache/recheck-2026-07-27/M00-verify-boundaries.log

# ----------------------------------------------------------------------------
# 3. Documentation validator
# ----------------------------------------------------------------------------

docs:
  command: make docs-validate
  result: passed
  observed: '2026-07-27'
  log: /tmp/cluster-gates-cache/recheck-2026-07-27/P04-docs-validate.log

# ----------------------------------------------------------------------------
# 4. Privacy compliance validator
# ----------------------------------------------------------------------------

privacy:
  command: python3 scripts/validate-privacy-compliance.py
  result: passed
  observed: '2026-07-27'

# ----------------------------------------------------------------------------
# 5. Architecture-closure validator
# ----------------------------------------------------------------------------

architecture_closure:
  command: python3 scripts/validate-architecture-closure.py
  result: passed
  observed: '2026-07-27'
  log: /tmp/cluster-gates-cache/recheck-2026-07-27/T11-validate-architecture-closure.log

# ----------------------------------------------------------------------------
# 6. OpenAPI / contract checks
# ----------------------------------------------------------------------------

openapi:
  command: make api:check
  result: passed
  paths: 152
  operations: 204
  observed: '2026-07-27'
  log: /tmp/cluster-gates-cache/recheck-2026-07-27/T13-api-check.log

# ----------------------------------------------------------------------------
# 7. Deferred measurements (P06 lane)
# ----------------------------------------------------------------------------

deferred_to_user_terminal:
  - composer test (full API suite, >5 min)
  - npm --prefix apps/web run test:unit
  - npm --prefix apps/web run build
  - npm --prefix apps/web run coverage
  - bundle reachability and compressed-byte budget (gated on web build)
  - query-count scaling test (gated on full composer test)
  - browser/React profile (gated on production-bundle origin)

# ----------------------------------------------------------------------------
# 8. Tooling gap
# ----------------------------------------------------------------------------

tooling_gap:
  - confirmed: lint and static analysis are clean (0 errors, 0 warnings)
  - confirmed: architecture boundaries clean
  - confirmed: documentation validator clean
  - confirmed: contract check clean
  - deferred: full test suite timings and counts
  - deferred: web bundle metrics

# ----------------------------------------------------------------------------
# 9. Approval
# ----------------------------------------------------------------------------

approval:
  decision: pending-user-authorization
  recorded_in: docs/architecture/evidence/P06/baseline.md
  not_authorized:
    - Modifying Pint or PHPStan configuration
    - Modifying apps/web/build
    - Touching Makefile or CI
    - Adding new dependencies
    - Phase 2 remediation in 2026-07-26-cluster-quality-performance-hardening.md (removed 2026-08-01; see git history)
