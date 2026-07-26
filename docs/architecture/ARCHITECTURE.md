# Cluster — المعمارية التفصيلية (نسخة مُحقَّقة من الكود)

> **نطاق الوثيقة:** وصف معماري شامل لكل طبقات النظام (Laravel Modular Monolith + React/Vite)
> **مُحقَّق من قراءة فعلية للملفات**، لا من الذاكرة. كل ادعاء تقني له مرجع في الكود.
>
> **المراجع الموثوقة داخل المستودع:**
> - `docs/architecture/module-catalog.md` — الرتب، ملكية الجداول، الحدود
> - `AGENTS.md` — قواعد التطوير والاختبار
> - `apps/api/tests/Architecture/ModuleBoundariesTest.php` — الحارس التنفيذي
>
> **آخر تحديث:** 2026-07-25 — إعادة كتابة كاملة بعد التحقق من
> `app/Http/Middleware/*.php` و `Shared/Infrastructure/Outbox/*` و `docker/worker-loop.sh`.

---

## 0 · حاوية عالية المستوى (Container View)

```
┌──────────────────────────────────────────────────────────────────────────┐
│                       Browser (SPA, React 19 + Vite 8)                    │
│  · IBM Plex Sans Arabic · locale = ar (RTL) · fallback en                │
│  · Cookies httpOnly (session) + X-CSRF-Token + Idempotency-Key + If-Match│
└────────────────────────────────────┬─────────────────────────────────────┘
                                     │ HTTPS (TLS via Caddy)
                                     ▼
┌──────────────────────────────────────────────────────────────────────────┐
│              Caddy 2.10.2 (reverse proxy + ACME + HTTP/2)                │
└──────────┬───────────────────────────────────────────┬───────────────────┘
           │ static SPA                                │ /api/v1/*
           ▼                                           ▼
┌─────────────────────┐                    ┌─────────────────────────────────┐
│  web (nginx-style)  │                    │  api (php-fpm :9000)            │
│  serves dist/       │                    │  bootstrap/app.php wires         │
└─────────────────────┘                    │  EnforcePlatformMaintenance     │
                                           │  as the first middleware        │
                                           └────────────────┬─────────────────┘
                                                            │
                                                            ▼
                                           ┌─────────────────────────────────┐
                                           │  Laravel route + middleware      │
                                           │  · IdentitySessionMiddleware     │
                                           │  · RequireIdentitySession…       │
                                           │  · IdentityCsrfMiddleware        │
                                           │  · ProjectWorkRecordReadModels   │
                                           │  · ConsumeSubmittedNotification  │
                                           └────────────────┬─────────────────┘
                                                            │
                                                            ▼
                                           ┌─────────────────────────────────┐
                                           │  Controller → Handler → Domain   │
                                           │  → Module-owned Persistence      │
                                           │  → Transactional Outbox          │
                                           └────┬──────────┬─────────────┬────┘
                                                │          │             │
                                                ▼          ▼             ▼
                                       ┌──────────┐ ┌──────────┐ ┌──────────────┐
                                       │  MySQL   │ │  Redis   │ │  MinIO+ClamAV│
                                       │  8.4.6   │ │  8.2.1   │ │  (docs only)  │
                                       │  data +  │ │ sessions │ │              │
                                       │  outbox  │ │ streams  │ │              │
                                       │  inbox   │ │ cache    │ │              │
                                       └──────────┘ └──────────┘ └──────────────┘
                                                ▲
                                                │
                                           ┌────┴────────────────────────────┐
                                           │  worker (php artisan, worker-   │
                                           │  loop.sh, no HTTP listen)       │
                                           │  · organization:relay-person-…  │
                                           │  · identity:consume-person-…    │
                                           │  · work-records:relay-pending   │
                                           │  · notifications:consume-…      │
                                           └─────────────────────────────────┘

                                           ┌─────────────────────────────────┐
                                           │  scheduler (scheduler-loop.sh)   │
                                           │  · php artisan schedule:run     │
                                           └─────────────────────────────────┘
```

**خصائص البنية العامة (مُحقَّقة من الكود):**

- **Modular Monolith** بعملية PHP واحدة و 12 وحدة منطقية، يتفاعلون حصرياً عبر
  `Contracts/` و `Events/`. الاختبار `test_detects_a_cross_module_domain_import`
  في `apps/api/tests/Architecture/ModuleBoundariesTest.php` يفرض ذلك برمجياً.
- **Client-rendered SPA**: React يبنى إلى `dist/` ويقدّمه `web` كملفات ثابتة؛
  لا server-rendering.
- **Shared MySQL**: كل الوحدات تستخدم قاعدة بيانات واحدة، لكن كل جدول له مالك واحد
  مُسجَّل في `TABLE_OWNERS`. لا توجد FKs عبر الوحدات، ولا `DB::table('other_module_*')`.
- **Edge TLS**: Caddy هو نقطة الدخول للإنتاج. الـ API يستمع على `:9000` ولا يتعرض
  للشبكة الخارجية.

---

## 1 · الطبقات من أعلى إلى أسفل

```
L1  Edge / Process         Caddy · PHP-FPM · nginx · worker-loop · scheduler-loop
L2  Presentation (Web)     React 19 · shell/routes · capability guard
L3  Transport (HTTP)       Laravel routes/web.php · JSON envelopes · problem+json
L4  Boundary / Middleware  Identity · CSRF · Maintenance · Read-Model Projector
L5  Application / Feature  Single-action controllers + handlers + application svcs
L6  Domain                 Pure PHP rules · Domain Events · Contracts
L7  Persistence            Module-owned adapters · Transactions
L8  Infrastructure         Outbox · Redis Streams · S3 · ClamAV · workers
L9  Data                   MySQL · Redis · MinIO · ClamAV definitions
```

### L1 — Edge / Process

| المكوّن | الإصدار (pinned) | المصدر | الدور |
|---|---|---|---|
| Caddy | 2.10.2-alpine (sha-pinned) | `infra/platform/production/compose.yaml` | TLS reverse proxy |
| nginx-style web | `:8080` | `apps/web/Dockerfile` + `compose.yaml` | تقديم `dist/` |
| PHP-FPM | `:9000` | `apps/api/Dockerfile` + `compose.yaml` | runtime الـ API |
| worker-loop | شلّ | `apps/api/docker/worker-loop.sh` | حلقة relays + consumers |
| scheduler-loop | شلّ | `apps/api/docker/scheduler-loop.sh` | `php artisan schedule:run` |

### L2 — Presentation (apps/web/src)

```
main.tsx                       → createRoot + StrictMode + IBM Plex Sans Arabic
App.tsx                        → session restore + 401 expiry handler
shell/
  routes.ts                    → typed AppRoute + ROUTE_WORKSPACE + capabilities
  navigation.tsx               → sidebar مع تجميع workspaces
  AppWorkspace                 → Router + RouteAccessGuard
api/
  http.ts                      → requestInit · unwrap · ApiError · ResourceState
  fetcher.ts                   → Orval mutator موحّد
  generated/                   → Orval output (ممنوع التعديل اليدوي)
  <domain>.ts                  → domain wrappers (identity, organization, documents, …)
features/                      → شاشات الوحدات
ui/                            → مكتبة UI محلية
```

**القاعدة الثابتة للشاشات (مُستخرجة من `AGENTS.md` و `api/http.ts`):**
- لا تستدعي `fetch` مباشرة في المكونات.
- الـ domain wrapper يولّد `X-Correlation-ID` (uuidV7)، يضبط `Idempotency-Key`،
  يحقن `X-CSRF-Token`، ويضع `If-Match: "<lock_version>"`.
- `unwrap()` يحول `application/problem+json` إلى `ApiError` ويفك مغلف `{ data: … }`.
- `stateFromError()` يحوّل `ApiError.status` إلى `'forbidden'|'not-found'|'conflict'|'stale'|'error'`.

### L3 — Transport

