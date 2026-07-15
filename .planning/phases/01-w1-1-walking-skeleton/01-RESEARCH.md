# Phase 01: W1.1 Walking Skeleton - Research

**Researched:** 2026-07-15  
**Domain:** مسار إداري رأسي أولي معزول: React → Laravel Modular Monolith → MySQL Transactional Outbox → Valkey Streams → Notifications  
**Confidence:** MEDIUM

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

### Development and runtime shape
- **D-01:** Use Git and external GitHub for development and work preservation only. GitHub may contain source, lockfiles, and revocable development secrets; it must not contain production secrets.
- **D-02:** Development machines may download packages from the Internet. Production servers must not connect to the Internet, GitHub, public registries, or other external sources at runtime or during deployment.
- **D-03:** The intended production runtime is Docker on one on-premises production server. This is an explicit deviation from the Kubernetes/GitOps target in the accepted architecture; planners must flag and govern the deviation rather than silently treating it as compliant.
- **D-04:** Use a separate internal MinIO instance for attachments and encrypted backups. Do not place the only copy of these assets on the Docker production server.

### Data recovery
- **D-05:** Run one MySQL instance on the Docker production server for the initial deployment. Do not introduce a three-node MySQL cluster now.
- **D-06:** Create encrypted MySQL backups to internal MinIO every 15 minutes; retain them for 30 days. Recovery is manual after a failure.
- **D-07:** The system administrator owns backup monitoring, recovery, and recovery evidence. The existing RPO/RTO and restore-test requirements remain binding.

### Release and supply chain
- **D-08:** At a future release, the system administrator alone approves the release bundle before it enters the internal environment.
- **D-09:** The isolated intake process, image signing, signing-key custody, signature verification, and approved deployment transport are deferred. They MUST be decided and implemented before the Phase 1 permanent deployment/exit gate can close.
- **D-10:** The expected future host procedure is to transfer a reviewed image and Compose manifest only through an approved internal channel, run `docker load`, then run `docker compose up -d`; retain a known previous version for rollback. This is a direction, not an approved replacement for the current GitOps requirement.

### Thin-path isolation proof
- **D-11:** Use two fixed development-only test accounts, one assigned to each of two test facilities. Backend authorization must prevent each account from reading the other's records.
- **D-12:** Use a fixed, direct-submit request form with only title and description. The record remains a published `request` WorkDefinition/WorkRecord, never a separate Requests module.

### the agent's Discretion
- Define the smallest notification recipient and presentation that proves the persisted request emits an Outbox event and an idempotent worker creates an internal notification. Do not add identity, organization-management, or workflow capabilities from later phases.
- Select the technical implementation, test fixtures, API adapters, and local Docker development details consistent with the canonical contracts and module boundaries.

### Deferred Ideas (OUT OF SCOPE)
- Finalize the internal intake workflow, signing implementation, signing-key custody, signature verification, internal registry/mirror, and approved release transport before closing the Phase 1 permanent deployment gate.
- Resolve or formally supersede the accepted Kubernetes/GitOps requirement before treating the Docker-on-one-server runtime as a compliant production platform.
- Full organization, identity, authorization-policy management, workflows, search, reporting, dashboards, and integrations remain in their assigned later phases.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|---|---|---|
| FR-R1-013 | واجهة موحدة عربية/إنجليزية وRTL/LTR. | Shell واحد عربي افتراضياً، و`lang`/`dir` من locale، مع اختبار E2E للاتجاهين. [CITED: docs/adr/009-unified-react-shell.md §Decision; docs/contracts/api/openapi.yaml §/auth/login] |
| SEC-R1-001 | حجب الاتصال الخارجي افتراضياً بـ`NetworkPolicy` deny-all. | يظل دليلاً محجوزاً لبيئة Kubernetes؛ Docker/Compose لا يغلق هذا المتطلب أو بوابة الخروج. [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر; docs/data-security/threat-model.md §4.11] |
| SEC-R1-009 | صفر joins مباشرة بين جداول موديولات الأعمال. | اختبارات حراسة للاستيرادات، DAG، SQL، وملكية الجدول قبل دمج أي Slice. [CITED: docs/architecture/dependency-rules.md §الإنفاذ; docs/engineering/coding-and-module-boundaries.md §اختبارات الحراسة المعمارية] |
| SEC-R1-011 | SBOM موجود وموقّع لكل إصدار. | لا يُعد artifact دائماً أو قابلاً للترقية قبل ربط digest وSBOM وتوقيع متحقق. [CITED: docs/adr/018-air-gapped-supply-chain.md §Decision/§Enforcement; docs/operations/air-gap-supply-chain.md §تدفق artifact] |
| OPS-R1-006 | Rolling Update مع rollback. | يقاس فقط في Staging/Prod عبر revision GitOps معروف؛ Compose handoff ليس بديلاً معتمداً. [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر/§قبول المنصة; docs/operations/runbooks.md §RB-05] |
| OPS-R1-007 | فصل Dev/Test/Staging/Prod شبكياً وملكياً. | أنشئ مصفوفة البيئة والبيانات الاصطناعية قبل أي نشر، وافصل Prod حسابياً وشبكياً وصلاحياً. [CITED: docs/operations/kubernetes-platform.md §البيئات والعناقيد] |
| OPS-R1-008 | ترقية Offline موثقة. | وثّق intake والموافقة والتحقق والنقل قبل الترقيات؛ لا تُغلق البوابة ما دامت D-09 مؤجلة. [CITED: docs/adr/018-air-gapped-supply-chain.md §Consequences; docs/operations/air-gap-supply-chain.md §المبدأ/§تدفق artifact] |
| OPS-R1-011 | كل الصور في Registry داخلي مركزي. | لا تقبل image reference خارج سجل داخلي approved، ولا `latest`. [CITED: docs/operations/air-gap-supply-chain.md §الضوابط; docs/operations/kubernetes-platform.md §ضوابط النشر] |
| OPS-R1-012 | Composer وnpm عبر مرايا داخلية فقط. | لا تثبّت product dependencies قبل اعتماد mirror/intake/lockfile؛ التطوير المتصل لا يثبت الامتثال التشغيلي. [CITED: docs/adr/018-air-gapped-supply-chain.md §Decision; docs/operations/air-gap-supply-chain.md §المبدأ/§الضوابط] |
</phase_requirements>

