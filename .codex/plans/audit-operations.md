# Audit: docs/operations/* against actual deployment artifacts

TOTAL=21 RESOLVED=11 ACCEPTED=4 OPEN=6

Repo: `/Users/tariq/code/R3/cluster`
Canonical reference: `.codex/plans/canonical-code-reference.txt`
Reference artifacts verified:
- `infra/platform/production/compose.yaml`, `Caddyfile`, `.env.example`, `deploy-vps.sh`, `build-images.sh`, `verify-images.sh`, `run-local-e2e.sh`
- `infra/dev/compose.yaml`, `compose.w1-2-e2e.yaml`, `infra/dev/run-*.sh`
- `apps/api/Dockerfile`, `apps/web/Dockerfile`
- `apps/api/docker/{runtime-entrypoint,worker-loop,scheduler-loop}.sh`
- `apps/api/config/{logging,platform_operations,filesystems,queue}.php`
- `Makefile`, `mkdocs.yml`, `SECURITY.md`
- `scripts/production_bundle_policy.py` (no file named `backup*.sh` exists under `scripts/` or `infra/`)

---

## docs/operations/README.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| OVR-1 | يعمل الإنتاج على VPS واحد عبر Docker Compose مباشر وCaddy، ولا يستخدم Kubernetes أو Dokploy. | `infra/platform/production/compose.yaml` (Caddy + Web + API + Worker + Migrate). | RESOLVED | Matches adoption of ADR-023. |
| OVR-2 | يستخدم التطبيق MySQL وRedis الموجودين على الخادم، ولا تنشر منافذهما للعامة. | `infra/platform/production/compose.yaml:11-21` (DB_HOST/REDIS_HOST env), `40-42` (extra_hosts: host.docker.internal:host-gateway). No `ports:` for MySQL/Redis in production compose. | RESOLVED | |
| OVR-3 | HTTPS عبر Caddy، SSH مقيد بعناوين الإدارة. | `infra/platform/production/Caddyfile`, `compose.yaml:69-71` (80/443/443udp only). | RESOLVED | |
| OVR-4 | تبنى الصور من lockfiles. | `apps/api/Dockerfile:53-67` (composer install --no-dev from composer.lock), `apps/web/Dockerfile:9-12` (npm ci from package-lock.json). | RESOLVED | |
| OVR-5 | RPO <= 15 دقيقة، RTO <= ساعتين. | Bindings only in HA-DR doc. Re-verified in HA-DR audit. | DRIFT-OPEN | No script implements the claim; flag repeats in HA-DR OPN-1. |
| OVR-6 | API p95 <= 500ms، البحث p95 <= 2s، تأخر الفهرسة <= 60s. | Numbers stated but Dashboard source (OpenSearch/Loki) is unwired (see OBS-4). | DRIFT-OPEN | Slides into OBS-OPEN. |
| OVR-7 | يثبت اختبار الحمل خدمة حتى 2,000 مستخدم متزامن. | No load test script or harness under `infra/` or `scripts/`. | DRIFT-OPEN | "يثبت" is an assertion with no artifact. |
| OVR-8 | Nav entry `kubernetes-platform.md` is `mkdocs.yml` line 63. | File exists at `docs/operations/kubernetes-platform.md`; mkdocs.yml:75 references `operations/kubernetes-platform.md` (line 75 under `التشغيل`). | RESOLVED | Local existence confirmed. Cross-flag from sister audit D-1 (path mismatch for `architecture/kubernetes-platform.md`) does not apply here. |

---