- **Prefix:** `Route::prefix('api/v1')->group(...)` في `apps/api/routes/web.php`.
- **مغلفات النجاح:** `{ data: … }` (تنفكّ عبر `unwrapEnvelope`).
- **مغلفات الخطأ:** `application/problem+json` بصياغة `{ type, title, status, detail, errors[] }`.
- **رؤوس دائمة:** `X-Correlation-ID` (uuidv7)، `ETag` على كل مورد له `lock_version`.
- **رؤوس الطفرات:** `Idempotency-Key` (commands)، `X-CSRF-Token` (mutations)، `If-Match` (concurrency).
- **Accept:** `application/json, application/problem+json` (تضعه `http.ts` افتراضياً).

### L4 — Boundary / Middleware (الدقيقة الفعلية)

ملفات `apps/api/app/Http/Middleware/`:

| الملف | يُحقَّن أين | مهمته الفعلية |
|---|---|---|
| `EnforcePlatformMaintenance.php` | `bootstrap/app.php` كأول middleware على كل route | يتحقق من نافذة صيانة نشطة، يعيد 503 مع `Retry-After`. يخزّن نافذة نشطة لمدة 60s (`Cache::remember('platform:maintenance:active', …)`). يتجاوز GET/HEAD/OPTIONS و `/up` و login endpoints. مسموح للعمال الموثوقين على `internal/documents/versions/{id}/scan|reconcile-promotion` عبر `WorkerPrincipalResolver`. |
| `IdentitySessionMiddleware.php` | routes صريحة في `routes/web.php` | يتحقق من UUIDv7 regex على `X-Correlation-ID`، يحل الـ session cookie (أو fixture-bearer في local/testing)، يكتب `identity.session` و `identity.principal` على الـ request. يعيد 401 إذا فشل. |
| `RequireIdentitySessionPrincipal.php` | routes صريحة | يتحقق من تماسك: `session.user_id === principal.user_id`، كلاهما non-empty string، و `session_id` موجود. 401 عند الفشل. |
| `IdentityCsrfMiddleware.php` | routes صريحة (mutations فقط) | يستخرج `X-CSRF-Token`، يقارنه مع session عبر `ResolveSession::validateCsrf`. يتجاوز fixture-bearer sessions (لأنها للاختبار). 403 على الفشل. |
| `ProjectWorkRecordReadModels.php` | `POST /work-records` و `POST /work-records/{id}/{action}` | **يكتب إسقاطات Search + Reporting بشكل متزامن بعد كل POST**. يستدعي `IndexSourceEventHandler->handle()` و `RefreshReportingProjectionHandler->handle()` على الـ response. هذا المسار **مكمّل، لا بديل**، لمسار الـ Relay غير المتزامن (انظر §7.1). |
| `ConsumeSubmittedNotification.php` | `POST /work-records` فقط | في بيئة `testing` مع header `X-Day3-Acceptance: 1` يستهلك حدث `com.cluster.workrecord.submitted.v1` من outbox بشكل متزامن لتسليم إشعار فوري للاختبار. خارج هذه البيئة لا يفعل شيئاً. |
| `IdentityRequestAttributes.php` / `IdentityRequestBinding.php` | غير middleware — ثوابت namespace | أزواج class-names للـ attributes يكتبها `IdentitySessionMiddleware` ويقرأها الباقون. |

### L5 — Application / Feature

```
Modules/<Name>/Features/<Feature>/
├── Http/<SingleActionController>.php   (أغلبها __invoke)
├── Handler/<HandlerClass>.php          (use-case orchestration)
└── Tests/<…>Test.php
```

دور الـ controller:
1. فك/تحقق المدخلات،
2. فحص القدرة عبر `DecideAccess`،
3. استدعاء الـ handler،
4. تحويل النتيجة إلى envelope أو `problem+json`.

### L6 — Domain

- `Modules/<Name>/Domain/<…>.php` — قواعد عمل نقية (no Laravel, no DB).
- `Modules/<Name>/Contracts/<…>` — واجهات تُصدَّر لباقي الوحدات.
- `Modules/<Name>/Events/<…>` — typed outbox payloads (CloudEvents 1.0).

### L7 — Persistence

- `Modules/<Name>/Infrastructure/Persistence/Migrations/` — هجرات الوحدة.
- معاملات `lock_version` + ETag + `If-Match` لتزامن optimistic.
- ممنوع `DB::table('other_module_*')` (architecture test يفرض ذلك).

### L8 — Infrastructure (الدقيقة)

```
Shared/Infrastructure/
├── Outbox/
│   ├── TransactionalOutbox.php (Contract)
│   ├── DatabaseTransactionalOutbox.php  (يكتب سطراً في outbox_events)
│   └── OutboxEventType.php (enum جامد لكل *.v1 literal)
├── Streams/
│   ├── RedisStreamTransport.php (Contract)
│   └── PredisRedisStreamTransport.php (predis adapter)
└── RuntimeMode.php

Modules/<Name>/Infrastructure/
├── Outbox/
│   ├── Identity/Infrastructure/Outbox/IdentitySecurityEventRegistry.php
│   ├── Identity/Infrastructure/Outbox/IdentityOutbox.php
│   └── Relay/
│       ├── Organization/Infrastructure/Outbox/Relay/OrganizationPersonOutboxRelay.php
│       └── WorkRecords/Infrastructure/Outbox/Relay/RedisOutboxRelay.php
├── Authorization/ConfiguredWorkerPrincipalResolver.php
└── Persistence/Migrations/

Modules/<Name>/Features/<Feature>/Worker/
├── IdentityPersonStreamWorker.php (يستهلك 3 streams)
├── NotificationsStreamWorker.php (يستهلك 1 stream)
└── NotificationsTechnicalAlertWorker.php
```

### L9 — Data

| المخزن | الاستخدام الفعلي | الإصدار |
|---|---|---|
| MySQL | مصدر الحقيقة + `outbox_events` + `identity_inbox` + `notification_inbox` + `search_inbox` + `report_inbox` | 8.4.6 (sha-pinned) |
| Redis | sessions + cache + Streams + `platform:maintenance:active` | 8.2.1-alpine (sha-pinned) |
| MinIO | `documents-quarantine`, `documents-available` | RELEASE.2025-04-08T15-41-24Z |
| ClamAV | فحص الوثائق | 1.4.3_base (sha-pinned) |

---

## 2 · تسلسل طلب HTTP (الدقيق من قراءة middleware)

```
Browser               Caddy           Laravel                              Stores
   │                     │                 │                                  │
   │──HTTPS + cookies───▶│                 │                                  │
   │  X-Corr-ID          │──HTTP/1.1──────▶│                                  │
   │  Idempotency-Key    │                 │                                  │
   │  X-CSRF-Token       │                 │                                  │
   │  If-Match           │                 │                                  │
   │                     │                 │──EnforcePlatformMaintenance────▶ │
   │                     │                 │   (cache-remember 60s, 503 if active)
   │                     │                 │                                  │
   │                     │                 │──IdentitySessionMiddleware─────▶│
   │                     │                 │   cookie→ResolveSession          │
   │                     │                 │   writes identity.session        │
   │                     │                 │   writes identity.principal      │
   │                     │                 │                                  │
   │                     │                 │──RequireIdentitySessionPrincipal▶│
   │                     │                 │   401 if mismatch                │
   │                     │                 │                                  │
   │                     │                 │──IdentityCsrfMiddleware─────────▶│
   │                     │                 │   (mutation routes only)        │
   │                     │                 │   X-CSRF-Token vs session        │
   │                     │                 │                                  │
   │                     │                 │──Controller::__invoke           │
   │                     │                 │   validate · DecideAccess       │
   │                     │                 │   delegate to Handler           │
   │                     │                 │                                  │
   │                     │                 │──Handler→AppService             │
   │                     │                 │   beginTransaction              │
   │                     │                 │     load aggregate              │
   │                     │                 │     optimistic lock check       │
   │                     │                 │     mutate rows                 │
   │                     │                 │     outbox.append(event_type)   │
   │                     │                 │   COMMIT                        │
   │                     │                 │                                  │
   │                     │                 │──(WorkRecords only)             │
   │                     │                 │   ProjectWorkRecordReadModels    │
   │                     │                 │     sync calls:                 │
   │                     │                 │       IndexSourceEventHandler    │
   │                     │                 │       RefreshReportingProjection │
   │                     │                 │   ConsumeSubmittedNotification   │
   │                     │                 │     (only in testing+X-Day3)    │
   │                     │                 │                                  │
   │                     │◀─200 + ETag────│                                  │
   │◀─────JSON envelope──│                 │                                  │
   │  {data: ...}        │                 │                                  │
   │  X-Correlation-ID   │                 │                                  │
```