## Project Constraints (from AGENTS.md)

- التزم بـLaravel Modular Monolith، حدود موديولات صارمة، vertical slices وlight DDD/CQRS، وقاعدة MySQL تشغيلية واحدة؛ لا cross-module joins أو تسريب ORM/Infrastructure. [CITED: AGENTS.md §Project/Constraints]
- استخدم React + TypeScript واحداً لكل الأدوار، العربية افتراضياً، والإنجليزية وRTL/LTR؛ لا واجهات إدارة منفصلة. [CITED: AGENTS.md §Project/Constraints]
- طبّق RBAC+ABAC خلفياً؛ لا يجعل إخفاء React قرار وصول. [CITED: AGENTS.md §Project/Constraints]
- احفظ تغيير الأعمال وOutbox في transaction واحدة، واجعل المستهلكين idempotent. [CITED: AGENTS.md §Project/Constraints]
- لا إنترنت أو CDN أو SaaS أو مصادر عامة في build/runtime الإنتاجي؛ استخدم artifacts وصوراً وخدمات داخلية. [CITED: AGENTS.md §Project/Constraints]
- حظر النشر عند فشل الأمن؛ يلزم SBOM وصور موقعة، وRPO ≤15 دقيقة وRTO ≤ ساعتين. [CITED: AGENTS.md §Project/Constraints]
- المنتج غير موجود بعد: لا يوجد root Composer/npm أو product source أو product tests أو container manifests؛ لا تصف توقعات الوثائق كأنها منفذة. [CITED: AGENTS.md §Technology Stack/Current Repository Status]
- أبقِ توثيق المستودع أخضر عند تغييره عبر `./scripts/validate-docs.sh`؛ هذه المرحلة لا تعدّل `docs/`. [CITED: AGENTS.md §Conventions/Current Implementation Scope]

## Summary

الحد الأدنى الصحيح ليس CRUD باسم `requests` ولا job يكتب إشعاراً مباشرة. إنه مسار واحد: حساب fixture في منشأة أ يسجل الدخول إلى Shell موحد، ينشئ `WorkRecord` من نسخة `WorkDefinition` منشورة رمزها `request`، ثم تحفظ المعاملة Envelope وحدث Outbox معاً؛ وبعد الـcommit يمر الحدث عبر Valkey Streams إلى مستهلك Notifications يكتب Inbox ثم إشعاراً واحداً قابلًا للعرض. يعاد رفض قراءة سجل منشأة ب على Laravel نفسه، لا في React. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Thin-path isolation proof; docs/architecture/overview.md §6/§9/§10; docs/contracts/module-contracts.md §Event Rules]

المستودع الحالي documentation-only: لذلك على الخطة أن تبدأ بإنشاء product baseline واختبارات الحراسة وCI product، لا أن تفترض وجود Laravel أو React أو Docker/Kubernetes/GitOps جاهز. كما أن الإصدارات والحزم لم تُنتقَ عمداً؛ اختيارها وترخيصها وإدخالها في mirrors الداخلية عمل بوابة، لا تفصيل تقني يجوز تخمينه. [VERIFIED: repository manifests] [CITED: AGENTS.md §Technology Stack/Current Repository Status; docs/operations/air-gap-supply-chain.md §الضوابط]

**التوصية الأساسية:** نفّذ Skeleton تطويري محلياً/في Staging بواجهة React واحدة وLaravel ووحدات fixtures ضيقة وMySQL+Valkey؛ لكن خطّط بوابة مستقلة مانعة لإغلاق النشر الدائم حتى يُعتمد أو يُستبدل رسمياً Kubernetes/GitOps وintake/signing/key custody/mirrors/admission. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Release and supply chain/§Deferred Ideas; docs/adr/018-air-gapped-supply-chain.md §Decision; docs/adr/019-kubernetes-resilience-and-recovery.md §Decision]

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|---|---|---|---|
| لغة/اتجاه واجهة موحدة | Browser / Client | API / Backend | React shell يضبط التجربة العربية/الإنجليزية وRTL/LTR؛ لا ينقل صلاحية إلى العميل. [CITED: docs/adr/009-unified-react-shell.md §Decision/§Security] |
| تسجيل دخول fixture | API / Backend | Browser / Client | جلسة وprincipal يملكه Identity؛ الواجهة تعرض النتيجة فقط. [CITED: docs/architecture/module-catalog.md §Identity; docs/contracts/api/openapi.yaml §/auth/login] |
| نشر fixture من نوع `request` | API / Backend | Database / Storage | WorkDefinitions يملك النسخة المنشورة، ولا ينشئ النوع موديول `Requests`. [CITED: docs/architecture/module-catalog.md §WorkDefinitions/§WorkRecords; docs/architecture/dependency-rules.md §قواعد خاصة بالحدود] |
| حفظ وإتاحة WorkRecord | API / Backend | Database / Storage | WorkRecords يملك Envelope والـpayload و`RecordFacts`، ويطبق العزل قبل serialization. [CITED: docs/architecture/module-catalog.md §WorkRecords; docs/architecture/overview.md §8/§9] |
| Outbox والrelay | API / Backend | Database / Storage | المصدر يحفظ Outbox في معاملة MySQL نفسها؛ relay يعمل بعد commit خارج معاملة المصدر. [CITED: docs/architecture/dependency-rules.md §ملكية المعاملة والتزامن; docs/contracts/module-contracts.md §Event Rules] |
| إظهار إشعار داخلي | API / Backend | Browser / Client | Notifications مستهلك مشتق يملك Inbox والإشعار؛ الواجهة تقرأ صندوق المستخدم فقط. [CITED: docs/architecture/module-catalog.md §Notifications] |
| egress deny-all وpromotion/rollback | CDN / Static | API / Backend | هي سياسة منصة Kubernetes/GitOps، وليست مسؤولية React أو Laravel ولا يمكن اعتماد Docker كدليل مكافئ دون قرار حوكمة. [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-03/D-10] |

