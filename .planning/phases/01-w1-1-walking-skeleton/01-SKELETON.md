# Walking Skeleton — منصة التجمع الصحي الثالث

**Phase:** 1  
**Generated:** 2026-07-15

## Capability Proven End-to-End

يسجّل حساب تطوير ثابت تابع لمنشأة اختبارية الدخول إلى التطبيق العربي الموحد، ويرسل `WorkRecord` من نوع العمل المنشور `request` بعنوان ووصف، ويراه في «طلباتي» ثم يرى إشعاراً داخلياً مشتقاً منه؛ ويرفض Laravel قراءة الحساب في المنشأة الأخرى للسجل.

## Architectural Decisions

حالة الاعتماد والأدلة المرجعية للمسار موثقة في [سجل بوابة الإدخال](01-ENTRY-GATE.md). لا تعني الخيارات المقترحة أدناه اعتماد جذر منتج أو حزمة أو منصة دائمة.

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Laravel modular monolith + unified React/TypeScript app؛ الإصدارات والحزم لا تُختار إلا في بوابة Wave 0 | قرار معماري ملزم، ولا توجد manifest أو مرايا أو lockfiles معتمدة في المستودع. |
| Data layer | MySQL هو مصدر الحقيقة؛ `WorkRecords` يحفظ الـEnvelope وOutbox في المعاملة ذاتها؛ Valkey-compatible Streams للنقل؛ Notifications يملك Inbox والأثر المشتق | يحقق ملكية البيانات وat-least-once وInbox-before-effect من العقود المعتمدة. |
| Auth | حسابا fixture ثابتان فقط، ومنشأتان ثابتتان، وقرار access خلفي ضيق يطابق `owner_facility_id` | يثبت D-11 العزل من دون بناء إدارة الهوية أو سياسات RBAC/ABAC الكاملة قبل مرحلتيهما. |
| Deployment target | Compose محلي/تطويري موثق بعد اعتماد صور الداخل؛ خادم Docker الأحادي في D-03 انحراف محكوم لا يغلق بوابة الإنتاج الدائم | لا يساوي Compose الدليل المقبول لـKubernetes/GitOps أو NetworkPolicy أو rolling rollback. |
| Directory layout | `apps/api` و`apps/web` مقترحان فقط، و`infra/dev` لتعريفات التشغيل التطويري المقترحة | لا يصبح أي جذر product معتمداً إلا بعد تعبئة `approved-product-roots` في سجل البوابة قبل إنشاء scaffold. |
| Supply chain | لا artifact أو image أو mirror أو توقيع أو SBOM يُدعى امتثاله قبل قرار intake والمفاتيح والتحقق | يلتزم D-02 وD-08 وD-09 ومتطلبات SEC/OPS بدلاً من اختراع منتج أو إصدار. |

## Stack Touched in Phase 1

- [ ] Project scaffold, build, lint, test runner, and lockfiles after approved intake
- [ ] Routing: login, my requests, new request, and notifications in one React application
- [ ] Database: one real insert and authorized read of a `WorkRecord`, plus atomic Outbox row
- [ ] UI: title/description submit form wired to the API in Arabic/RTL and English/LTR
- [ ] Async: relay → stream → Notifications Inbox → one in-app notification
- [ ] Developer run: documented local full-stack command; permanent environment evidence remains a blocking gate

## Explicit Gate Boundaries

- الحالة التفصيلية للـ`approved-dependency-bom` و`internal-source-policy` و`permanent-gate-status` ودليل D-01 إلى D-10 في [سجل بوابة الإدخال](01-ENTRY-GATE.md)؛ جميع الأدلة التشغيلية الدائمة في هذا المسار حالياً محجوبة صراحة.
- D-04 through D-07 require an approved internal MinIO service, encryption/key custody, 15-minute backup schedule, 30-day retention, and a measured manual restore drill. These are not evidenced by the development path.
- D-09 requires approved isolated intake, signing, signing-key custody, verification, registry/mirror, and transport before permanent deployment may be claimed.
- D-03 and D-10 require a recorded CCB decision to supersede or retain the Kubernetes/GitOps target before any Docker-host procedure is described as production-compliant.
- The developer path may use external package download only as permitted by D-02; it must never become a production build or runtime dependency.

## Out of Scope (Deferred to Later Slices)

- Organization, Identity, and Authorization administration; the two fixtures prove only the bounded isolation path.
- Workflow lifecycle, dynamic form builder, search, reporting, dashboards, documents, and external integrations.
- Permanent release promotion, egress policy evidence, internal artifact intake, signing, key custody, registry/mirror, GitOps admission, rolling rollback, and production recovery acceptance.
- Attachments and production backups until the separate internal MinIO, encryption, and recovery decisions have executable evidence.

## Subsequent Slice Plan

Each later phase extends this skeleton without changing its ownership and transaction rules:

- Phase 2: Organization, local Identity, and governed import.
- Phase 3: full explainable Authorization and supervisory relationships.
- Phase 4: published WorkDefinitions and form builder.
- Phase 5: immutable Workflow definitions and execution.
- Phase 6: complete internal-request lifecycle in `WorkRecords`.