**لاحِظ بدقة:** `ProjectWorkRecordReadModels` و `ConsumeSubmittedNotification` يعملان **بعد** استدعاء الـ handler لكن **قبل** إرسال الرد. هذا يعني أن الـ response لا يُرسَل للعميل حتى يكتمل الإسقاط. هذا مقصود للكتابة، لكنه ثمن زمن استجابة. انظر §7.1 للمقارنة بالمسار غير المتزامن.

---

## 3 · الوحدات الـ 12 والرتب (مُحقَّقة من `module-catalog.md`)

```
Rank │ Module              │ ملكية الجداول                                   │ يعتمد على (Contracts/Events فقط)
─────┼─────────────────────┼─────────────────────────────────────────────────┼────────────────────────────────────
  0  │ PlatformSettings    │ settings, calendars, alerts, maintenance,       │ — (root)
     │                     │ backup ops, technical_log_archive                │
  0  │ Organization        │ cluster, facilities, units, positions, people,   │ — (root)
     │                     │ assignments, supervisory, import_jobs            │
  1  │ Identity            │ identities, users, sessions, TOTP, passwords,    │ Organization.Contracts
     │                     │ activation, identity_inbox, auth_attempt_ledgers │
  2  │ Authorization       │ roles, capabilities, delegations, denies,        │ Identity.Contracts, Organization
     │                     │ field_access, sensitive_access_events             │
  4  │ Workflow            │ workflow_* (definitions, instances, steps,       │ Identity, Organization, Authorization
     │                     │ decisions)                                       │
  5  │ WorkDefinitions     │ work_definitions + versions                      │ Identity, Authorization
  5  │ Documents           │ documents, versions, links, quarantine,          │ Identity, Authorization, Organization
     │                     │ storage_objects, upload_intents,                  │
     │                     │ document_outbox_events (ملكية خاصة)              │
  7  │ Tasks               │ tasks, participants, comments                    │ Identity, WorkDefinitions, Workflow
  8  │ WorkRecords         │ work_records + outbox_events                      │ Identity, WorkDefinitions, Workflow,
     │                     │ (مالك مُسجَّل، يُكتب منه وحدات أخرى — §7.1)      │ Tasks
 11  │ Notifications       │ notifications, notification_inbox, recipients,   │ Identity, Organization
     │                     │ dead_letters                                     │
 11  │ Search              │ search_index_entries, search_inbox, checkpoints  │ Identity, Authorization
 11  │ Reporting           │ dashboards, report_definitions, runs,            │ Identity, Authorization, WorkRecords
     │                     │ export_artifacts, read_models                    │
```

> **ديْن معماري موثَّق:** موديول `Audit` ما زال مخططاً ولا توجد حالياً
> هجرة أو ملكية لجدول `audit_events`. سجلات الوصول الحساسة باقية تحت
> `Authorization`. كما تم تحديث `TABLE_OWNERS` في موجة Task 4
> (2026-07-26): المفتاح الزائد `project_work_record_read_models` حُذِفَ
> لأنه لا يقابل أي `Schema::create`، وأصبح السجل يطابق 96 جدولاً
> مُهاجَراً بالضبط. الحارس الآن يرفض أي مفتاح زائد برسالة مستقلة.
> لمزيد من التفاصيل، راجع `module-catalog.md §4·قاعدة 2` (مرجع
> Task 4: `apps/api/tests/Architecture/ModuleBoundariesTest.php` و
> `apps/api/tests/Architecture/ModulePlacementInventory.php`).

---
## 4 · تشريح وحدة (مثال Identity، من `find` الفعلي)

```
Modules/Identity/
├── Contracts/                          (تُصدَّر لباقي الوحدات)
│   ├── ResolvePrincipalContext.php
│   ├── ResolveAccountEntitlement.php
│   ├── ResolveUserForPerson.php
│   ├── ResolveDevelopmentFixturePrincipal.php
│   ├── AuthenticateUser.php
│   ├── PreAuthThrottle.php
│   ├── IssueActivationToken.php
│   ├── ChangePassword.php
│   └── ResolveSession.php
├── Domain/                             (pure)
├── Exceptions/
├── Features/
│   ├── Authentication/
│   │   ├── Contracts/AuthenticateUser.php
│   │   └── Handler/AuthenticateUserHandler.php
│   ├── Activation/
│   │   ├── Contracts/IssueActivationToken.php
│   │   └── Handler/IssueActivationHandler.php
│   ├── Sessions/
│   │   ├── Contracts/ResolveSession.php
│   │   └── Handler/…
│   ├── Credentials/                    (password change/rotation)
│   ├── Totp/
│   ├── UserAccount/                    (CRUD users)
│   ├── DevelopmentFixtureLogin/Http/DevelopmentFixtureLoginController.php
│   ├── ResolveDevelopmentFixturePrincipal/Http/…Controller.php
│   └── ConsumeOrganizationPersonEvents/
│       ├── Handler/ConsumeOrganizationPersonEventHandler.php
│       └── Worker/IdentityPersonStreamWorker.php
├── Http/                               (controllers المتبقية)
├── Infrastructure/
│   ├── Outbox/
│   │   ├── IdentityOutbox.php
│   │   └── IdentitySecurityEventRegistry.php
│   ├── Persistence/Migrations/         (هجات الـ Identity)
│   └── Security/                       (adapters security events)
├── Providers/
└── Tests/Fixtures/
```

**القواعد التي يفرضها `ModuleBoundariesTest.php`:**

| القاعدة | الاختبار |
|---|---|
| ترتيب الرتب | `test_detects_a_cross_module_domain_import` |
| ملكية الجداول | `test_every_migrated_table_has_an_owner_and_owners_match_actual_module_layout` |
| موقع الـ controller | `test_detects_a_business_controller_outside_its_module` |
| عدم استخدام `Request*` كمعرّف | `test_rejects_requests_as_a_business_module_or_identifier` |
| تطابق event مع JSON schema | `test_every_event_type_in_outbox_has_a_matching_json_schema` |
| الوحدات المخطّطة فارغة | `test_planned_modules_have_no_implementation_directory_yet` |

---

## 5 · خريطة تطبيق الواجهات (Web Module Map — مُحقَّقة)

```
apps/web/src/
├── main.tsx                  createRoot + StrictMode + IBM Plex Sans Arabic
├── App.tsx                   session restore + 401 expiry handler
├── AppWorkspace              حلّ typed routes مع capability gate
├── shell/
│   ├── routes.ts             AppRoute + capability map (typed Record)
│   ├── navigation.tsx        sidebar
│   └── capabilities.ts       (مُتحقَّق من اسم route)
├── api/
│   ├── http.ts               requestInit · unwrap · ApiError · ResourceState
│   │                         (مصدر الحقيقة للمعالجة الموحَّدة)
│   ├── fetcher.ts            Orval mutator
│   ├── generated/            Orval output (ممنوع يدوياً)
│   ├── session.ts            /identity/me, /identity/login, csrf
│   ├── identity.ts           users / activations / TOTP
│   ├── organization.ts       cluster, facilities, units, people, assignments
│   ├── documents.ts          create, version, upload intent, scan, download
│   ├── work-records.ts       submit / lifecycle / authorized reads
│   ├── platform-settings.ts  overview / calendars / maintenance / backups / health
│   ├── w1-3/                 wave 1.3 contracts
│   └── r1.ts                 release 1 entrypoints
├── features/                 شاشات الوحدات
├── charts/                   تصدير رسوم بيانية
├── ui/                       مكتبة UI محلية
└── styles/                   تنسيقات
```

**خط أنابيب طلب API في الويب (مُستخرَج من `http.ts`):**