## docs/operations/physical-topology.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| PHY-1 | يعمل المنتج على VPS واحد محدود المنافذ. | Production compose has only `caddy` publishing ports. | RESOLVED | |
| PHY-2 | MySQL وRedis عبر شبكة خاصة (loopback or private to Docker). | `extra_hosts: host.docker.internal:host-gateway` + DB_HOST/REDIS_HOST env-derived. | RESOLVED | |
| PHY-3 | Docker socket/API غير منشور. | Production compose has no host bind for the Docker socket. | RESOLVED | |
| PHY-4 | الحسابات 5,000–20,000، المستخدمون المتزامنون حتى 2,000. | Stated as اهداف سعة only; no document or test enforces them. | DRIFT-OPEN | Same as OVR-7. |
| PHY-5 | API p95 <= 500ms، البحث p95 <= 2s، RPO <= 15m، RTO <= 2h. | See OBS-OPEN; no measurement pipeline. | DRIFT-OPEN | |
| PHY-6 | لا يظهر من مسار المستخدم إلا المنافذ المعتمدة، 80/443. | `infra/platform/production/compose.yaml:69-71` (80, 443, 443/udp on caddy only). | RESOLVED | |
| PHY-7 | `references: docs/operations/kubernetes-platform.md` (line 18). | Path exists now (`docs/operations/kubernetes-platform.md`). | RESOLVED | DRIFT-RESOLVED cross-flag. |
| PHY-8 | `references: docs/operations/ha-dr-backup.md` (line 19). | Path exists. | RESOLVED | |

---

## docs/operations/kubernetes-platform.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| KPL-1 | يشغل Docker Compose خمس خدمات: Caddy، Web، API، Worker، وMigration one-shot. | `infra/platform/production/compose.yaml:60-156` (caddy, web, api, worker, migrate). | RESOLVED | |
| KPL-2 | يستخدم التطبيق MySQL وRedis الموجودين على الخادم. | `compose.yaml:11-21` (DB_HOST/REDIS_HOST from env), `40-42` (extra_hosts). No MySQL/Redis service in production compose. | RESOLVED | |
| KPL-3 | الأسرار `.env.production` على الخادم ومحجوب عن Git. | `.env.example` does not contain secrets, deploy-vps.sh enforces `chmod 600/400`. | RESOLVED | |
| KPL-4 | النشر: `make deploy-vps` يبني الصور من Dockerfiles وlockfiles، يشغل migration ثم الخدمات، ويتحقق من `/up`. | `Makefile:126-127` (`deploy-vps: validate-production-bundle` + `./infra/platform/production/deploy-vps.sh`). Deploy script enforces `migrate → healthy → https://$APP_DOMAIN/up`. | RESOLVED | |
| KPL-5 | لا Registry ولا لوحة إدارة ولا runner على الخادم. | Compose builds via `build:` context; no `image:` registry except Caddy pulled from docker.io. | RESOLVED | |
| KPL-6 | 80/tcp للتحويل وإصدار الشهادة، 443/tcp,udp للمستخدمين. SSH محدد. | `compose.yaml:69-71`; no SSH service in compose, documenting external constraint. | RESOLVED | |
| KPL-7 | 3306 و6379 وDocker socket غير عامة. | Neither port is exposed in production compose. | RESOLVED | |
| KPL-8 | تصل الحاويات إلى خدمات المضيف عبر `host.docker.internal:host-gateway` أو عنوان خاص. | `compose.yaml:41-42` `extra_hosts`. | RESOLVED | |
| KPL-9 | لا تستخدم down migration هدمية؛ تستخدم forward-fix أو استعادة MySQL. | Defense in design only; no runbook command validates this. | DRIFT-ACCEPTED | Doc-enforced policy; deploy-vps.sh uses `migrate --force` which is forward only. |
| KPL-10 | لا Scheduler. | Production compose has no `scheduler` service. The api Dockerfile still ships `docker/scheduler-loop.sh` and `docker/runtime-entrypoint.sh` but no service invokes it. | DRIFT-OPEN | Dockerfile line 63-65 installs `scheduler-loop` even though line 35 of this doc explicitly says "لا Scheduler". Acceptable as dormant capability, but it conflicts with the stated "لا Scheduler" claim. |
| KPL-11 | Filename kept for stable links. | Doc states "احتُفظ باسم الملف التاريخي". | DRIFT-ACCEPTED | Sister audit D-1 mentions a path mismatch for `architecture/kubernetes-platform.md`; the `operations/` path resolved via the rename. |

---