## Standard Stack

### Core

| Technology | Version | Purpose | Why Standard |
|---|---:|---|---|
| Laravel Modular Monolith | غير مختار | HTTP/API، معاملات الكتابة، وحدات الأعمال. | قرار معماري ملزم، مع فصل module ownership وCQRS خفيف. [CITED: docs/architecture/overview.md §4] |
| React + TypeScript unified shell | غير مختار | واجهة عربية افتراضياً ووحيدة لكل الأدوار. | ADR-009 يرفض التطبيقات المنفصلة ويمنع اعتماد الأمن على العميل. [CITED: docs/adr/009-unified-react-shell.md §Decision/§Security] |
| MySQL | غير مختار | مصدر الحقيقة وOutbox atomic. | العقود تفرض حفظ mutation وOutbox معاً في MySQL transaction. [CITED: docs/architecture/dependency-rules.md §ملكية المعاملة والتزامن] |
| Valkey-compatible Streams | غير مختار | نقل event بعد commit بمجموعات مستهلكين وack. | AsyncAPI وعقد الموديولات يحددان Streams وconsumer groups وDLQ. [CITED: docs/contracts/events/asyncapi.yaml §servers/§operations; docs/contracts/module-contracts.md §Event Rules] |

### Supporting

| Capability | Version | Purpose | When to Use |
|---|---:|---|---|
| Internal OCI registry + Composer/npm mirrors | غير مختار | مصدر داخلي وحيد للصور والحزم. | يجب اعتماده قبل بناء أو نشر معزول. [CITED: docs/adr/018-air-gapped-supply-chain.md §Decision; docs/operations/air-gap-supply-chain.md §المبدأ] |
| GitOps controller + admission verification | غير مختار | promotion/rollback من Git revision والتحقق من التوقيع/SBOM. | حصراً في Staging/Prod بعد قرار المنصة. [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر] |
| Separate MinIO/S3-compatible backup store | غير مختار | نسخ مشفرة منفصلة عن host/Kubernetes. | وفق D-04 وD-06؛ لا يحمل Docker host النسخة الوحيدة. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-04/D-06; docs/operations/ha-dr-backup.md §النسخ والاحتفاظ] |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|---|---|---|
| Kubernetes + GitOps المعتمدان | Docker/Compose على خادم واحد | هذا قرار مستخدم صريح لكنه deviation غير معتمد؛ يظل مفيداً لمسار تطوير/عرض ولا يحقق بمفرده NetworkPolicy أو GitOps أو HA/فشل عقدة. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-03/D-10; docs/adr/019-kubernetes-resilience-and-recovery.md §Alternatives; docs/operations/kubernetes-platform.md §ضوابط النشر] |
| Valkey Streams + Inbox | كتابة Notifications داخل request transaction أو queue غير متعاقد عليها | يكسر التسليم at-least-once أو يربط نجاح الحقيقة بخدمة خارج المعاملة. [CITED: docs/architecture/overview.md §10.2/§10.3; docs/contracts/module-contracts.md §Event Rules] |
| `WorkDefinition(request)` + `WorkRecord` | `Requests` module/table/event | ممنوع صراحة ويكسر ملكية الموديولات canonical. [CITED: docs/architecture/overview.md §6; docs/architecture/dependency-rules.md §قواعد خاصة بالحدود] |

**Installation:** لا تكتب أمر `composer` أو `npm install` في خطة التنفيذ قبل إغلاق بوابة intake/mirror/lockfile وتحديد الإصدارات المعتمدة. [CITED: docs/operations/air-gap-supply-chain.md §الضوابط; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09]

**Version verification:** لا توجد product manifests أو إصدارات Laravel/React/Valkey مختارة في المستودع؛ لا يجوز تحويل إصدارات التدريب إلى قرار. [VERIFIED: repository manifests] [CITED: AGENTS.md §Technology Stack/Frameworks]

## Package Legitimacy Audit

لا توجد حزم خارجية موصى بها بالاسم في هذا البحث؛ لذلك لا يوجد package install مسموح به أو version يمكن تدقيقه الآن. هذا متعمد لأن mirrors، intake، التوقيع، وحيازة المفاتيح مؤجلة في D-09. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09; docs/operations/air-gap-supply-chain.md §الضوابط]

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---|---|---|---|---|---|---|
| لا شيء محدد | — | — | — | — | N/A | لا تثبيت قبل `checkpoint:human-verify` لاعتماد intake والمرايا، ثم Package Legitimacy Gate لكل package فعلي. [CITED: docs/operations/air-gap-supply-chain.md §تدفق artifact/§الضوابط] |

**Packages removed due to [SLOP] verdict:** لا شيء؛ لم يُقترح package. [VERIFIED: research scope]  
**Packages flagged as suspicious [SUS]:** لا شيء؛ لم يُقترح package. [VERIFIED: research scope]

## Architecture Patterns

### System Architecture Diagram

```text
[React Arabic-first Shell]
        | POST /auth/login, /work-records, correlation + idempotency
        v
[Laravel HTTP adapter]
        | backend fixture identity + narrow DecideAccess(scope=fixed facility)
        v
[WorkDefinitions: published request] --> [WorkRecords: direct submit]
                                              |
                            one MySQL transaction: record + Outbox row
                                              v
                                         [MySQL Outbox]
                                              |
                                  post-commit relay, at-least-once
                                              v
                       [Valkey Stream / consumer group / explicit ack]
                                              |
                                Inbox(event_id) before side effect
                                              v
                      [Notifications: one submitter notification]
                                              |
                                              v
                              [React notification indicator/list]

Facility A fixture --- GET record A ---> allow
Facility B fixture --- GET record A ---> deny (Laravel; no client trust)
```