```
Component (useState/useEffect/useCallback)
   │
   ▼
Domain wrapper (e.g. documents.create)
   │   ▸ يبني Idempotency-Key (uuidV7)
   │   ▸ يحقن X-CSRF-Token إذا mutation/command
   │   ▸ يضبط If-Match إذا وُجد lock_version
   ▼
Orval generated client (fetcher)
   │
   ▼
fetcher → requestInit(token, options)   (http.ts)
   │   ▸ X-Correlation-ID = uuidV7()
   │   ▸ Accept: application/json, application/problem+json
   │   ▸ credentials: 'include'
   ▼
fetch
   │
   ▼
unwrap(response)
   │   ▸ if 4xx: throw ApiError(problemFrom(...))
   │   ▸ if 401: notifySessionExpired()
   │   ▸ peel { data } envelope
   │   ▸ stamp entity.lock_version from ETag
   ▼
stateFromError(err) → 'forbidden'|'not-found'|'conflict'|'stale'|'error'
```

---

## 6 · Topology الإنتاج (مُحقَّق من compose.yaml)

```
                          ┌─────────────────────────────┐
                          │        Caddy (TLS)          │
                          │  · ACME auto-renew          │
                          │  · HTTP/2 + compression     │
                          └────────┬──────────┬─────────┘
                                   │          │
                  static SPA       │          │  /api/*
                                   ▼          ▼
                          ┌─────────────┐  ┌────────────────────┐
                          │  web (8080) │  │   api (php-fpm     │
                          │  /up check  │  │   :9000 socket)   │
                          └─────────────┘  └─────────┬──────────┘
                                                     │
                                                     ▼
                          ┌──────────────────────────────────────────┐
                          │  middleware chain                       │
                          │  EnforcePlatformMaintenance →            │
                          │  IdentitySessionMiddleware →            │
                          │  RequireIdentitySessionPrincipal →      │
                          │  IdentityCsrfMiddleware →               │
                          │  Controller → Handler → Application     │
                          └──────────────────────────────────────────┘
                                                     │
                                ┌────────────────────┼────────────────────┐
                                ▼                    ▼                    ▼
                       ┌─────────────────┐   ┌────────────────┐   ┌────────────────┐
                       │  worker         │   │  scheduler     │   │  migrate       │
                       │ worker-loop.sh  │   │ scheduler-loop │   │ one-shot       │
                       │ (no HTTP)       │   │ (no HTTP)      │   │ (no restart)   │
                       └────────┬────────┘   └────────┬───────┘   └────────┬───────┘
                                │                    │                    │
                                ▼                    ▼                    ▼
                       ┌──────────────────────────────────────────────────────────┐
                       │   MySQL · Redis · MinIO · ClamAV                         │
                       └──────────────────────────────────────────────────────────┘

Networks: `app` (internal)
Volumes:  app-storage, caddy-data, caddy-config
Security: read_only FS · tmpfs scratch · cap_drop ALL · no-new-privileges · init ·
          stop_grace_period 20s
Health  : php-fpm socket (api), /up endpoint (web), /tmp/worker.ready (worker),
          /tmp/scheduler.ready (scheduler)
```

**`worker-loop.sh` الفعلي** (من `apps/api/docker/worker-loop.sh`):

```sh
while :; do
  php artisan organization:relay-person-events --once --no-interaction
  php artisan identity:consume-person-events --once --consumer="$consumer" --no-interaction
  php artisan work-records:relay-pending --once --no-interaction
  php artisan notifications:consume-work-record-submitted --once --consumer="$consumer" --no-interaction
  touch /tmp/worker.ready
  sleep "$WORKER_POLL_SECONDS"
done
```

**أربع عمليات متكررة**، كل واحدة `--once`، مع `consumer` group واحد (`production-worker` افتراضياً).

---

## 7 · آليات تفصيلية (الآن مع الحقيقة)

### 7.1 · Outbox + Streams — مساران حقيقيان، لا واحد

**المسار 1 — غير متزامن (المُعتمد لـ WorkRecords و Organization):**

```
Handler ─▶ DB::transaction {
                 mutate aggregates (table X)
                 DatabaseTransactionalOutbox::append(event_type, payload)
                 // outbox_events row inserted in same transaction
              } COMMIT
                                │
                                ▼
                       outbox_events (مالكه WorkRecords في TABLE_OWNERS، لكن
                                       يُكتب منه: Tasks, Workflow,
                                       PlatformSettings, WorkRecords)
                                │
                                ▼  (worker-loop.sh يستدعي work-records:relay-pending)
                ┌──────────────────────────────────────┐
                │  WorkRecords/Infrastructure/Outbox/  │
                │  Relay/RedisOutboxRelay.php          │
                │  · reads pending (WHERE published_at IS NULL)│
                │  · calls transport->xadd(STREAM, …)  │
                │  · updates published_at = NOW()      │
                └─────────────┬────────────────────────┘
                              ▼
                platform.work-record.submitted.v1 (Redis Stream)
                              │
                              ▼
                NotificationsStreamWorker
                · XREADGROUP group=notifications.work-record.v1
                · ConsumeWorkRecordSubmittedHandler->handle(event)
                · ACK or DLQ
```

نفس النمط لـ `OrganizationPersonOutboxRelay` (4 streams) و `IdentityPersonStreamWorker`
(3 streams بـ `platform.dlq.v1` كـ dead-letter queue).

**⚠ تضارب معماري موثَّق:** `outbox_events` مُسجَّل في `TABLE_OWNERS` كجدول مملوك
لـ `WorkRecords` فقط، لكنه فعلياً يُكتب من **أربع وحدات**:
- `WorkRecords/Features/SubmitWorkRecord/Handler/SubmitWorkRecordHandler.php` (سطر 70: `DB::table('outbox_events')->insert(...)`)
- `Modules/Tasks/Features/.../Handler/...` (عبر `DatabaseTransactionalOutbox->append`)
- `Modules/Workflow/Features/.../Handler/...` (نفس النمط)
- `Modules/PlatformSettings/Features/Settings/Handler/PlatformSettingsHandler.php` (سطر 130)

`DatabaseTransactionalOutbox` لا يُقيَّد على WorkRecords رغم اسم الجدول. هذا **ديْن معماري**
يحتاج إما: (أ) تقسيم الجدول لكل وحدة، أو (ب) إعادة تسجيله كـ Shared Infrastructure
بصراحة في `TABLE_OWNERS`. حالياً **الاختبار المعماري لا يكشفه** لأن `outbox->append()`
يكتب عبر Eloquent ولا يُفحص مصدره.

**المسار 2 — متزامن (مكمّل لـ WorkRecords فقط):**

```
POST /work-records  ─▶  Handler (mutates + outbox.append)
                          │
                          ▼
                       Controller response (200 + ETag)
                          │
                          ▼
                       ProjectWorkRecordReadModels middleware
                          │
                          ├──▶ IndexSourceEventHandler->handle($event)
                          │      (Search writes search_index_entries — sync)
                          │
                          └──▶ RefreshReportingProjectionHandler->handle($event)
                                 (Reporting refreshes report_read_models — sync)
                          │
                          ▼
                       ConsumeSubmittedNotification middleware
                          │
                          └──▶ (only in testing + X-Day3-Acceptance: 1)
                                 read outbox_events for this recordId
                                 ConsumeWorkRecordSubmittedHandler->handle($cloudEvent)
                          │
                          ▼
                       response sent to client
```

**لماذا المسار 2؟** لا أعرف من الكود وحده — لكن من السياق: المسار 1 يصل متأخراً بدورة
worker واحدة، وقد يكون مطلوباً أن يكون الإسقاط متاحاً فوراً في POST response
(لأن الـ UI قد يحتاج البحث/التقرير في نفس الـ session). هذا تفسير معقول وليس حقيقة موثقة.

**⚠ ما لا يوجد:** لا يوجد `search:*` أو `reporting:*` worker command في `worker-loop.sh`.
Search و Reporting يُحدَّثان **حصراً** عبر المسار 2 (sync middleware). إذا مات
`worker-loop` فلن يصلها شيء من Streams، لكن الـ `outbox_events` ستبقى معلقة في
WorkRecords/Organization.

