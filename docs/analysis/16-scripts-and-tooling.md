# 16 · السكربتات وخطوط التحقق و الـ Infra

> **خط أساس تاريخي — 2026-07-25.** الوصف التفصيلي مفيد، لكن المقاييس
> والمخاطر الحالية تؤخذ من [`SUMMARY.md`](SUMMARY.md) و[`17-cross-cutting-risks.md`](17-cross-cutting-risks.md).

> **النطاق:** `scripts/`, `infra/`, `.github/workflows/`, `Makefile`, `actionlint.yaml`, `.gitleaks.toml`.

## 1 · Makefile targets

```
verify-intake          : فحص lockfiles/manifests
test-api-smoke         : API smoke
test-web-smoke         : web smoke
test-api               : composer test
test-web               : web build + lint + coverage
test-web-unit          : Vitest only
coverage-web           : vitest --coverage
lint-api               : Laravel Pint
analyse-api            : PHPStan/Larastan
scan-secrets           : gitleaks
audit-dependencies     : composer audit --locked
verify-boundaries      : tests/Architecture/ModuleBoundariesTest
verify-mysql-integration : MySQL concurrency suite
verify-screens         : verify-day3
validate-production-bundle
build-production-images
verify-production-images
deploy-vps             : validate-production-bundle
```

## 2 · السكربتات تحت `scripts/`

| السكربت | الحجم | الدور |
|---------|------|------|
| `validate-docs.sh` | 16.7KB | shell + Python متعدد المراحل، يتحقق من: bash syntax، python3 متاح، OpenAPI validators، `module-catalog.md` |
| `validate-notifications-openapi.py` | 13KB | Notifications API validation |
| `validate-auth-openapi.py` | 3.7KB | Auth API validation |
| `validate-work-records-openapi.py` | 8KB | WorkRecords API validation |
| `validate-w1-1-openapi.py` | 1.6KB | W1.1 contract |
| `validate-w1-2-contracts.py` | 38KB | W1.2 contracts (largest) |
| `openapi_reconciler.py` | 51KB | OpenAPI reconciler |
| `production_bundle_policy.py` | 11KB | Production bundle policy checks |
| `bundle_runner.py` | 10KB | Bundle runner |
| `markdown_renderer.py` | 14KB | Render MD for docs |
| `arabic_translator.py` | 13KB | AR translation support |
| `inventory-routes.py` | 17KB | Routes inventory |
| `rbac.py` + `rbac_helpers.php` | 13.8KB + 0.3KB | RBAC analysis |
| `check-day3-migrations.php` | 1.6KB | Day3 migration checker |
| `render-diagrams.sh` | 1KB | Mermaid rendering |
| `verify-inventory.sh` | 10KB | Inventory verification |

## 3 · Infra

### 3.1 dev/
- `compose.yaml` — dev stack (api، mysql، redis، minio، clamav).
- `compose.w1-2-e2e.yaml` — E2E stack for W1.2.
- `run-approvals-e2e.sh`، `run-day2-e2e.sh`، `run-day3-e2e.sh`، `run-platform-settings-e2e.sh`، `run-w1-1-e2e.sh`، `run-w1-2-e2e.sh`، `run-w1-3-e2e.sh`، `run-w1-1-api-worker-smoke.sh` — E2E runners.
- `.env.example` و `.env` — متغيرات بيئة.

### 3.2 platform/production/
- `compose.yaml` — production stack.
- `compose.test.yaml` — production-like stack للاختبار.
- `Caddyfile` — reverse proxy.
- `build-images.sh`، `verify-images.sh`، `deploy-vps.sh`، `run-local-e2e.sh`.
- `.env.example` — production env template.

## 4 · CI (.github/workflows/ci.yml)

5 jobs:
1. **api** — composer validate, install, lint, analyse, audit, test-api, verify-boundaries.
2. **web** — npm ci, make test-web.
3. **secrets** — gitleaks scan.
4. **production-bundle** — install Playwright + run `make verify-w1-1-local`.
5. (لا job E2E مدمج في CI — يُشغَّل محلياً فقط عبر `playwright.config.ts`).

## 5 · actionlint.yaml
يحدد إعدادات actionlint (4 أسطر) — للتحقق من GH Actions.

## 6 · .gitleaks.toml
قواعد gitleaks لتفادي تسريب أسرار في الكود.

## 7 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| I1 | `validate-docs.sh` يبحث عن `docs/architecture/module-catalog.md` (مفقود) — سيفشل | `scripts/validate-docs.sh` |
| I2 | `validate-docs.sh` يستدعي `validate-w1-2-contracts.py` (38KB) — قد يكون بطيئاً | `scripts/validate-w1-2-contracts.py` |
| I3 | `openapi_reconciler.py` (51KB) ضخم، صعب الصيانة | `scripts/openapi_reconciler.py` |
| I4 | `inventory-routes.py` (17KB) — قد يكون out of sync مع `routes/web.php` | `scripts/inventory-routes.py` |
| I5 | `rbac.py` + `rbac_helpers.php` — متعدّد اللغات، صعب الـ extension | `scripts/rbac.py` |
| I6 | `run-w1-3-e2e.sh`، `run-platform-settings-e2e.sh`، `run-day3-e2e.sh` — متعدّدة، بدون Make target موحَّد | `infra/dev/run-*.sh` |
| I7 | CI لا يشغّل E2E (`playwright.config.ts` يحدّ localhost only) — risk gap | `.github/workflows/ci.yml` |
| I8 | `verify-w1-1-local` ليس له Make target موثوق في `Makefile` | `Makefile` |
| I9 | `gitleaks` ينفّذ على كل push (overhead) | `ci.yml:50-53` |
| I10 | لا job `dependency-audit` للـ web (composer --locked فقط) | `ci.yml:29` |
| I11 | `Makefile` يفتقر target `make e2e-w1-1` رغم وجود `make test-e2e-w1-1` | `Makefile` |

## 8 · التحسينات المقترحة

1. **إنشاء `docs/architecture/module-catalog.md`** + إضافة target `make docs:validate` يستدعي `validate-docs.sh`.
2. **تفكيك `openapi_reconciler.py`** إلى modules أصغر.
3. **استبدال `inventory-routes.py`** بـ `php artisan route:list --json` + jq.
4. **توحيد `run-*.sh` في infra** عبر Makefile (مثل `make e2e-w1-2`).
5. **إضافة job E2E في CI** (self-hosted runner مع Redis + MySQL).
6. **إضافة npm audit** في CI (lockfile).
7. **cache** لـ `composer install` و `npm ci` (موجود لـ npm، composer مفقود).
8. **target موحَّد لـ `verify-w1-1-local`** في `Makefile`.
9. **تأكيد أن `bundle_runner.py` يتعامل مع `w1-2` contracts** (38KB script — يجب أن يكون idempotent).
10. **اختبار `make verify-w1-1` محلياً** قبل الـ deploy.

## 9 · ملاحظات ختامية

- **CI يركّز على API** أكثر من web (الـ web test محدود بـ `make test-web`).
- **E2E محلي فقط** (localhost-only enforcement in `playwright.config.ts:30-37`).
- **Production-grade guards** في `AppServiceProvider` (assertAuthorizationRuntimeSafe, assertDocumentsStorageRuntimeSafe) لكنها تتطلب `production` environment صحيح.
- **gitleaks** يعمل، لكن لا يوجد job dependency audit للـ web.