المستلم الأدنى الموصى به هو منشئ/مرسل السجل نفسه؛ يثبت أن `WorkRecordSubmitted` صار أثراً مشتقاً persisted، من دون إدخال resolver للمدير أو workflow أو إدارة هويات كاملة. [CITED: docs/architecture/module-catalog.md §Notifications/§WorkRecords; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §the agent's Discretion]

### Recommended Project Structure

```text
<monorepo>/
├── <React shell>/                 # تطبيق واحد، locale/dir/routes
├── <Laravel application>/
│   ├── Modules/Identity/          # fixture login فقط في W1.1
│   ├── Modules/Organization/      # facility fixtures فقط في W1.1
│   ├── Modules/Authorization/     # contract + fixture isolation decision
│   ├── Modules/WorkDefinitions/   # published request fixture
│   ├── Modules/WorkRecords/       # direct-submit slice + Outbox
│   ├── Modules/Notifications/     # Inbox + projection/notification
│   └── Shared/                    # UUID/Clock/transaction/Outbox primitives فقط
└── <deployment definitions>/      # Dev local; permanent target remains gated
```

هذا شكل منطقي مطلوب للملكية وليس prescription لمسميات root folders؛ كل موديول يحتفظ بـ`Domain/Contracts/Events/Infrastructure/Features/<BusinessVerb>/Tests`، و`Shared` تقني محايد فقط. [CITED: docs/engineering/vertical-slices.md §القاعدة; docs/architecture/module-catalog.md §قواعد الإضافة والتغيير]

### Pattern 1: Slice كتابة مباشر يملك المعاملة
**What:** أنشئ `SubmitWorkRecord` داخل `WorkRecords`؛ يستدعي فقط contracts منشورة للتحقق من نسخة `request` واتخاذ قرار fixture، ثم يكتب record وOutbox atomically. [CITED: docs/engineering/vertical-slices.md §قواعد الـSlice; docs/architecture/dependency-rules.md §ملكية المعاملة والتزامن]

**When to use:** لكل direct-submit في W1.1، لا لبدء Workflow أو إدارة تعريفات من UI. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-12/§Deferred Ideas]

```text
// Pseudocode pattern — source: docs/architecture/dependency-rules.md §ملكية المعاملة والتزامن
transaction.begin();
decision = authorization.decide_access(actor, "work_record.submit", record_facts);
require(decision.allow);
require(work_definitions.published_version_is("request"));
record = work_records.submit(title, description, owner_facility_id);
outbox.append(cloud_event("com.cluster.workrecord.submitted.v1", record));
transaction.commit();
```

### Pattern 2: relay ثم Inbox-before-effect
**What:** relay يرسل Outbox بعد commit؛ Notifications يثبت `event_id` في Inbox قبل إنشاء notification، ثم يقر delivery المكرر بلا أثر ثانٍ ويرسل exhausted failures إلى DLQ. [CITED: docs/contracts/module-contracts.md §Event Rules; docs/contracts/events/asyncapi.yaml §dead-letter]

```text
// Pseudocode pattern — source: docs/contracts/module-contracts.md §Event Rules
event = stream.receive();
if (notification_inbox.insert_if_absent(event.id)) {
  notifications.create(recipient_id=event.data.record.created_by_user_id, source_ref=event.subject);
}
stream.ack(event.id); // duplicates are acknowledged without another notification
```

### Pattern 3: fixture-only isolation through backend contract
**What:** seed two fixed facilities and two fixture accounts; `WorkRecords` builds the minimum `RecordFacts` and asks a narrow `Authorization` contract to allow only matching facility scope. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-11; docs/architecture/overview.md §9; docs/contracts/schemas/record-facts.schema.json]

**When to use:** فقط لإثبات isolation في W1.1؛ لا تبنِ role management أو delegation أو policy administration المخصصة لـW1.3. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Deferred Ideas; docs/plans/release-1-platform.md §W1.3]

### Implementation Sequencing

1. **Gate record first:** سجّل قرار CCB يصف Docker/one-MySQL deviation وحدودها، ثم افصل “developer/staging skeleton evidence” عن “permanent release closure”. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-03/D-05/D-09/D-10; docs/plans/implementation-roadmap.md §القرارات المؤجلة وبوابات الحسم]
2. **Scaffold and guard:** أنشئ monorepo product baseline، templates للـ19 موديول، test runners، contract/schema checks، وboundary checks قبل business features. [CITED: docs/plans/release-1-platform.md §W1.1/REQ-R1-W1.1-003; docs/architecture/overview.md §13]
3. **Fixture foundations only:** أنشئ fixture-only Organization/Identity/Authorization contracts ومستخدمين ومنشأتين؛ لا CRUD أو lifecycle كامل. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Phase Boundary/§D-11; docs/architecture/module-catalog.md §Organization/§Identity/§Authorization]
4. **Write path:** publish fixture `request` through WorkDefinitions ثم direct-submit WorkRecord مع envelope/version/classification/lock version وOutbox في المعاملة نفسها. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-12; docs/contracts/schemas/work-record.schema.json; docs/contracts/schemas/work-record-submitted.schema.json]
5. **Async proof:** أضف relay، Streams، Inbox، notification واحد، crash/restart/replay/DLQ tests. [CITED: docs/contracts/module-contracts.md §Event Rules; docs/contracts/events/asyncapi.yaml §dead-letter]
6. **UX and E2E:** أوصل form title/description، login، list/indicator للإشعار، العربية/الإنجليزية والاتجاهين، ثم اختبر Allow/Deny من الحسابين. [CITED: docs/adr/009-unified-react-shell.md §Decision; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-11/D-12]
7. **Supply-chain evidence:** لا تنشئ production promotion قبل تحقق internal registry/mirrors, digest, SBOM, signature, GitOps admission وNetworkPolicy. عند غيابها تنتهي الخطة إلى `checkpoint:human-verify` لا إلى ادعاء success. [CITED: docs/operations/air-gap-supply-chain.md §تدفق artifact/§دليل القبول; docs/operations/kubernetes-platform.md §ضوابط النشر]