**ملاحظة إضافية:** يوجد relay سادس مُسجَّل في `routes/console.php` لكنه **لا يُستدعى
من worker-loop.sh**: `platform-settings:relay-technical-alerts` (الذي يستهلك
`TechnicalAlertOutboxRelay`). يبدو أنه لا يُشغَّل في الإنتاج الحالي — يمكن تشغيله
يدوياً أو إضافته للـ loop.

**ضمانات:**

- **لا فقدان حدث على المسار 1:** التسجيل يتم داخل نفس transaction مع الـ business write.
- **At-least-once على Streams:** `delivery_attempts` يُزاد، `published_at` يُحدَّث بعد `XADD`.
  أما عند فشل `XADD` فالصف يبقى `published_at IS NULL` ويُعاد محاولة.
- **Idempotency في المستهلكين:** `event_id` (uuidv7) يُستخدم لتجنّب الازدواج.
- **DLQ:** `IdentityPersonStreamWorker` يدفع إلى `platform.dlq.v1` بعد `MAX_ATTEMPTS = 3`.

**JSON Schemas contracts** موجودة في `docs/contracts/schemas/` (54 schema + 1 `_template` = **55 ملف**) — كل `*.v1` literal
يجب أن يكون حالة على `OutboxEventType` وله schema.

### 7.2 · Security & Concurrency Stack

```
                        ┌──────────────────────────────────────────┐
                        │      Client (Web) prepares request       │
                        │  · X-Correlation-ID (uuidV7)            │
                        │  · Idempotency-Key  (لـ commands)        │
                        │  · X-CSRF-Token     (session-bound)      │
                        │  · If-Match: "<lock_version>"            │
                        │  · Accept: application/json,             │
                        │           application/problem+json        │
                        └─────────────────────┬────────────────────┘
                                              ▼
                              ┌────────────────────────────────────┐
                              │  EnforcePlatformMaintenance         │
                              │  · cache-remember(active window)    │
                              │  · 503 + Retry-After if active      │
                              │  · bypass GET/HEAD/OPTIONS + login  │
                              │  · bypass trusted internal workers  │
                              └─────────────┬──────────────────────┘
                                            ▼
                              ┌────────────────────────────────────┐
                              │  IdentitySessionMiddleware          │
                              │  · UUIDv7 regex on X-Correlation-ID │
                              │  · cookie → ResolveSession          │
                              │  · fixture-bearer fallback (local)  │
                              │  · writes identity.session & principal│
                              │  · 401 on failure                   │
                              └─────────────┬──────────────────────┘
                                            ▼
                              ┌────────────────────────────────────┐
                              │  RequireIdentitySessionPrincipal    │
                              │  · session.user_id == principal     │
                              │    .user_id (نفس الـ UUID)          │
                              │  · 401 على عدم التماسك              │
                              └─────────────┬──────────────────────┘
                                            ▼
                              ┌────────────────────────────────────┐
                              │  IdentityCsrfMiddleware              │
                              │  · X-CSRF-Token vs session          │
                              │  · bypass fixture-bearer sessions   │
                              │  · 403 on failure                   │
                              └─────────────┬──────────────────────┘
                                            ▼
                              ┌────────────────────────────────────┐
                              │  Controller::__invoke               │
                              │  · Validate request                 │
                              │  · DecideAccess (RBAC + ABAC)       │
                              │  · Run Handler                      │
                              └─────────────┬──────────────────────┘
                                            ▼
                              ┌────────────────────────────────────┐
                              │  Handler → ApplicationService       │
                              │  · beginTransaction                 │
                              │  · optimistic lock_version check    │
                              │  · mutate rows                      │
                              │  · outbox.append(event_type, …)     │
                              │  · COMMIT                           │
                              │  · 412 إذا If-Match فشل            │
                              │  · 409 إذا تعارض آخر               │
                              └─────────────┬──────────────────────┘
                                            ▼
                              ┌────────────────────────────────────┐
                              │  ProjectWorkRecordReadModels (route-bound)│
                              │  · sync IndexSourceEventHandler     │
                              │  · sync RefreshReportingProjection  │
                              │  (WorkRecords فقط)                  │
                              │  ConsumeSubmittedNotification (testing only)│
                              └────────────────────────────────────┘
```

### 7.3 · RBAC + ABAC — مع ملاحظة عن N+1

`DecideAccess::decide(actor, capability, RecordFacts)` يُستخدم في ثلاث أنماط:

1. **Per-request**: استدعاء واحد لكل HTTP request في `EnforcePlatformMaintenance::canManageMaintenance`
   وفي معظم الـ controllers.
2. **Per-row في Search**: `SearchAccessibleRecordsHandler` يستدعي `DecideAccess` في loop
   لكل صف من `search_index_entries`. هذا **N+1 authorization queries** حقيقي.
3. **Per-document grant check** في `Documents`.

التخفيف الحالي: تخزين مؤقت لنتيجة الـ middleware في `Cache::remember('platform:maintenance:active', 60, …)`.
لا يوجد تخفيف مُوثَّق لـ N+1 في Search — هذا **ديْن تحسيني** يجب الإشارة إليه في
تقرير منفصل.

### 7.4 · Documents Pipeline

```
Initiate DocumentUpload intent (Controller)
   │
   ▼
INSERT INTO document_upload_intents + outbox.append(document.upload_intent.created.v1)
   │
   ▼
Web يستلم presigned URL من MinIO
   │
   ▼
Multipart upload مباشر إلى documents-quarantine bucket
   │
   ▼
CompleteDocumentUpload (Controller)
   │   يحدّث scan_status='pending'
   │   outbox.append(document.upload.completed.v1)
   ▼
Trusted internal worker (POST /internal/documents/versions/{id}/scan أو reconcile-promotion)
   │   مُعرَّف عبر WorkerPrincipalResolver
   │   · DocumentScanAdapter → ClamAV TCP 3310
   │   · إن نجح: نقل الكائن من quarantine إلى available
   │   · outbox.append(document.scanned.v1, document.promoted.v1)
   │   · إن فشل: quarantine record + document.scan_failed.v1
   ▼
Download
   · CreateDocumentGrantController يولد presigned URL قصير TTL (SigV4)
   · الـ Web يفتح URL مباشرةً، لا يمر عبر php-fpm
```

### 7.5 · Idempotency

- على الويب: `uuidV7()` كـ `Idempotency-Key` (يلحق `X-Correlation-ID` بصيغة `cluster-<corr>` لو لزم).
- على الخادم: كل وحدة تملك `*_idempotency_keys` تحتفظ بـ `(key, response_body, status)`.
- **استثناء موثَّق:** `MarkNotificationReadController` لا يحتاجها — UPDATE مشروط
  (`WHERE id=…`) بطبيعته idempotent (قرار 6.1 في module-catalog).
- **Throttling:** `/identity/activation` بـ `throttle:6,1`.

### 7.6 · Locale

- الـ API: `APP_LOCALE=ar`, `APP_FALLBACK_LOCALE=en`. رسائل `problem+json` مُعرَّبة
  عبر IdentityApi::problem() وتختار اللغة من `Accept-Language` (ar/en).
- الـ Web: يستخدم `IBM Plex Sans Arabic`، يعرض RTL. لا ترجمة runtime على الـ Web —
  النصوص تأتي جاهزة من الـ API في `detail`/`title` أو من ملفات i18n محلية.

### 7.7 · Streams — الأسماء الفعلية

مُستخرجة من الكود (`OrganizationPersonOutboxRelay.php`، `RedisOutboxRelay.php`،
`IdentityPersonStreamWorker.php`، `NotificationsStreamWorker.php`):

| Stream | المنتج | المستهلك |
|---|---|---|
| `platform.organization.identity-provisioning-requested.v1` | OrganizationPersonOutboxRelay | IdentityPersonStreamWorker |
| `platform.organization.person-access-status-changed.v1` | OrganizationPersonOutboxRelay | IdentityPersonStreamWorker |
| `platform.organization.person-registered.v1` | OrganizationPersonOutboxRelay | IdentityPersonStreamWorker |
| `platform.organization.person-updated.v1` | OrganizationPersonOutboxRelay | IdentityPersonStreamWorker |
| `platform.work-record.submitted.v1` | RedisOutboxRelay | NotificationsStreamWorker |
| `platform.dlq.v1` | (DLQ) | IdentityPersonStreamWorker يدفع هنا بعد 3 محاولات |

