---
name: cluster-verification
description: Select the narrowest sufficient repository checks by changed surface and risk. Use after implementation or when running /verify.
---

# Cluster Verification

Choose checks from changed behavior and risk rather than running every suite.

| Surface | Minimum verification |
|---|---|
| Documentation only | `./scripts/validate-docs.sh` |
| Laravel behavior | Narrowest affected `php artisan test` |
| Module relationship, SQL, contract, or migration | Focused test plus `make verify-boundaries` |
| PHP style | `composer --working-dir=apps/api lint` when relevant |
| PHP static behavior | `composer --working-dir=apps/api analyse` when risk justifies it |
| React behavior | Focused Vitest test plus `npm --prefix apps/web run build` |
| OpenAPI or Orval | `api:lint`, generation, `api:check`, and web build |
| Package closure | Matching `make verify-*` or `make verify-day*` target |
| Browser journey | Relevant E2E script after focused checks pass |
| Production bundle | Production policy, image verification, and local E2E only for production scope |

Do not use broad suites for text-only or non-behavioral changes. Do not rely on mock-only tests for organizational isolation, transaction atomicity, authorization, MySQL-specific behavior, or file security.

Report exact commands, pass or fail result, and a reason for every material skipped check.