### Anti-Patterns to Avoid
- **Compose deployment يسمى GitOps:** غير مقبول ما لم يُعتمد supersession؛ لا يتجاوز D-10 كونه اتجاهاً. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-10; docs/operations/kubernetes-platform.md §ضوابط النشر]
- **`requests` table/module/event:** حوّله إلى `WorkDefinition(request)` و`WorkRecord*` فقط. [CITED: docs/architecture/dependency-rules.md §قواعد خاصة بالحدود]
- **قرار facility في React أو filter UI:** Laravel يستدعي authorization قبل أي read/serialization. [CITED: docs/architecture/overview.md §9; docs/adr/009-unified-react-shell.md §Security]
- **Notification قبل commit أو دون Inbox:** أبقه derived post-commit وidempotent. [CITED: docs/architecture/overview.md §10.2/§10.3; docs/contracts/module-contracts.md §Event Rules]
- **اعتماد framework/package/version من الذاكرة أو public registry:** مرره عبر intake داخلي وmirror وlockfile وlegitimacy check. [CITED: docs/operations/air-gap-supply-chain.md §تدفق artifact/§الضوابط]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---|---|---|---|
| تنسيق async بين الحقيقة والإشعار | transaction موزعة أو notification داخل Controller | Transactional Outbox + relay + Valkey Streams + consumer Inbox. [CITED: docs/contracts/module-contracts.md §Event Rules] | يفرض atomic source mutation، at-least-once، dedupe وDLQ. [CITED: docs/contracts/module-contracts.md §Event Rules] |
| صلاحية العزل | if شرط في React أو permission داخل WorkRecords بلا contract | `DecideAccess`/`RecordFacts` contract ضيق للـfixtures. [CITED: docs/architecture/module-catalog.md §Authorization; docs/architecture/overview.md §9] | يبقي Authorization بلا قراءة جداول العمل ويجعل Laravel مرجع القرار. [CITED: docs/architecture/module-catalog.md §Authorization] |
| تعريف request | schema/aggregate مستقل | WorkDefinitions published version + WorkRecords envelope. [CITED: docs/architecture/overview.md §6] | يمنع كسر الحدود ويُثبت نسخة النوع على السجل. [CITED: docs/architecture/overview.md §8] |
| rollback | حذف state أو image pull خارجي | Git revision معروف وصورة داخلية موقعة سابقة. [CITED: docs/adr/018-air-gapped-supply-chain.md §Rollback; docs/operations/kubernetes-platform.md §ضوابط النشر] | يربط rollback بـartifact موثق ويحمي state. [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر] |
| توقيع/SBOM/intake محلي مرتجل | scripts مفاتيحها في Git أو image | عملية supply-chain داخلية approved وحيازة مفاتيح مفصولة. [CITED: docs/operations/air-gap-supply-chain.md §تدفق artifact/§الضوابط] | D-09 يصرح أن ذلك قرار تنفيذي مؤجل، وليس قابلاً للتخمين. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09] |

**Key insight:** البساطة هنا تكون في عدد حالات الاستخدام والـfixtures، لا في تجاوز الموديول أو المعاملة أو evidence chain؛ المسار الرقيق يجب أن يحمل الحدود التي ستتوسع عليها R1 لاحقاً. [CITED: docs/engineering/vertical-slices.md §معيار الاكتمال; docs/plans/release-1-platform.md §W1.1]

## Common Pitfalls

### Pitfall 1: إغلاق بوابة دائمة بدليل Docker محلي
**What goes wrong:** يعدّ `docker compose up` أو `docker load` دليلاً على GitOps/NetworkPolicy/rolling rollback رغم أن المقصود target مختلف. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-03/D-10; docs/operations/kubernetes-platform.md §ضوابط النشر]

**How to avoid:** ضع checkpoint CCB يحسم supersession أو platform target، ولا تعلن SEC-R1-001 أو OPS-R1-006 مكتملة قبل evidence Kubernetes/GitOps المعتمد. [CITED: docs/operations/kubernetes-platform.md §قبول المنصة; docs/plans/implementation-roadmap.md §القرارات المؤجلة وبوابات الحسم]

### Pitfall 2: fixture isolation تتحول إلى authorization system مبكر
**What goes wrong:** توسع W1.1 إلى role management أو organization CRUD أو delegation، فتتداخل مع W1.2/W1.3. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Phase Boundary/§Deferred Ideas]

**How to avoid:** استخدم account/facility fixtures ثابتة وقرار backend ضيق، مع TODO/contract واضح لتوسعة W1.3 فقط. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-11; docs/plans/release-1-platform.md §W1.3]

### Pitfall 3: Outbox شكلي لا يختبر crash/replay
**What goes wrong:** يمر happy path لكن worker restart يخلق إشعارين أو يفقد event. [CITED: docs/contracts/module-contracts.md §Event Rules; docs/plans/release-1-platform.md §W1.1]

**How to avoid:** اختبر rollback transaction، relay retry، duplicate CloudEvent id، crash بعد Inbox وقبل ack، وDLQ للرسالة exhausted. [CITED: docs/contracts/module-contracts.md §Event Rules; docs/contracts/events/asyncapi.yaml §dead-letter]

### Pitfall 4: كسر الملكية لتسريع المسار
**What goes wrong:** WorkRecords يقرأ/يكتب notification tables أو Notifications يستعلم work-record tables. [CITED: docs/engineering/coding-and-module-boundaries.md §ملكية البيانات/§قاعدة الاستعلام]