**Consumer Group:** `identity.organization-person-events.v1` (في IdentityPersonStreamWorker).

**MAX_BATCH_SIZE:** 100 لكل relay، مع `MAX_ATTEMPTS = 3` في IdentityPersonStreamWorker.

---

## 8 · طبقات الدفاع (Defense in Depth)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Layer                  │ Control (مُحقَّق)                                │
├────────────────────────┼──────────────────────────────────────────────────┤
│ Edge (Caddy)           │ TLS · ACME · HTTP/2 · compression               │
│ Container              │ read_only · tmpfs · cap_drop ALL · no-new-privs │
│ API Bootstrap          │ APP_DEBUG=false · JSON-only · problem+json ·   │
│                        │ X-Correlation-ID                                │
│ Session/CSRF           │ Redis session · Secure cookie (prod) ·         │
│                        │ IdentityCsrfMiddleware (X-CSRF-Token) ·         │
│                        │ RequireIdentitySessionPrincipal binding check   │
│ Auth                   │ TOTP · throttle 6,1 on activation ·            │
│                        │ auth_attempt_ledgers · password_history ·      │
│                        │ locked-account outbox event                    │
│ Authorization          │ RBAC + ABAC · explicit_denies · delegations ·  │
│                        │ field_access_templates · sensitive_access_      │
│                        │ events ledger                                   │
│ Idempotency            │ uuidV7 keys per module + lock_version          │
│ Data integrity         │ transactional outbox · optimistic locking ·    │
│                        │ cursor pagination                               │
│ Storage                │ MinIO presigned URLs (short TTL) · quarantine   │
│                        │ bucket · ClamAV scan                           │
│ Auditability           │ outbox_events · access_decisions ·             │
│                        │ auth_attempt_ledgers · document_access_events  │
│ Secret scanning (CI)   │ gitleaks · .gitleaks.toml                       │
│ Dependency scanning    │ composer audit · npm audit                      │
│ Architecture gate      │ ModuleBoundariesTest (PHPUnit)                  │
│ Static analysis        │ PHPStan/Larastan · Pint · oxlint · Prettier     │
└──────────────────────────────────────────────────────────────────────────┘
```

**ثغرات موثَّقة يجب الإشارة إليها في تقييم مخاطر:**
- **دقة `TABLE_OWNERS`**: بعد موجة Task 4 (2026-07-26) أصبحت السجلات
  تطابق الجداول المُهاجرة بالضبط (96 لـ 96)، والمفتاح الزائد
  `project_work_record_read_models` حُذِفَ مع توثيق السياسة في تعليق
  رأس `ModuleBoundariesTest.php`. أي مفتاح زائد مستقبلي يُرفض الآن
  برسالة مستقلة عبر `test_every_migrated_table_has_an_owner_and_owners_match_actual_module_layout`.
- **Work-record sync الإسقاط داخل HTTP** (§7.1): يطيل زمن استجابة POST.
- **N+1 authorization في Search** (§7.3): لا تخفيف مؤقت؛ مع نمو البيانات ستظهر
  bottleneck واضح.
---

## 9 · خريطة التخزين (مُحقَّقة)

```
┌────────────────────────────────────────────────────────────────────────┐
│                       MySQL 8.4.6 (sha-pinned)                         │
│                                                                        │
│  جداول حسب الوحدة المالكة (مُستخرَجة من module-catalog §2):            │
│   Organization     → clusters, facilities, units, positions, people,   │
│                      assignments, supervisory_relationships,            │
│                      temporary_assignments (+ capabilities),            │
│                      import_jobs, import_rows, organization_*          │
│   PlatformSettings → platform_settings, *_versions, outbox,            │
│                      alert_policies, business_calendars, weekdays,      │
│                      exceptions, maintenance_windows, operation_*      │
│                      technical_log_archive_*                            │
│   Identity         → identities, users, identity_sessions,              │
│                      credentials, identity_totp, identity_inbox,        │
│                      identity_activation_tokens, password_history,      │
│                      auth_attempt_ledgers, *_idempotency_keys          │
│   Authorization    → roles, capabilities, role_capabilities,           │
│                      role_assignments, delegations, explicit_denies,    │
│                      classification_policies, field_access_templates,   │
│                      sensitive_access_events, *_idempotency_keys,       │
│                      access_decisions                                   │
│   Workflow         → workflow_definitions, *_versions, *_instances,     │
│                      step_instances, decisions, *idempotency_keys       │
│   WorkDefinitions  → work_definitions, *_versions, *idempotency_keys    │
│   Documents        → documents, *_versions, links, quarantines,         │
│                      storage_objects, upload_intents,                   │
│                      restriction_facts, access_events,                   │
│                      document_outbox_events (وليس outbox_events)        │
│   Tasks            → tasks, participants, comments, *idempotency_keys  │
│   WorkRecords      → work_records, *idempotency_keys                   │
│   Notifications    → notifications, notification_inbox, recipients,    │
│                      dead_letters                                       │
│   Search           → search_index_entries, search_inbox, checkpoints    │
│   Reporting        → report_definitions, report_inbox,                  │
│                      report_read_models, report_runs,                   │
│                      export_artifacts, dashboard_definitions            │
└────────────────────────────────────────────────────────────────────────┘

**جداول Outbox — نموذجان منفصلان (لا جدول مشترك واحد):**

| الجدول | المالك المُسجَّل | من يكتب فيه فعلياً | من يقرأ |
|---|---|---|---|
| `outbox_events` | `WorkRecords` | `WorkRecords` (مباشر) + `Tasks`, `Workflow`, `PlatformSettings` (عبر `DatabaseTransactionalOutbox`) | `WorkRecords/Infrastructure/Outbox/Relay/RedisOutboxRelay` (→ `platform.work-record.submitted.v1`)، `Organization/Infrastructure/Outbox/Relay/OrganizationPersonOutboxRelay` (→ 4 streams) |
| `document_outbox_events` | `Documents` | `Documents` فقط (مباشر في `LinkDocumentController`, `UpdateDocumentController`, `DocumentUploadHandler`) | لا relay مُسجَّل — يُكتب مباشرة بدون Streams |

**⚠ تضارب معماري:** `outbox_events` مُسجَّل تحت `WorkRecords` لكن كتابته مشتركة. انظر §7.1.

┌────────────────────────────────────────────────────────────────────────┐
│                       Redis 8.2.1 (sha-pinned)                         │
│                                                                        │
│  Connection 'default' (REDIS_DB=0):                                    │
│   Streams: platform.organization.identity-provisioning-requested.v1     │
│            platform.organization.person-access-status-changed.v1        │
│            platform.organization.person-registered.v1                   │
│            platform.organization.person-updated.v1                      │
│            platform.work-record.submitted.v1                            │
│            platform.dlq.v1                                             │
│   Sessions:  SESSION_DRIVER=redis (يستخدم default connection = DB 0)   │
│   Cache:     platform:maintenance:active (60s TTL)                      │
│   Other:     development-fixture-bearer (file store في dev/testing)     │
│                                                                        │
│  Connection 'cache' (REDIS_CACHE_DB=1):                                 │
│   Cache store (CACHE_STORE=redis في الإنتاج)                           │
│                                                                        │
│  prefix: cluster_  (REDIS_PREFIX)                                       │
│  client: predis (REDIS_CLIENT=predis في الإنتاج)                        │
└────────────────────────────────────────────────────────────────────────┘

**المصدر:** `apps/api/config/database.php` السطور 156-180 و `config/session.php` السطر 21.
`SESSION_CONNECTION` غير مُعيَّن صريحاً → يرجع إلى `default`.

┌────────────────────────────────────────────────────────────────────────┐
│                       MinIO (S3-compatible)                            │
│  Buckets: documents-quarantine (private)                                │
│           documents-available  (private)                                │
│  Upload:   server-issued multipart presigned URLs                       │
│  Download: SigV4 presigned URLs (short TTL, scoped by grant)           │
└────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────┐
│                       ClamAV 1.4.3_base                                │
│  Port 3310 (clamdscan protocol)                                          │
│  Healthcheck: --config-file=/etc/clamav/clamd.conf --ping 1              │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 10 · خريطة مسارات `/api/v1` (مُختصر)

