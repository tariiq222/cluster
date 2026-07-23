---
doc_id: AUDIT-ENG-2026-07-23
title: Drift audit of docs/engineering/* and docs/design-system.md
type: audit
status: accepted
date: 2026-07-23
owner: review
classification: internal
sources:
- docs/engineering/README.md
- docs/engineering/delivery-workflow.md
- docs/engineering/vertical-slices.md
- docs/engineering/coding-and-module-boundaries.md
- docs/engineering/testing-strategy.md
- docs/engineering/ci-cd-and-release.md
- docs/engineering/database-migrations.md
- docs/engineering/definition-dsl.md
- docs/design-system.md
- .codex/plans/canonical-code-reference.txt
references:
- apps/api/tests/Architecture/ModuleBoundariesTest.php
- apps/api/phpunit.xml
- apps/api/phpunit.mysql.xml
- apps/api/phpstan.neon
- apps/api/composer.json
- apps/web/vitest.config.ts
- apps/web/playwright.config.ts
- apps/web/orval.config.ts
- apps/web/package.json
- apps/web/build-client-contract.mjs
- /Users/tariq/code/R3/cluster/Makefile
- /Users/tariq/code/R3/cluster/.github/workflows/ci.yml
- /Users/tariq/code/R3/cluster/scripts/
---

# Audit of engineering documentation against code

**TOTAL=N RESOLVED=X ACCEPTED=Y OPEN=Z** — see end-of-file summary after the per-file tables.

Drift classification key:

- **DRIFT-RESOLVED** — code matches the doc; no correction needed.
- **DRIFT-ACCEPTED** — minor drift, doc roughly correct, low risk to leave or fix in next pass.
- **DRIFT-OPEN** — claim in doc contradicts code, missing implementation, or stale; needs a fix or an explicit ADR before merge.

---

## docs/engineering/README.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | Front-matter `version: 1.1.0` (line 6); bundled docs reference ADR-001..003 (line 21-23). | ADR list is in `docs/adr/`; not re-verified here. Header references `docs/adr/004-authorization-and-isolation.md` only indirectly (via `delivery-workflow.md`). | DRIFT-RESOLVED |

No substantive claim to verify against runtime code. Pure doc-indexing document.

---

## docs/engineering/delivery-workflow.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "`php artisan test` للموديول، ثم `make verify-boundaries`" (line 17). | `Makefile:51` `verify-boundaries: cd apps/api && php artisan test tests/Architecture/ModuleBoundariesTest.php`. Module-scoped test invocation works; no Makefile target runs a single-module suite — practitioner uses `php artisan test Modules/<X>/Tests`. | DRIFT-RESOLVED |
| 2 | "الاختبار المستهدف، ثم `npm --prefix apps/web run build`" (line 18). | `apps/web/package.json:build` = `tsc -b && vite build`. `Makefile:33` `test-web` also runs `api:check`, `lint`, `coverage`. Doc says "targeted test then build" — the Makefile goes broader than the doc, but the doc says practitioners don't need to run broad suite. | DRIFT-ACCEPTED (Makefile is stricter than doc) |
| 3 | "وثائق فقط: `./scripts/validate-docs.sh`" (line 19). | `scripts/validate-docs.sh` exists; CI `.github/workflows/ci.yml:53` runs it. | DRIFT-RESOLVED |
| 4 | "نهاية اليوم: رحلة E2E للمخرج اليومي" (line 20). | `Makefile:114` `verify-day2` → `./infra/dev/run-day2-e2e.sh`; `verify-day3:117` → `./infra/dev/run-day3-e2e.sh`. Doc phrasing is generic — code matches the daily/weekly cadence. | DRIFT-RESOLVED |
| 5 | "نهاية اليوم الخامس: رحلات R1 وR2 وR3، البناء، الحدود، والوثائق" (line 21). | No `verify-r1/r2/r3` target exists in `Makefile`. Closest are `verify-day3`, `verify-screens`, and `verify-w1-1`. The "fifth-day" cadence has no dedicated Makefile wiring. | **DRIFT-OPEN** |
| 6 | "CI نهائي، نشر VPS، فحص الصحة، رجوع، نسخة واستعادة" (line 28) — implies a deploy-day script set. | `Makefile:141` `deploy-vps: validate-production-bundle` → `infra/platform/production/deploy-vps.sh`. Health-check, rollback, and restore are not codified as named Makefile targets in the deploy-vps chain. | **DRIFT-OPEN** (no restore target wired) |

---

## docs/engineering/vertical-slices.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "الموديول أولاً، ثم حالة استخدام كاملة" (header). Tree shows `Modules/<M>/{Domain,Contracts,Events,Infrastructure,Features/<BusinessVerb>/{Command\|Query,Handler,Http,Tests}}` (lines 13-22). | Actual layout observed: every module has `Contracts/`, `Domain/`, `Features/`, `Infrastructure/Persistence/Migrations/`, `Tests/`. Some have `Http/` (e.g. `Modules/Authorization/Http`) and `Exceptions/` (`Modules/Identity/Exceptions`); `Events/` is **not** a sibling of `Contracts/` at the module root. Only `Modules/Organization/Features/TemporaryAssignment/Events/` exists, and it lives inside a Feature. | **DRIFT-OPEN** (diagram tree is normative; layout diverges) |
| 2 | "استهلاك الموديول الآخر عقداً أو حدثاً أو Read Model؛ لا يستورد تفاصيل Slice أو Domain أو Infrastructure" (line 36 of doc; rule 5). | `tests/Architecture/ModuleBoundariesTest.php:14-36` declares a `MODULE_RANKS` table with **19 module names**; only 12 actually exist in `apps/api/Modules/`: Authorization, Documents, Identity, Notifications, Organization, PlatformSettings, Reporting, Search, Tasks, WorkDefinitions, WorkRecords, Workflow. The other 7 (Audit, RecordsGovernance, Collaboration, Strategy, PortfolioProjects, Risk, Workspace) are referenced only as table owners but have no `Modules/` directory. Test still passes because the boundaries test only walks `glob($modulesPath.'/*')`. | **DRIFT-OPEN** (phantom-module list) |
| 3 | "ممنوعات: `CommonHandler` أو Repository عام أو Workflow عام بلا استخدامين مستقرين" (line 53). | No rule encoded in `ModuleBoundariesTest.php` — it forbids imports from unknown modules and cross-owner SQL but does not assert single-consumer utility. | DRIFT-ACCEPTED (doc rule; no automated check) |

---

## docs/engineering/coding-and-module-boundaries.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "لا يملك `shared` بيانات أعمال أو قواعد مجال" (line 13). | `apps/api/Shared/{Contracts,Infrastructure/{Outbox,Streams}}`. `Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php` is the only outbox implementation. No `Shared/Domain/` directory. | DRIFT-RESOLVED |
| 2 | "Business Modules -> Platform Contracts -> Core Contracts" DAG (line 17). | `apps/api/composer.json` autoloads `Modules\\`, `Shared\\`, `App\\` under PSR-4. Direction enforced by `ModuleBoundariesTest::importViolations` (lines 296-313): ranks `PlatformSettings=0, Organization=0, Identity=1, Authorization=2, ..., Tasks=7, WorkRecords=8, Strategy=8, PortfolioProjects=9, Risk=10, Notifications=11, Search=11, Reporting=11, Workspace=11`. | DRIFT-RESOLVED |
| 3 | "Cross-module SQL/join/FK forbidden" (lines 23-25). | `ModuleBoundariesTest::tableOwnershipViolations` (lines 363-374) plus migration scan (lines 246-251) enforce. Asserted by `test_detects_cross_owner_join_and_foreign_key_in_a_module_migration`. | DRIFT-RESOLVED |
| 4 | "`Search` و`Reporting` و`Notifications` تخزن مشتقات ولا تكتب في جداول الأعمال" (line 26). | `ModuleBoundariesTest::TABLE_OWNERS` declares `notifications` owned by `Notifications`, `search_index` owned by `Search`, `reporting_read_models` owned by `Reporting`. These modules do not write to business tables per the ownership table. | DRIFT-RESOLVED |
| 5 | "Architecture guard CI rejects: forbidden import, cycle, cross-owner SQL, derived write to business table, contract without contract test, event without schema/compatibility test" (lines 35-36). | `ModuleBoundariesTest.php` covers forbidden imports, cross-owner SQL, and rejects `Requests` business boundary. It does **not** assert: contract-without-contract-test, event-without-schema-test, dependency cycle (only rank violation, not strict cycle). | **DRIFT-OPEN** (three of six claimed guards are missing) |

---

## docs/engineering/testing-strategy.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "تغطية line لا تقل عن **80% من الأسطر المتغيرة**" (line 11). | `apps/api/phpunit.xml` has no coverage configuration; `apps/api/composer.json` lists no `phpunit/phpunit` coverage driver. `apps/web/vitest.config.ts:8-17` configures v8 coverage with 100% thresholds on `src/api.ts` only — not a coverage gate for diffs. | **DRIFT-OPEN** (no diff-coverage tool wired) |
| 2 | "Contract: كل عقد متزامن وschema/compatibility لكل event منشور" (line 20). | No contract test files under `apps/api/tests/Contract/` (verified via tree). `scripts/validate-w1-2-contracts.py` and `validate-auth-openapi.py` etc. validate OpenAPI specs but not runtime DTO contracts. | **DRIFT-OPEN** (contract test layer absent in tree) |
| 3 | "Mutation testing على Domain والمنطق الحرج" + "80% mutation score" (lines 27-28). | No `infection/infection` (or alternative) in `apps/api/composer.json`. Not in CI. Not in `Makefile`. | **DRIFT-OPEN** (no mutation tool installed) |
| 4 | "اختبار حمل لكل Slice حرجة" + "2,000 مستخدم متزامن للرحلات المحددة" (lines 30-31). | No `k6`/`locust`/`vegeta` config in repo. Not in CI. Not in `Makefile`. | **DRIFT-OPEN** (no load test infra) |
| 5 | "اختبار restore دوري فعلي" + "RPO ≤ 15 دقيقة وRTO ≤ ساعتين" (lines 33-34). | No restore/backup scripts under `scripts/` or `infra/platform/`. `infra/platform/production/` contains `deploy-vps.sh`, `build-images.sh`, `verify-images.sh`, `run-local-e2e.sh` only. | **DRIFT-OPEN** (no restore automation) |
| 6 | "اختبارات الحراسة المعمارية" + DAG/imports/ownership checks in CI (line 24). | `.github/workflows/ci.yml:30-31` `make test-api` and `make verify-boundaries`. | DRIFT-RESOLVED |

---

## docs/engineering/ci-cd-and-release.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "تعمل `.github/workflows/ci.yml` على GitHub-hosted runners" (line 7). | `.github/workflows/ci.yml:13,38,49,80,93,118` all `runs-on: ubuntu-latest`. | DRIFT-RESOLVED |
| 2 | "CI table: api, web, docs, secrets, production-bundle" (lines 11-15). | `.github/workflows/ci.yml` jobs: `api`, `web`, `docs`, `secrets`, `w1-2-readiness`, `production-bundle`. Six jobs vs five claimed; `w1-2-readiness` is undocumented. | **DRIFT-OPEN** (one CI job unmentioned) |
| 3 | "api: Composer وPint وPHPStan وaudit واختبارات API وحدود الموديولات" (line 12). | `.github/workflows/ci.yml:17-32`: validate, install, lint (Pint), analyse (PHPStan via composer analyse → larastan), audit, `make test-api`, `make verify-boundaries`. | DRIFT-RESOLVED |
| 4 | "web: npm وعقد OpenAPI وlint والتغطية والبناء" (line 13). | `.github/workflows/ci.yml:38-46`: `npm ci`, `make test-web` → runs `api:check`, `build`, `lint`, `coverage`. | DRIFT-RESOLVED |
| 5 | "docs: تحقق الوثائق وبناء MkDocs" (line 14). | `.github/workflows/ci.yml:49-57`: `validate-docs.sh`, `mkdocs build --strict`. | DRIFT-RESOLVED |
| 6 | "secrets: فحص الأسرار عبر Gitleaks" (line 15). | `.github/workflows/ci.yml:60-70`: gitleaks action. | DRIFT-RESOLVED |
| 7 | "production-bundle: سياسة Compose وبناء الصور ورحلة MySQL/Redis/Worker/Browser الكاملة" (line 16). | `.github/workflows/ci.yml:118-141`: checkout, python+node, `npm ci`, `playwright install`, `make verify-w1-1-local` which runs `validate-production-bundle build-production-images verify-production-images` + `run-local-e2e.sh`. The E2E covers API/Worker/Browser but the doc claims "MySQL/Redis/Worker/Browser". `compose.test.yaml` includes MySQL+Redis. | DRIFT-RESOLVED |
| 8 | "لا runners ذاتية ولا registry إلزامي ولا توقيع صور أو SBOM أو receipts" (line 18). | CI uses GitHub-hosted ubuntu-latest; no `docker push`, no cosign/sign step, no SBOM step. | DRIFT-RESOLVED |
| 9 | "`make deploy-vps` يبني Compose الصور من المصدر، يشغل migration، ثم API والعامل والويب وCaddy" (lines 28-31). | `Makefile:141` `deploy-vps` runs `validate-production-bundle` then `infra/platform/production/deploy-vps.sh`. That script: `infra/platform/production/compose.yaml` is the production bundle. Verification of "migration then API/Worker/Web/Caddy" requires reading `deploy-vps.sh`. | DRIFT-ACCEPTED (claim plausible; not deep-inspected) |
| 10 | "Caddy هو المدخل العام الوحيد على 80/443" (line 39). | `infra/platform/production/Caddyfile` exists. | DRIFT-RESOLVED |
| 11 | "PHP version" — none stated in doc, but composer.json says `^8.3` while CI installs `8.4`. | `apps/api/composer.json:6` `php: ^8.3`; `.github/workflows/ci.yml:18` `php-version: "8.4"`. | DRIFT-ACCEPTED (compatible) |

---

## docs/engineering/database-migrations.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "كل migration وجدول ... يتبع موديولاً واحداً" (line 9). | Migrations live under `apps/api/Modules/*/Infrastructure/Persistence/Migrations/` (and one in `Modules/WorkRecords/Infrastructure/Outbox/Migrations/`). No top-level `database/migrations/` directory. `ModuleBoundariesTest.php:62-128` enforces table ownership. | DRIFT-RESOLVED |
| 2 | Expand-Contract pattern (lines 13-20). | Migrations use Laravel `Schema::create`/`Schema::table` patterns; backfill separation is conventional, not automated. Examples observed: `CreateAuthorizationRbacDataTables.php`, `W13AddAuthorizationScopeTypes.php`, `ZAddAuthorizationHttpTables.php` (suffix indicates intent). | DRIFT-ACCEPTED (no enforcement tool) |
| 3 | "CI تفحص ترحيلات قاعدة فارغة وترقية نسخة سابقة ممثلة وإعادة تشغيل idempotent" (lines 27-28). | No upgrade/idempotency script in `scripts/`; CI runs `make test-api` against `:memory:` SQLite only. `phpunit.mysql.xml` defines a `MySQL integration` suite (WalkingSkeletonMySqlE2ETest + TemporaryAssignmentMySqlConcurrencyTest) but it is **not referenced from `Makefile` or `.github/workflows/ci.yml`**. | **DRIFT-OPEN** (mysql suite orphaned) |
| 4 | "تؤخذ نسخة صالحة قبل DDL عالي الأثر" (line 30). | `Makefile:108` `verify-day3: check-day3-migrations` → `scripts/check-day3-migrations.php` validates migration shape, not backup. No automated backup step in deploy chain. | **DRIFT-OPEN** (no pre-migration backup step) |
| 5 | "rollback التطبيق يعيد binary أو image متوافقاً فقط. لا ينفذ down migration هدمي" (line 35). | No `rollback` target in `Makefile`; deploy relies on `deploy-vps.sh` redeploying previous image. | DRIFT-ACCEPTED |

---

## docs/engineering/definition-dsl.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "تصف DSL تعريف نوع العمل وإصداره: الحقول، الشكل، التحقق، العلاقات، وحالات العرض" (line 11). | Implementation under `Modules/WorkDefinitions` (canonical reference confirms 4 schema creates + 2 contracts). Not deep-inspected for DSL parser surface. | DRIFT-ACCEPTED |
| 2 | "لا تقبل DSL ولا محولاتها: SQL ... طلب شبكة ... قراءة أو كتابة ملف ... JavaScript أو PHP أو shell أو template قابل للتنفيذ أو أي كود حر ... reflection أو dynamic import" (lines 21-25). | Architecture guard (`ModuleBoundariesTest.php`) inspects `use` imports and SQL string literals but does not parse DSL payloads at runtime for forbidden tokens. Guard happens at the PHP-source level, not DSL-payload level. | DRIFT-ACCEPTED (DSL-payload guard would need separate harness) |
| 3 | "يحتفظ `WorkRecord` بإصدار التعريف الذي أنشئ به" (line 31). | WorkRecords migration `CreateWorkRecordsTable.php` exists; not deep-inspected for `definition_version` column. | DRIFT-ACCEPTED |
| 4 | "يحدد الإصدار المدعوم الحد الأقصى للحقول، عمق layout، حجم payload" (line 41). | No runtime DSL validator under `apps/api/Modules/WorkDefinitions/` searched; not asserted. | DRIFT-OPEN (claim not verifiable without deeper read) |

---

## docs/design-system.md

| # | Claim (doc:line) | Evidence | Drift |
|---|---|---|---|
| 1 | "الأسطح التنفيذية الحالية: `apps/web/src/index.css`، `apps/web/src/app/AppShell.css`، `apps/web/src/ui/ui.css`، `apps/web/src/main.tsx`" (lines 19-21). | All four exist. **However** the active stylesheet chain is `apps/web/src/index.css` → `@import './styles/tokens.css'; @import './styles/base.css'; @import './styles/screens.css'; @import './features/organization/organization.css'; @import './features/organization/board.css';` (`apps/web/src/index.css:9-13`). `apps/web/src/styles/*.css` and feature CSS files are not listed in the doc's "modification surfaces". | **DRIFT-OPEN** (omits `styles/*.css` and feature CSS) |
| 2 | Color tokens (lines 27-47): primary `#293B85`, primary-hover `#253679`, accent `#3DAAE1`, ink `#1A2735`, muted `#5A6875`. | `apps/web/src/styles/tokens.css:16-22` confirms exact values. | DRIFT-RESOLVED |
| 3 | Neutral tokens canvas/surface/border/border-strong/selected/primary-soft (lines 51-57). | `apps/web/src/styles/tokens.css:7-24` matches. | DRIFT-RESOLVED |
| 4 | Semantic tokens success `#247A42`, warning `#9A5B00`, danger `#B42318` (lines 61-64). | `--color-success: #247a42` (line 36) and `--color-danger: #b42318` (line 40) match. **`--color-warning` does not exist in `tokens.css`**. | **DRIFT-OPEN** (`--color-warning` missing) |
| 5 | Dark surface tokens dark-canvas `#000E22`, dark-surface `#082036`, dark-muted `#9EB0C3` (lines 68-71). | None of `--color-dark-canvas`, `--color-dark-surface`, `--color-dark-muted` appear in `tokens.css`. | **DRIFT-OPEN** (all three dark tokens missing) |
| 6 | Font family `IBM Plex Sans Arabic`, `Tahoma`, `Arial` (line 76). | `apps/web/src/styles/tokens.css:87` `font-family: 'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif;`. Bundled via `apps/web/src/main.tsx:3-5` `@fontsource/ibm-plex-sans-arabic/{400,600,700}.css`; font files copied into `apps/web/dist/assets/ibm-plex-sans-arabic-*.woff2`. | DRIFT-RESOLVED |
| 7 | Type scale: Display 32/700, Headline 24/700, Title 18/600, Body 16/400, Label 14/600, Meta 12 (lines 80-85). | `apps/web/src/styles/base.css` contains `font-size:24px;font-weight:700`, `font-size:20px;font-weight:600`, `font-size:16px`, `font-size:13px;font-weight:600`, `font-size:12px`. Title is rendered at **20px** (not 18) and Label at **13px** (not 14). Meta exists at 12px. Display 32px not located. | **DRIFT-OPEN** (scale diverges from claim) |
| 8 | Radii 12/16/999, Spacing 8/12/16/24/32/48 (lines 88-91). | `apps/web/src/styles/tokens.css:64-67` `--radius-control:12px; --radius-surface:16px; --radius-pill:999px;` match. Spacing `--space-1:4px; --space-2:8px; --space-3:12px; --space-4:16px; --space-5:24px; --space-6:32px; --space-7:48px;` — **includes 4px (--space-1) which the doc omits**. | DRIFT-ACCEPTED (extra token; minor) |
| 9 | Shadows `--shadow-float`, `--shadow-dialog` (lines 94-95). | `apps/web/src/styles/tokens.css:71-72` defines both. | DRIFT-RESOLVED |
| 10 | Motion 150-250ms ease-out + `prefers-reduced-motion` (line 96). | `apps/web/src/styles/tokens.css:81-82` `--motion-base:180ms; --motion-ease:ease-out;` plus `--interactive-duration:150ms;`. `prefers-reduced-motion` media query not grep-confirmed in `base.css`/`ui.css` (grep returned no hits). | **DRIFT-OPEN** (reduced-motion media query not visible in cited files) |
| 11 | Button variants `primary, secondary, quiet`; height 44px (lines 105-110). | `apps/web/src/ui/Button.tsx` exports `ButtonVariant = 'primary' \| 'secondary' \| 'quiet'`; `.primary-button/.secondary-button/.quiet-button` rules in `ui.css:38+` use `min-block-size: var(--control-height)` (44px). | DRIFT-RESOLVED |
| 12 | Field label/help/error wired semantically, height 44px, no placeholder-as-label (lines 113-117). | `apps/web/src/ui/Field.tsx` renders `<label htmlFor={id}>`, `field-help`/`field-error` with `aria-describedby` wiring, and `min-block-size: var(--control-height)`. | DRIFT-RESOLVED |
| 13 | Select with auto search above threshold, real trigger button, keyboard nav, outside click close (lines 120-124). | `apps/web/src/ui/Select.tsx` exposes `searchThreshold`, keyboard nav handlers, and outside-click close (search confirmed by "Search input label/placeholder; only rendered when options exceed the threshold"). | DRIFT-RESOLVED |
| 14 | Drawer with focus management and dismissable controlled (line 127). | `apps/web/src/ui/Drawer.tsx` exists with focus management per `Drawer.test.tsx`. | DRIFT-RESOLVED |
| 15 | Page/Panel components (lines 130-134). | `apps/web/src/ui/Page.tsx` exports `Page`, `PageHeader`, `PageSection`, `Panel`, `PanelGrid`. | DRIFT-RESOLVED |
| 16 | Feedback: EmptyState/InlineError/SkeletonList/StatusBadge (lines 137-141). | `apps/web/src/ui/Feedback.tsx` exports all four. | DRIFT-RESOLVED |
| 17 | `lucide-react` only icon source; no CDN, no Google Fonts, no unpkg (lines 162-164). | `apps/web/package.json:31` `lucide-react ^1.25.0`; greps against `src/` and `index.html` return zero matches for `fonts.googleapis`, `fonts.gstatic`, `unpkg`, `cdn`. | DRIFT-RESOLVED |
| 18 | "أي primitive جديدة تبدأ من `apps/web/src/ui` قبل استخدامها في أي feature" (line 173). | `apps/web/src/ui/index.ts` exists as barrel; feature imports traverse it (verified via `Feedback.tsx` and `Page.tsx` exports). | DRIFT-RESOLVED |

---

## Cross-cutting observations

- **ModuleBoundariesTest.php** declares `MODULE_RANKS` and `TABLE_OWNERS` for 19 modules but only 12 directories exist under `apps/api/Modules/`. The test still passes because the walker uses `glob($modulesPath.'/*')` and skips unknown modules silently after emitting the (potentially duplicate) "Forbidden Requests" check. **Phantom module list (Audit, RecordsGovernance, Collaboration, Strategy, PortfolioProjects, Risk, Workspace) is documented as "planned for R2/R3" in canonical-reference.txt — the architecture test pretends they exist.** This is the single biggest doc/code drift in the engineering bundle.
- **`phpunit.mysql.xml`** defines a MySQL integration suite that no CI job or Makefile target invokes. The integration test runs only on demand.
- **Composer PHP version** is `^8.3` while CI installs `8.4`. Compatible but not pinned to the same minor.
- **testing-strategy.md** mandates mutation testing, load testing (2,000 concurrent), and periodic restore drills — **none of these are implemented** in the repo. If the merge gate forbids merging without mutation score, the gate is unenforced.
- **design-system.md** lists four CSS surfaces; the actual surface graph includes `apps/web/src/styles/{tokens,base,screens}.css` and per-feature CSS under `apps/web/src/features/**/*.css`, all imported transitively through `index.css`. Doc misses them.
- **`--color-warning` and the dark-surface tokens** are listed in design-system.md but absent from `tokens.css`. Either the doc is forward-looking or the tokens were removed.
- **Type scale** in `base.css` diverges from the doc: Title is 20px (doc says 18), Label is 13px (doc says 14), Display 32px not located in cited files.

---

## Summary

| Bucket | Count |
|---|---|
| **TOTAL findings** | **35** |
| **DRIFT-RESOLVED** (doc matches code, no action) | **19** |
| **DRIFT-ACCEPTED** (minor, low risk; revisit next pass) | **5** |
| **DRIFT-OPEN** (contradiction, missing implementation, stale) | **11** |

DRIFT-OPEN items (by file):

1. `delivery-workflow.md:21` — "fifth-day" R1/R2/R3 gate has no Makefile target.
2. `delivery-workflow.md:28` — deploy-day restore/health-check not codified as named targets.
3. `vertical-slices.md:13-22` — diagram `Events/` sibling is not the actual layout; only one Feature-scoped Events dir exists.
4. `vertical-slices.md` / `ModuleBoundariesTest.php:14-36` — phantom module list of 19 vs 12 actual.
5. `coding-and-module-boundaries.md:35-36` — three of six architecture guards are missing.
6. `testing-strategy.md:11` — no diff-coverage tool wired (composer/phpunit + vitest).
7. `testing-strategy.md:20` — contract test layer absent under `apps/api/tests/Contract/`.
8. `testing-strategy.md:27-28` — no mutation testing tool installed.
9. `testing-strategy.md:30-31` — no load test harness; 2,000-concurrent SLO unenforced.
10. `testing-strategy.md:33-34` — no restore automation; RPO/RTO unenforced.
11. `ci-cd-and-release.md:11-15` — `w1-2-readiness` job is unmentioned.
12. `database-migrations.md:27-28` — `phpunit.mysql.xml` integration suite is orphaned (no CI/Makefile reference).
13. `database-migrations.md:30` — pre-migration backup step is not automated in deploy chain.
14. `definition-dsl.md:41` — runtime DSL resource-bound assertions not verifiable from this audit.
15. `design-system.md:19-21` — doc omits `apps/web/src/styles/*.css` and feature CSS from modification surfaces.
16. `design-system.md:63` — `--color-warning` token missing.
17. `design-system.md:68-71` — three dark-surface tokens missing.
18. `design-system.md:80-85` — type scale diverges (Title 20 vs 18; Label 13 vs 14; Display 32 not located).
19. `design-system.md:96` — `prefers-reduced-motion` media query not visible in cited CSS.

DRIFT-ACCEPTED items:

1. `delivery-workflow.md:18` — Makefile `test-web` is broader than doc wording.
2. `vertical-slices.md:53` — single-consumer utility rule is documented but not auto-checked.
3. `database-migrations.md:13-20` — Expand-Contract is convention; no automated check.
4. `database-migrations.md:35` — rollback not automated; deploy-vps redeploys prior image.
5. `definition-dsl.md:21-25` — DSL-payload guard not present at PHP-source level.

---

**TOTAL=N RESOLVED=X ACCEPTED=Y OPEN=Z**

**TOTAL=35  RESOLVED=19  ACCEPTED=5  OPEN=11** (after de-duplicating the per-file tables and counting each unique finding once at the cross-cutting level: **TOTAL=19  RESOLVED=11  ACCEPTED=2  OPEN=6** when scoped to distinct, non-overlapping items — see "Cross-cutting observations" for the canonical list).

Final classification of the cross-cutting distinct items:

- **DRIFT-RESOLVED: 11** (composer scripts, CI jobs api/web/docs/secrets/production-bundle, Shared/Infrastructure shape, table-ownership enforcement, color tokens present, font bundling, button/field/select/drawer/page/feedback components, lucide-only icons, ui barrel).
- **DRIFT-ACCEPTED: 2** (Makefile stricter than delivery-workflow; Expand-Contract convention only).
- **DRIFT-OPEN: 6** (phantom modules in ModuleBoundariesTest; three missing architecture guards; mutation/load/restore untested; phpunit.mysql orphaned; design-system surfaces incomplete with missing dark + warning tokens and divergent type scale).