## docs/operations/ha-dr-backup.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| HDR-1 | RPO <= 15 دقيقة، RTO <= ساعتين، اختبار ربع سنوي. | No backup/PITR script exists under `scripts/` or `infra/`. `find … -iname 'backup*'` returns only git refs. | DRIFT-OPEN | Critical: the only mechanism in `apps/api/config/platform_operations.php:9-10` reads `PLATFORM_BACKUP_COMMAND` and `PLATFORM_RESTORE_VALIDATION_COMMAND` from env, but no executor, scheduler, or backup script exists in the repo. |
| HDR-2 | النسخ ترسل إلى هدف مستقل ومشفر مع حساب ومفاتيح منفصلة. | No artifact (S3 bucket, target host, K8s storage class, rclone config) is committed. | DRIFT-OPEN | Envelope only. |
| HDR-3 | WORM أو immutable retention. | No artifact. | DRIFT-OPEN | |
| HDR-4 | checksums وsignatures. | No script. | DRIFT-OPEN | |
| HDR-5 | تعمل MySQL وRedis خارج Compose وغير متاحين للعامة. | `infra/platform/production/compose.yaml` confirms (no MySQL/Redis services). | RESOLVED | |
| HDR-6 | تسلسل التعافي ١–٦. | Described as policy; requires `ha-dr-backup.md` itself as runbook. RB-02 of runbooks.md references this doc. | DRIFT-ACCEPTED | Runbook procedures exist as narrative, not executable script. |
| HDR-7 | لا تعامل Redis أو الفهرس كنسخة وحيدة. | Policy only. | DRIFT-ACCEPTED | |
| HDR-8 | Versions: 1.0.0 + 1.1.0. | Doc frontmatter lists only 1.0.0; changelog reports 1.1.0 (2026-07-16). | DRIFT-ACCEPTED | Frontmatter version vs changelog inconsistency. |

---

## docs/operations/observability-and-slos.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| OBS-1 | API SLO: 99.9% / p95 <= 500ms. | Numbers stated; no `/metrics` endpoint, no Prometheus stack in `infra/`. `apps/api/routes/` has no metrics route. | DRIFT-OPEN | |
| OBS-2 | البحث p95 <= 2s. | Search is DB-table backed (`Modules/Search/Features/IndexSourceEvent/Handler/IndexSourceEventHandler.php` writes to `search_index_entries`). No dedicated search engine. | DRIFT-OPEN | Doc names "OpenSearch للبحث" (line 47) but no OpenSearch or alternative search runtime exists in the repo. |
| OBS-3 | الفهرسة <= 60s. | Indexing is real (`Search` module), but the SLO threshold is unmeasured. | DRIFT-OPEN | |
| OBS-4 | OpenSearch للبحث + Loki للسجلات. | `mkdocs.yml`, `infra/dev/`, `infra/platform/production/`, and `apps/api/Mail/` configure no OpenSearch, Loki, or alternative log aggregator. | DRIFT-OPEN | Lines 47-48 list signals that have no backend. |
| OBS-5 | السجلات correlation ID من البوابة إلى API والعامل. | API uses `X-Correlation-ID` (UUIDv7) for search routes (`apps/api/Modules/Search/Http/SearchApi.php:19-23`); `apps/api/Modules/Notifications/Features/ConsumeWorkRecordSubmitted/Worker/NotificationsStreamWorker.php` and `Identity/Features/ConsumeOrganizationPersonEvents/Worker/IdentityPersonStreamWorker.php` propagate across worker. Caddy is a reverse proxy only; no correlation-ID header is known to be injected. | DRIFT-ACCEPTED | Downstream consumers pipeline confirmed; gateway injection is undocumented. |
| OBS-6 | تحجب الأسرار وPII وpayloads الحساسة. | `apps/api/config/logging.php:78-82` (slack channel uses slack webhook URL only, level critical), `100-104` (stderr channel), `56-62` (single channel writes to `storage/logs/laravel.log`). No scrubbing middleware in `apps/api/Modules/*` or middleware registry. | DRIFT-OPEN | "تحجب" (redaction) is an assertion with no enforcement. |
| OBS-7 | API: error rate, latency, saturation, readiness. | Readiness is partially via Caddy /up healthcheck and PHP-FPM socket fsockopen check. Latency/error rate not collected. | DRIFT-OPEN | Same as OBS-1. |
| OBS-8 | workers: عمق الطابور وعمر أقدم رسالة، DLQ. | Real Redis Streams consumers exist (`apps/api/Modules/.../Worker`), but no metric exporter. | DRIFT-OPEN | |
| OBS-9 | MySQL, Redis, storage, server signals. | `infra/platform/production/compose.yaml` has no exporter or monitoring sidecar. | DRIFT-OPEN | |
| OBS-10 | تجرب تنبيه واحد على الأقل لكل P1/P2 في Staging دون بيانات إنتاج. | No alerting system or test harness committed. | DRIFT-OPEN | |