```
/api/v1
├── auth/login                                DevelopmentFixtureLogin (local/testing)
├── identity/login                            IdentityLogin
├── identity/activation                       ConsumeActivation (throttle 6,1)
├── identity/me                               GetCurrentIdentity  [IdentitySession+Require]
├── identity/csrf                             RefreshIdentityCsrf [IdentitySession+Require]
├── identity/logout                           IdentityLogout       [IdentitySession+Require+CSRF]
│
├── access/scopes                             ListMyScopes / SelectMyScope
├── access/context                            GetCurrentPrincipal
├── access/explanation                        Access-explanation
│
├── organization/*                            35 controllers (cluster, facility, unit,
│                                              position, person, assignment, temporary,
│                                              importJob)
│
├── documents/*                               create / addVersion / initiateUpload /
│                                              completeUpload / scan / transition /
│                                              download / link / reconcile / grants
│
├── internal/documents/versions/{id}/scan            (WorkerPrincipalResolver)
├── internal/documents/versions/{id}/reconcile-…     (WorkerPrincipalResolver)
│
├── work-records                              Submit / Lifecycle / List / Get
│                                              [POST: ProjectWorkRecordReadModels + ConsumeSubmittedNotification]
│
├── work-definitions, workflow/*              Authoring & instances
├── tasks/*                                   Task lifecycle & engagement
├── approvals/*                               Approval-inbox / Approval-detail
├── workflow-admin/*                          Admin-only
│
├── authorization/*                           Admin (roles, caps, role-assignments,
│                                              delegations, supervisory, classification,
│                                              field-access, bootstrap)
│                                              DecideAccess (POST access-decisions)
│                                              ExplainAccessDecision
│
├── platform-settings/*                       Versions / Publish / Validate / UpdateValue /
│                                              Calendars / Maintenance / Backups /
│                                              Alerts / Logs / Operations / Health
│
├── search                                    SearchController (per-row DecideAccess — N+1)
├── reports / dashboards / exports            Reporting read models
└── notifications                             List / Mark-read
```

---

## 11 · CI/CD والـ Make targets (مُحقَّقة)

```
.github/workflows/
├── ci.yml             PR gate (lint, PHPStan, PHPUnit, web build/coverage,
│                      architecture, secrets, prod-bundle verify)
└── ci-e2e.yml         self-hosted runner, label=cluster-e2e, 30m timeout,
                       runs verify-w1-1-local + uploads test-results

Makefile targets (الأكثر استخداماً):
├── verify-intake            lockfiles + manifest/package integrity
├── test-api                 composer test (PHPUnit 12.5)
├── test-web                 build + lint + coverage
├── test-web-unit            Vitest 4 only
├── verify-boundaries         module architecture guard
├── lint-api                  Pint
├── analyse-api               PHPStan/Larastan
├── test-e2e-w1-1             local W1.1 browser workflow
├── verify-w1-1               broad local quality gate
├── verify-mysql-integration  optional MySQL walking-skeleton
└── docs-validate             scripts/validate-docs.sh (Python 3.12)
```

---

## 12 · بيانات المرجع

```
docs/contracts/
├── api/                          OpenAPI contracts
│   ├── openapi.yaml              (current)
│   ├── r1-screens.openapi.yaml
│   ├── w1-1.openapi.yaml         (frozen — لا تعدّل عرضاً)
│   └── w1-2.openapi.yaml         (frozen)
└── schemas/                      JSON Schemas لكل outbox event (Draft 2020-12)
                                   55 ملف (54 schema + 1 _template) مُحقَّق آلياً

docs/architecture/
├── module-catalog.md             الرتب، المالكات، الحدود (المرجع الأعلى)
└── ARCHITECTURE.md               هذا الملف
docs/analysis/                    تعمّق في كل موديول (12 وثيقة على القرص)
```

---

## 13 · End-to-End Use Case — رفع مستند

```
0. Web:
   - المكوّن يستدعي documents.create({...})
   - documents.ts يحقن Idempotency-Key, X-CSRF-Token, Accept

1. Caddy:
   - ينهي TLS، يوجّه /api/* إلى api:9000

2. Laravel Boundary:
   - EnforcePlatformMaintenance → يتجاوز (GET/OPTIONS/login)
   - IdentitySessionMiddleware → يحل session+principal
   - RequireIdentitySessionPrincipal → 401 إذا انكسر التماسك
   - IdentityCsrfMiddleware → يقارن X-CSRF-Token

3. InitiateDocumentUploadController:
   - DecideAccess(principal, 'document', 'create.intent') → allow
   - Handler:
       - beginTransaction
       - INSERT document_upload_intents
       - outbox.append(document.upload_intent.created.v1)
       - COMMIT

4. Web:
   - يستلم { data: { upload_url, fields } } + ETag
   - multipart upload إلى MinIO presigned URL
   - CompleteDocumentUploadController → scan_status=pending
   - outbox.append(document.upload.completed.v1)

5. Trusted internal worker (POST /internal/.../scan):
   - WorkerPrincipalResolver → موافقة
   - DocumentScanAdapter → ClamAV TCP 3310
   - إن نجح: انتقال من quarantine → available
   - outbox.append(document.scanned.v1, document.promoted.v1)

6. Search/Reporting (متزامن، ليس عبر Streams):
   - ProjectWorkRecordReadModels middleware يستدعي IndexSourceEventHandler
     و RefreshReportingProjectionHandler مباشرة على الـ response
   - ⚠ لا يوجد search:* أو reporting:* worker command في worker-loop.sh
     (§7.1 تناقض 11). الإسقاط يحدث فقط عبر middleware

7. الرد النهائي: 200 OK · ETag · X-Correlation-ID · { data: … }

8. Auditability: access_decisions يحوي قرار الرفع،
   document_access_events يحوي أثر التحميل.
```

---

## 14 · الأداء وقابلية التوسع — عيوب موثقة

| البُعد | السلوك الحالي | التحسينات المطلوبة |
|---|---|---|
| حساب الصلاحيات | DB joins لكل استدعاء، N+1 في Search | ACL projection مفهرسة + batch authorization |
| WorkRecords POST latency | sync إسقاط Search + Reporting داخل الـ request | فصل Relay أو جعله async مع progress |
| idempotency tables | TTL قصير لكل وحدة | يمكن توحيد TTLs |
| Streams throughput | consumer واحد (`production-worker`) افتراضياً | توسيع horizontal عبر consumers متعددة |
| multi-tenant | قاعدة بيانات واحدة بـ cluster واحد | إذا زاد العملاء: شظايا DB أو cluster-per-tenant |
| RPO/RTO | لا backup strategy موثقة في الـ repo | يستحق توثيق صريح |

---

## 15 · «أين أبحث» (Where to Look)

| السؤال | الملف/المسار |
|---|---|
| ما هي الوحدات ورتبها؟ | `docs/architecture/module-catalog.md` |
| من يملك جدول `xyz`؟ | `apps/api/tests/Architecture/ModuleBoundariesTest.php` → `TABLE_OWNERS` |
| الـ middleware على route ما؟ | `apps/api/routes/web.php` ثم افتح `app/Http/Middleware/` |
| كيف يُحقن DI؟ | `apps/api/app/Providers/AppServiceProvider.php` للتجميع المشترك + `apps/api/Modules/*/Providers/*ServiceProvider.php` لملكية كل موديول |
| كيف يُنشر حدث outbox؟ | `Shared/Infrastructure/Outbox/` + `Modules/<Name>/Infrastructure/Outbox/Relay/` |
| كيف يستهلك Stream؟ | `Modules/<Name>/Features/*/Worker/*.php` |
| كيف يحلّ الويب الخطأ؟ | `apps/web/src/api/http.ts` (`ApiError`, `stateFromError`, `unwrap`) |
| كيف تُبني الـ SPA؟ | `apps/web/vite.config.ts` + `apps/web/Dockerfile` |
| كيف أُضيف route؟ | `routes/web.php` داخل `prefix('api/v1')` + controller داخل `Modules/<Name>/Features/<Feature>/Http/` |
| كيف أُضيف شاشة؟ | `apps/web/src/features/...` + تسجيل في `shell/routes.ts` + capability |
| كيف أُضيف event type؟ | حالة على `Shared/Infrastructure/Outbox/OutboxEventType.php` + JSON schema |