**How to avoid:** event يحمل source reference وaccess context/classification؛ Notifications يحفظ مشتقه فقط. [CITED: docs/contracts/schemas/work-record-submitted.schema.json; docs/architecture/module-catalog.md §Notifications]

### Pitfall 5: الاعتماديات public أو `latest`
**What goes wrong:** build متصل يعمل على جهاز المطور ثم يفشل داخل air gap أو لا يمكن تكراره. [CITED: docs/adr/018-air-gapped-supply-chain.md §Decision/§Consequences; docs/operations/air-gap-supply-chain.md §الضوابط]

**How to avoid:** gate package/image intake ومرايا داخلية وdigest/lockfile قبل acceptance؛ لا تُدرج public URL في runtime artifacts. [CITED: docs/operations/air-gap-supply-chain.md §الضوابط; docs/data-security/threat-model.md §4.10/§6]

## Code Examples

### Backend authorization at read boundary

```text
// Pseudocode pattern — source: docs/architecture/overview.md §9
facts = work_records.resolve_record_facts(record_id);
decision = authorization.decide_access(actor, "work_record.read", facts);
if (decision.deny) return forbidden();
return serialize_with_field_decisions(record, decision.field_decisions);
```

هذا يفرض أن RecordFacts تتضمن facility owner وclassification وactor context اللازمة للـfixture proof. [CITED: docs/contracts/schemas/record-facts.schema.json; docs/contracts/schemas/access-decision.schema.json]

### Event envelope assertions

```text
// Test assertions — source: docs/contracts/schemas/event-envelope.schema.json
assert event.specversion == "1.0";
assert event.type == "com.cluster.workrecord.submitted.v1";
assert event.id is lowercase_uuidv7;
assert event.correlationid == request.correlation_id;
assert event.data includes record, access_context, classification;
```

الـAsyncAPI يربط هذا النوع بقناة `platform.work-record.submitted.v1`. [CITED: docs/contracts/events/asyncapi.yaml §work-record-submitted/§WorkRecordSubmitted]

## State of the Art

| Old/Rejected Approach | Current Accepted Approach | When Changed | Impact |
|---|---|---|---|
| Service split مبكر أو Event Sourcing | Laravel Modular Monolith مع light CQRS وTransactional Outbox. [CITED: docs/architecture/overview.md §4] | القرار المعماري الحالي، 2026-07-15. [CITED: docs/architecture/overview.md front matter] | لا تبنِ bus أو services مستقلة لمسار W1.1. [CITED: docs/architecture/overview.md §4] |
| React apps منفصلة للإدارة والمستخدم | React+TypeScript shell واحد مع routes/contracts. [CITED: docs/adr/009-unified-react-shell.md §Decision] | ADR-009، 2026-07-15. [CITED: docs/adr/009-unified-react-shell.md front matter] | صفحة fixture/admin إن وجدت تبقى داخل التطبيق نفسه. [CITED: docs/adr/009-unified-react-shell.md §Scope] |
| deploy يدوي أو image tag mutable | GitOps revision + internal signed digest/SBOM. [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر; docs/operations/air-gap-supply-chain.md §الضوابط] | target accepted/current؛ Docker alternative لم يُعتمد كبديل. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-03/D-10] | البوابة الدائمة تبقى مفتوحة حتى قرار الحوكمة. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09/§Deferred Ideas] |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|---|---|---|
| A1 | تصنيف ASVS أدناه mapping تخطيطي عام، لا إثبات امتثال ASVS رسمي؛ يلزم مسؤول الأمن تأكيد إصدار ASVS/ضوابطه قبل اعتماده كدليل. [ASSUMED] | Security Domain | ادعاء امتثال غير موثق أو نقص ضابط. |

## Open Questions

1. **هل يُعتمد Docker single-host رسمياً كاستثناء محدود أم يُستبدل بالهدف Kubernetes/GitOps قبل التنفيذ الدائم؟**
   - ما نعرفه: D-03 يطلب Docker، بينما ADR-019 وعمليات Kubernetes يرفضان single node ويقصران production writes على GitOps. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-03; docs/adr/019-kubernetes-resilience-and-recovery.md §Decision/§Alternatives]
   - ما هو غير واضح: الجهة صاحبة supersession، المدة، وضوابط التعويض. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Deferred Ideas]
   - التوصية: `checkpoint:human-verify`/CCB قبل أي Task يعد production permanent؛ لا تفتح exit gate بدليل Compose. [CITED: docs/plans/implementation-roadmap.md §القرارات المؤجلة وبوابات الحسم]

2. **ما implementation المعتمد لـintake، registry/mirrors، SBOM/signature، key custody، verification وadmission؟**
   - ما نعرفه: الأدوار والضوابط مطلوبة، لكنها ليست أسماء خدمات مثبتة، وD-09 تؤجل القرار. [CITED: docs/operations/air-gap-supply-chain.md §المبدأ; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09]
   - ما هو غير واضح: الأدوات والإصدارات والمسؤوليات العملية. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09]
   - التوصية: يختار المالك القائمة المصادق عليها ثم تنفذ Package Legitimacy Audit وregistry verification لكل dependency. [CITED: docs/operations/air-gap-supply-chain.md §تدفق artifact]