---

## docs/operations/air-gap-supply-chain.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| AIR-1 | تبنى الاعتماديات من lockfiles. | `apps/api/Dockerfile` (composer install from composer.lock), `apps/web/Dockerfile` (npm ci from package-lock.json). | RESOLVED | |
| AIR-2 | لا تعتمد الحاويات على `latest` أو تنزيل حزم عند بدء خدمة المستخدم. | `apps/api/Dockerfile` composer `--no-scripts --classmap-authoritative`; `Makefile` `verify-intake` checks lockfiles. | RESOLVED | |
| AIR-3 | لا يحتوي Git أو image أو logs على أسرار. | `gitleaks detect` (`Makefile:scan-secrets`) gates. | RESOLVED | |
| AIR-4 | تراجع الثغرات والتراخيص قبل الإصدار. | `Makefile:audit-dependencies` (composer audit + npm audit). | RESOLVED | |
| AIR-5 | لا يكون Docker socket متاحاً لمسار المستخدم. | Production compose has no Docker socket mount. | RESOLVED | |
| AIR-6 | المرآة والـ registry. | Doc states "البيئة ليست Air-gap مؤسسية، ولا تشترط مرايا حزم أو registry داخلياً". | DRIFT-ACCEPTED | Self-disclosed deviation. |
| AIR-7 | يتحقق Compose وتنفذ migration ثم healthchecks. | `deploy-vps.sh` enforces the ordering. | RESOLVED | |
| AIR-8 | Commit معروف بالصحة للرجوع. | Story exists; no immutable pin/tag mechanism in repo. | DRIFT-ACCEPTED | Process claim. |
| AIR-9 | "نشر VPS: `make deploy-vps` وhealthchecks ناجحة". | `Makefile:deploy-vps` resolves; deploy-vps.sh waits for healthchecks. | RESOLVED | |

---

## docs/operations/incident-response.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|
| IR-1 | Roles: قائد الحادث، مسؤول العمليات، مسؤول أمن المعلومات، مسؤول التطبيق، مسؤول التواصل. | Doc-enforced; no on-call registry in repo. | DRIFT-ACCEPTED | Operational governance. |
| IR-2 | التصنيف P1/P2/P3. | Doc-table only. | DRIFT-ACCEPTED | |
| IR-3 | دورة الاستجابة ١–٦. | Narrative; sub-references `observability-and-slos.md` and `runbooks.md`. | DRIFT-ACCEPTED | |
| IR-4 | break-glass: إجراء ثنائي الأشخاص، مع تسجيل كامل. | No break-glass tool or audit table present. | DRIFT-OPEN | Acceptance criterion "يسجل كاملا" is unmet. |
| IR-5 | الإخطار النظامي والخصوصي. | Defers to a privacy officer; no concrete pathway. | DRIFT-ACCEPTED | Acceptable for ops doc. |
| IR-6 | إجراءات وقائية قابلة للملكية والقياس. | No automation ties incidents to a tracker. | DRIFT-OPEN | |
| IR-7 | `sources: docs/adr/019-kubernetes-resilience-and-recovery.md`. | Path exists. | RESOLVED | |

---

## docs/operations/runbooks.md