---

## 16 · قاموس الرموز

| الرمز | المعنى |
|---|---|
| **Rank** | درجة امتياز الوحدة؛ تعتمد على الأدنى فقط عبر Contracts/Events |
| **Contract** | واجهة PHP صريحة تُصدَّر لباقي الوحدات (`Modules/<Name>/Contracts/`) |
| **Event** | حمولة typed outbox بصيغة CloudEvents 1.0 (`com.cluster.<module>.<type>.v<n>`) |
| **Outbox** | جدول `outbox_events` يُكتب داخل نفس transaction مع business write |
| **Stream** | Redis Stream يستهلكه الـ worker عبر consumer group |
| **Relay** | كود PHP يقرأ `outbox_events` ويكتب Redis Stream (`XADD`) |
| **Worker** | كود PHP يقرأ Stream ويعالج الرسالة |
| **Envelope** | `{ data: … }` للنجاح؛ `application/problem+json` للخطأ |
| **Idempotency-Key** | uuidV7 على الويب، يُسجَّل في `*_idempotency_keys` |
| **If-Match** | قيمة ETag بصيغة `"<lock_version>"` |
| **X-Correlation-ID** | uuidV7 يُمرَّر من العميل إلى كل سجلات الخادم |
| **Problem+json** | RFC 7807 — `{ type, title, status, detail, errors[] }` |
| **Capability** | سلسلة `module.resource.action` تُقيَّم عبر `DecideAccess` |
| **Workspace** | مجموعة routes داخل تبويب واحد في الـ shell |
| **Ghost table** | جدول مذكور دون هجرة حقيقية (مُنظَّف سابقاً) |
| **DLQ** | Dead-Letter Queue: `platform.dlq.v1` يدفع إليها بعد 3 محاولات فاشلة |
| **Fixture-bearer** | session مُصطَنَعة محلياً للاختبار/التطوير، تتجاوز CSRF |
| **WorkerPrincipal** | مبدأ موثوق للعمال الداخليين (مثل scan workers) |

---

## 17 · ملاحظات المراجعة (Audit Notes)

أعيد كتابة هذا الملف بعد مراجعات كشفت النقاط التالية التي كانت **خاطئة أو ناقصة**:

1. **`ProjectWorkRecordReadModels` ليس مجرد middleware أمان** — يكتب إسقاطات Search + Reporting
   داخل HTTP request. كان موصوفاً بشكل مضلِّل كـ "معالجة صامتة". الآن موصوف بدقة (§1 L4، §2،
   §7.1) مع تأكيد أنه **مكمّل، لا بديل**، لمسار الـ Relay غير المتزامن.

2. **`ConsumeSubmittedNotification` يعمل متزامناً فقط في `testing` + `X-Day3-Acceptance: 1`** —
   ليس آلية إنتاج. موصوف بدقة في §1 L4 و §7.1.

3. **N+1 authorization في `SearchAccessibleRecordsHandler`** — كان مفقوداً تماماً. أُضيف
   إلى §7.3 و §8 كـ "ديْن تحسيني" يحتاج معالجة.

4. **Streams names الفعلية** — كانت موصوفة بشكل تقريبي. الآن محددة بالاسم (5 streams
   منتجة + DLQ) في §7.7 و §9.

5. **`worker-loop.sh` الفعلي** — كان موصوفاً بأنه "يدور على relays + consumers" دون تحديد
   الأوامر الأربعة. الآن الأوامر الأربعة مُدرجة بالاسم في §6.

6. **`EnforcePlatformMaintenance`** — كان موصوفاً بأنه "يتجاوز GET/OPTIONS" دون ذكر مسارات
   login و trusted internal workers. الآن مكتمل في §1 L4 و §7.2.

7. **`IdentityCsrfMiddleware` يتجاوز fixture-bearer sessions** — كان مفقوداً. أُضيف في §1 L4.

8. **`RedisOutboxRelay` و `OrganizationPersonOutboxRelay`** — يعملان بـ `MAX_BATCH_SIZE = 100`
   ويحدّثان `published_at` بعد `XADD`. كان موصوفاً بشكل تقريبي. الآن في §7.1.

9. **الـ DBs في Redis** — التصحيح النهائي:
   - `default` connection (REDIS_DB=0): Streams + Sessions (عبر `SESSION_DRIVER=redis`
     و `SESSION_CONNECTION` غير مُعيَّن → يرجع إلى default) + cache key مخصص
     (`platform:maintenance:active`).
   - `cache` connection (REDIS_CACHE_DB=1): الـ cache store العام.
   - المصدر: `apps/api/config/database.php` السطور 156-180، `infra/platform/production/compose.yaml`.

10. **`outbox_events` vs `document_outbox_events`** — تصحيح جوهري:
    - `outbox_events` (مالكه `WorkRecords`): يُكتب من WorkRecords + Tasks + Workflow +
      PlatformSettings عبر `DatabaseTransactionalOutbox`. يُقرأ من WorkRecords/Relay و
      Organization/Relay. **تضارب معماري موثَّق** لأن `TABLE_OWNERS` يُسنده لـ WorkRecords
      فقط بينما الكتابة مشتركة.
    - `document_outbox_events` (مالكه `Documents`): جدول منفصل تماماً، يُكتب مباشرة في
      `Documents` بدون relay. لا يصل إلى Streams.

11. **Search و Reporting لا يستهلكان Streams** — `worker-loop.sh` يُشغّل 4 أوامر فقط:
    `organization:relay-person-events`، `identity:consume-person-events`،
    `work-records:relay-pending`، `notifications:consume-work-record-submitted`. لا يوجد
    `search:*` أو `reporting:*`. Search/Reporting يُحدَّثان **حصراً** عبر المسار 2 (sync
    middleware `ProjectWorkRecordReadModels`). إذا مات `worker-loop`، فلن يصل إلى
    Search/Reporting أي شيء — لكن `outbox_events` ستبقى معلقة في WorkRecords/Organization.

12. **`access_decisions`** — جدول موجود ضمن Authorization (§9) لكن لم أتحقق من كل
    أعمدته. موجود بصيغة `TABLE_OWNERS` لكن لم أُدخل كل حقل.

---

## 18 · تحديثات 2026-07-25 (بعد التحقق من docs/analysis drift)

1. **`AppWorkspace.tsx` 807 سطر (الادعاء القديم)** — **خطأ**: الـ component مُفكَّك إلى:
   - `AppWorkspace.tsx` (1 سطر — re-export)
   - `AppWorkspaceShell.tsx` (271 سطر)
   - `WorkspaceContent.tsx` (271 سطر)
   - `WorkspaceHeader.tsx` (90 سطر)
   - `WorkspaceSidebar.tsx` (64 سطر)
   - `WorkspaceTabs.tsx` (54 سطر)
   المجموع: **750 سطر موزعة على 5 مكونات** في `apps/web/src/app/`. الخطوة التالية المقترحة: تقسيم `AppShell.tsx` (583 سطر) و `AppShell.css` (864 سطر) إلى design system موحَّد.

2. **عدد JSON Schemas (الادعاء القديم: 56)** — **تصحيح**: العدد الفعلي **55** = 54 schema + 1 `_template.schema.json`. قائمة الأحداث في `OutboxEventType` enum تحتوي 56 حالة (52 events + extended variants)، كل حالة تستدعي `schemaPath()` ولكن `_template` لا يُحسب كحدث.
> **حالة الوثيقة:** معاد كتابتها بدقة بعد التحقق. عند أي تغيير في البنية (وحدة جديدة،
> middleware جديد، topology مختلف)، حدّث هذا الملف في نفس الـ PR مع تحديث
> `module-catalog.md` و `ModuleBoundariesTest.php`.