3. **كيف يثبت single MySQL + backup MinIO كل من RPO/RTO؟**
   - ما نعرفه: D-06 يفرض backup كل 15 دقيقة و30 يوماً وmanual recovery؛ الأهداف الملزمة لا تزال RPO≤15m/RTO≤2h. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-05/D-06/D-07; docs/operations/ha-dr-backup.md §الأهداف الملزمة]
   - ما هو غير واضح: runbook، تشفير/مفاتيح، PITR، وقياس restore. [CITED: docs/operations/ha-dr-backup.md §النسخ والاحتفاظ/§تسلسل التعافي]
   - التوصية: اجعل أول restore drill موثق checkpoint قبل ادعاء compliance؛ لا يكفي نجاح backup job. [CITED: docs/operations/ha-dr-backup.md §اختبار ربع سنوي]

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|---|---|---|---|---|
| Docker / Docker Compose | dev thin path وD-10 direction | ✓ | Docker 29.6.1 / Compose 5.2.0 | لا يعد بديلاً دائماً عن GitOps. [VERIFIED: command -v] [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-10] |
| kubectl | تحقق read-only للمنصة المعتمدة | ✓ | لم تُرجع الأداة version | لا توجد منصة Kubernetes مثبتة مثبتة بالدليل. [VERIFIED: command -v] |
| Helm / Flux / Argo CD | GitOps/rolling rollback | ✗ | — | blocking for permanent accepted target until platform decision. [VERIFIED: command -v] [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر] |
| MySQL client/server | local DB verification | ✗ | — | containerized dev service فقط بعد artifact intake؛ لا يثبت production topology. [VERIFIED: command -v] [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-05] |
| MinIO CLI/server | backup/object store validation | ✗ | — | blocking for D-04/D-06 evidence. [VERIFIED: command -v] [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-04/D-06] |
| cosign / syft / trivy | signature/SBOM/vulnerability evidence | ✗ | — | blocking until approved internal equivalents and key custody are selected. [VERIFIED: command -v] [CITED: docs/adr/018-air-gapped-supply-chain.md §Security/§Enforcement] |
| PHP / Composer / Node / npm | product scaffold once approved | ✓ | PHP 8.5.7 / Composer 2.10.1 / Node 22.22.2 / npm 11.18.0 | host versions are not product pins. [VERIFIED: command -v] [CITED: AGENTS.md §Technology Stack/Runtime] |

**Missing dependencies with no fallback:** GitOps controller, internal registry/mirrors, signing/SBOM tooling and custody, validated MinIO service, and an approved permanent deployment platform. [VERIFIED: command -v] [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09/§Deferred Ideas]

**Missing dependencies with fallback:** local MySQL/Valkey may be containerised for development only after approved images are available; that fallback cannot close production gates. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Development and runtime shape; docs/operations/kubernetes-platform.md §ضوابط النشر]

## Validation Architecture

### Test Framework
| Property | Value |
|---|---|
| Framework | لا يوجد product framework مثبت؛ مستند R1 يتوقع `Pest` وPHPStan/Pint، وfrontend checks `eslint`/`vitest`/`tsc` بعد اعتماد packages. [VERIFIED: repository manifests] [CITED: docs/plans/release-1-platform.md §W1.1/بوابة الخروج] |
| Config file | none — Wave 0. [VERIFIED: repository manifests] |
| Quick run command | غير متاح حتى scaffold/lockfile؛ Wave 0 يحدد commands المعتمدة. [VERIFIED: repository manifests] |
| Full suite command | غير متاح حتى scaffold/lockfile؛ CI الحالي docs-only. [VERIFIED: repository manifests] [CITED: .gitlab-ci.yml] |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|---|---|---|---|---|
| FR-R1-013 | Arabic/English + RTL/LTR direct submit path | UI E2E | `TBD: approved frontend E2E runner` | ❌ Wave 0 [CITED: docs/adr/009-unified-react-shell.md §Decision] |
| SEC-R1-001 | egress denied by default and allowed internal paths work | Kubernetes integration | `TBD: approved cluster policy test` | ❌ Wave 0 [CITED: docs/data-security/threat-model.md §4.11] |
| SEC-R1-009 | no forbidden imports/joins/FKs/write-side derived access | architecture/static + integration | `TBD: approved boundary guard` | ❌ Wave 0 [CITED: docs/engineering/coding-and-module-boundaries.md §اختبارات الحراسة المعمارية] |
| SEC-R1-011 | artifact has verified SBOM/signature | supply-chain integration | `TBD: approved internal verification command` | ❌ Wave 0 [CITED: docs/operations/air-gap-supply-chain.md §دليل القبول لكل إصدار] |
| OPS-R1-006 | staged rolling update and GitOps rollback | staging operational | `TBD: approved GitOps controller verification` | ❌ Wave 0 [CITED: docs/operations/kubernetes-platform.md §قبول المنصة] |
| OPS-R1-007 | environment ownership/network separation | platform configuration | `TBD: environment separation evidence check` | ❌ Wave 0 [CITED: docs/operations/kubernetes-platform.md §البيئات والعناقيد] |
| OPS-R1-008 | offline upgrade provenance/intake record | supply-chain audit | `TBD: approved intake verification` | ❌ Wave 0 [CITED: docs/adr/018-air-gapped-supply-chain.md §Consequences] |
| OPS-R1-011 | internal-registry-only image references | build/deploy policy | `TBD: approved image-source policy check` | ❌ Wave 0 [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر] |
| OPS-R1-012 | Composer/npm resolve through internal mirrors only | isolated build | `TBD: approved mirror isolation build` | ❌ Wave 0 [CITED: docs/operations/air-gap-supply-chain.md §الضوابط] |

### Sampling Rate
- **Per task commit:** product unit/feature/contract/boundary checks selected by Wave 0, plus `./scripts/validate-docs.sh` if governed docs change. [CITED: docs/engineering/testing-strategy.md §طبقات الاختبار; AGENTS.md §Conventions/Documentation Workflow]
- **Per wave merge:** full product suite, architecture checks, E2E thin path, and existing docs CI. [CITED: docs/engineering/testing-strategy.md §طبقات الاختبار; docs/plans/implementation-roadmap.md §سياسة الاختبارات]
- **Phase gate:** include staging E2E, worker replay proof, air-gap/signature/SBOM/registry evidence, and governed GitOps evidence; otherwise mark gate open. [CITED: docs/plans/release-1-platform.md §W1.1/اختبارات القبول; docs/operations/air-gap-supply-chain.md §دليل القبول لكل إصدار]