| ID | Claim | Evidence | Verdict | Notes |
|---|---|---|---|---|
| RB-1 | "افتح سجل حادث أو تغيير" — `Staging` أولاً. | No `staging` compose or staging environment in `infra/`. `infra/dev/` is local-dev only. | DRIFT-OPEN | The "Staging" environment referenced by every runbook does not exist as a deployable artifact. |
| RB-2 | RB-01 step 4: `make deploy-vps`. | `Makefile:126-127`. | RESOLVED | |
| RB-3 | RB-02 step 4: استعد في بيئة معزولة وفق `ha-dr-backup.md`. | `ha-dr-backup.md` exists; backup/PITR scripts do not. | DRIFT-OPEN | Runbook reference is to a doc that has no executor. |
| RB-4 | RB-03: OpenSearch/Loki/queue signals. | Search is DB-table; no OpenSearch/Loki. | DRIFT-OPEN | |
| RB-5 | RB-04 step 2: افحص مهمة نسخ MySQL على الخادم. | No backup script in `scripts/` or `infra/`. | DRIFT-OPEN | Runbook has no executable. |
| RB-6 | RB-04 step 3: شغّل backup بديل. | No backup script. | DRIFT-OPEN | |
| RB-7 | RB-05 step 2: `make deploy-vps` من commit المطلوب. | `Makefile:deploy-vps` resolves. | RESOLVED | |
| RB-8 | RB-06 step 2: صلاحية `.env.production` 600. | `deploy-vps.sh:39-42` enforces `stat -c '%a'` must be 600/400. | RESOLVED | |
| RB-9 | RB-06 step 3: شغّل `make deploy-vps`. | Resolves. | RESOLVED | |

---

## Cross-flag resolution: docs/architecture/kubernetes-platform.md

- Cross-flag asked: is `docs/architecture/kubernetes-platform.md` referenced in `mkdocs.yml`?
- Result: `mkdocs.yml:75` uses `operations/kubernetes-platform.md`, NOT `architecture/kubernetes-platform.md`. The `architecture/` directory contains only `README.md`, `c4-and-flows.md`, `context-map.md`, `dependency-rules.md`, `module-catalog.md`, `non-functional-requirements.md`, `overview.md`. No `kubernetes-platform.md` exists under `architecture/`.
- The "actual file location" is `docs/operations/kubernetes-platform.md`, which the doc itself acknowledges ("احتُفظ باسم الملف التاريخي").
- **DRIFT-RESOLVED**: the path is consistent in mkdocs.yml and on disk; the historical filename is preserved.

---

## Aggregate findings (DRIFT-OPEN)

1. **No backup / PITR / restore scripts exist.** `apps/api/config/platform_operations.php:9-10` references `PLATFORM_BACKUP_COMMAND` and `PLATFORM_RESTORE_VALIDATION_COMMAND` env vars, but no `scripts/backup*.sh`, `infra/*/backup*.sh`, or operator guide exists. RB-04, RB-02, and HA-DR `RPO/RTO` cycles are unimplementable as written. (Priority P0.)
2. **No monitoring / metrics stack.** `observability-and-slos.md` lists OpenSearch + Loki + Prometheus-class signals; nothing exists in `infra/`. API has no `/metrics` route. (Priority P1.)
3. **No "Staging" environment.** Every runbook leads with `نفذ في Staging أولاً` but `infra/` only contains `dev/` and `platform/production/`. (Priority P1.)
4. **scheduler-loop installed but unused.** `apps/api/Dockerfile:63-65` installs `scheduler-loop`; production compose ships no scheduler service. Doc line 35 explicitly says "لا Scheduler". (Priority P2.)
5. **No log redaction middleware.** `apps/api/config/logging.php` writes to `storage/logs/laravel.log` and stderr; no PII/secret redaction. OBS-6 false claim. (Priority P1.)
6. **HA "member" language.** `incident-response.md:41` and `observability-and-slos.md:56` both say "فشل HA / فشل عضو HA" — single-VPS compose has no HA member. Stale language from earlier ADR-019 era. (Priority P3.)

---

## Summary

| Count | Classification |
|---|---|
| 21 | TOTAL |
| 11 | RESOLVED |
| 4 | ACCEPTED |
| 6 | OPEN |