### Wave 0 Gaps
- [ ] Approved product dependencies, exact versions, licenses, internal mirror provenance, and lockfiles — prerequisite to all product runners. [CITED: docs/operations/air-gap-supply-chain.md §تدفق artifact/§الضوابط]
- [ ] Backend test/static-analysis configuration and module boundary/SQL ownership guard. [CITED: docs/plans/release-1-platform.md §W1.1/بوابة الخروج; docs/architecture/dependency-rules.md §الإنفاذ]
- [ ] Frontend type/lint/component/E2E configuration with locale-direction assertions. [CITED: docs/adr/009-unified-react-shell.md §Enforcement]
- [ ] Contract/schema compatibility checks for OpenAPI, AsyncAPI, CloudEvent and JSON Schemas. [CITED: docs/architecture/overview.md §13; docs/contracts/module-contracts.md §Compatibility]
- [ ] Internal CI stages for test/build/verify/publish; present CI has only docs validate/build. [VERIFIED: .gitlab-ci.yml] [CITED: AGENTS.md §Technology Stack/Frameworks]

## Security Domain

### Applicable ASVS Categories

> هذا mapping مساعد للتخطيط فقط، وليس إثبات امتثال ASVS رسمي. [ASSUMED]

| ASVS Category | Applies | Standard Control |
|---|---|---|
| V1 Architecture | yes | module DAG، table ownership، Outbox/Inbox، وboundary guard. [CITED: docs/architecture/dependency-rules.md §الإنفاذ] |
| V2 Authentication | yes, fixture-only | login/session contract وfixture accounts؛ lifecycle الكامل مؤجل لـW1.2. [CITED: docs/contracts/api/openapi.yaml §/auth/login; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §Deferred Ideas] |
| V3 Session Management | limited | لا توسعة سياسة session في W1.1؛ لا تسرب tokens إلى logs أو Git. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-01; docs/data-security/threat-model.md §4.1] |
| V4 Access Control | yes | `RecordFacts` → backend `DecideAccess` → deny cross-facility read; React ليس boundary. [CITED: docs/architecture/overview.md §9; docs/contracts/schemas/access-decision.schema.json] |
| V5 Input Validation | yes | تحقق title/description وcontracts/schema في Handler قبل mutation؛ لا raw SQL cross-owner. [CITED: docs/engineering/vertical-slices.md §قواعد الـSlice; docs/data-security/threat-model.md §4.3] |
| V6 Cryptography | gate-dependent | لا hand-roll؛ key custody/signing/backups هي D-09/D-06 gates قبل permanent exit. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-06/D-09] |

### Known Threat Patterns for this Phase

| Pattern | STRIDE | Standard Mitigation |
|---|---|---|
| IDOR عبر record ID من منشأة أخرى | Information disclosure / Elevation | Laravel builds facts and denies before serialization; E2E proves A cannot read B. [CITED: docs/architecture/overview.md §9; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-11] |
| event replay ينشئ إشعارين | Tampering / Repudiation | Inbox keyed by CloudEvent id before effect, explicit ack, DLQ. [CITED: docs/contracts/module-contracts.md §Event Rules] |
| mutation بلا Outbox أو Outbox بلا source mutation | Repudiation / Integrity | same MySQL transaction and rollback test. [CITED: docs/architecture/dependency-rules.md §ملكية المعاملة والتزامن] |
| image/package من مصدر خارجي | Tampering / Information disclosure | internal mirror/registry, signed digest/SBOM, default-deny egress. [CITED: docs/adr/018-air-gapped-supply-chain.md §Decision/§Enforcement] |
| bypass للـGitOps بـmanual deploy | Tampering | manifests/revision review only; no write-capable kubectl in Prod. [CITED: docs/operations/kubernetes-platform.md §ضوابط النشر] |

## Sources

### Primary (HIGH confidence)
- [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md] - قرارات المستخدم والـscope والبوابات المؤجلة.
- [CITED: docs/architecture/overview.md] - modular monolith، request ownership، access، Outbox، quality gates.
- [CITED: docs/architecture/module-catalog.md] - ملكية WorkDefinitions/WorkRecords/Notifications.
- [CITED: docs/architecture/dependency-rules.md] - DAG، transaction owner، Outbox وenforcement.
- [CITED: docs/contracts/module-contracts.md] و[CITED: docs/contracts/events/asyncapi.yaml] - HTTP/event, Streams, Inbox, ack وDLQ.
- [CITED: docs/adr/018-air-gapped-supply-chain.md] و[CITED: docs/adr/019-kubernetes-resilience-and-recovery.md] - air gap، SBOM/signing، HA/DR target.

### Secondary (MEDIUM confidence)
- [CITED: docs/operations/air-gap-supply-chain.md] و[CITED: docs/operations/kubernetes-platform.md] - proposed operating implementation; لا تثبت provisioning فعلياً.
- [CITED: docs/engineering/vertical-slices.md] و[CITED: docs/engineering/testing-strategy.md] - draft engineering patterns.

### Tertiary (LOW confidence)
- لا مصادر ويب مستخدمة؛ Context7 CLI وWebSearch غير متاحين في هذه الجلسة، لذلك لم تُستخدم حقائق package/version خارجية. [VERIFIED: command -v]

## Metadata

**Confidence breakdown:**
- Standard stack: MEDIUM — target technologies ملزمة لكن الإصدارات/الحزم والمرايا غير محددة. [CITED: AGENTS.md §Technology Stack/Frameworks; .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09]
- Architecture: HIGH — يحددها CONTEXT وaccepted architecture/ADRs/contracts. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md; docs/architecture/overview.md]
- Pitfalls: HIGH — مستمدة من boundaries وsupply-chain/operations gates الموثقة. [CITED: docs/architecture/dependency-rules.md; docs/adr/018-air-gapped-supply-chain.md]

**Research date:** 2026-07-15  
**Valid until:** 2026-08-14 للقرارات الداخلية؛ تعاد المراجعة فور حسم D-09 أو platform supersession. [CITED: .planning/phases/01-w1-1-walking-skeleton/01-CONTEXT.md §D-09/§Deferred Ideas]
