---
doc_id: ARC-CM-001
title: خريطة السياقات المعمارية
type: architecture
status: accepted
version: 1.2.0
date: '2026-07-15'
owner: مكتب هندسة المنصة
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: نصف سنوي
sources: []
references: []
---
# خريطة السياقات

## 1. نطاق الخريطة

تحدد هذه الوثيقة حدود السياقات، واتجاه الاعتماد، وعلاقات التكامل بين الموديولات التسعة عشر المعرفة في [النظرة المعمارية](overview.md). السهم `A → B` يعني أن `A` يعتمد على عقد منشور من `B`؛ لا يعني أن `A` يملك بيانات `B`.

## 2. طبقات السياق

```mermaid
flowchart TB
    subgraph L11["المستهلكات الطرفية المشتقة"]
        N["Notifications"]
        S["Search"]
        R["Reporting"]
        WS["Workspace"]
    end

    subgraph L10["المخاطر"]
        RK["Risk"]
    end

    subgraph L9["المحافظ والمشاريع"]
        PP["PortfolioProjects"]
    end

    subgraph L8["السجلات والاستراتيجية"]
        WR["WorkRecords"]
        ST["Strategy\nالمالك الوحيد للمؤشرات"]
    end

    subgraph L7["التنفيذ"]
        T["Tasks"]
    end

    subgraph L6["التعاون"]
        C["Collaboration"]
    end

    subgraph L5["التعريف والمحتوى"]
        WD["WorkDefinitions"]
        D["Documents"]
    end

    subgraph L4["التشغيل والحوكمة"]
        WF["Workflow"]
        RG["RecordsGovernance"]
    end

    subgraph L3["التدقيق"]
        AU["Audit"]
    end

    subgraph L2["قرار الوصول"]
        AZ["Authorization"]
    end

    subgraph L1["الهوية"]
        I["Identity"]
    end

    subgraph L0["الجذور"]
        O["Organization"]
        PS["PlatformSettings"]
    end

    I --> O
    I --> PS
    AZ --> I
    AZ --> O
    AZ --> PS
    AU --> AZ

    WF --> O
    WF --> AZ
    WF --> AU
    RG --> PS
    RG --> AZ
    RG --> AU

    WD --> PS
    WD --> WF
    WD --> AZ
    WD --> AU
    D --> RG
    D --> AZ
    D --> AU

    C --> D
    C --> RG
    C --> AZ
    C --> AU

    T --> I
    T --> C
    T --> D
    T --> RG
    T --> AZ
    T --> AU

    WR --> WD
    WR --> WF
    WR --> T
    WR --> C
    WR --> D
    WR --> RG
    WR --> AZ
    WR --> AU

    ST --> O
    ST --> WF
    ST --> T
    ST --> C
    ST --> D
    ST --> RG
    ST --> AZ
    ST --> AU

    PP --> O
    PP --> ST
    PP --> WF
    PP --> T
    PP --> C
    PP --> D
    PP --> RG
    PP --> AZ
    PP --> AU

    RK --> O
    RK --> ST
    RK --> PP
    RK --> WF
    RK --> T
    RK --> C
    RK --> D
    RK --> RG
    RK --> AZ
    RK --> AU

    N -.-> I
    N -.-> AZ
    S -.-> AZ
    R -.-> O
    R -.-> AZ
    WS -.-> AZ

    N -.-> WR
    N -.-> WF
    N -.-> T
    N -.-> C
    N -.-> ST
    N -.-> PP
    N -.-> RK

    S -.-> WR
    S -.-> T
    S -.-> C
    S -.-> D
    S -.-> ST
    S -.-> PP
    S -.-> RK

    R -.-> WR
    R -.-> WF
    R -.-> T
    R -.-> ST
    R -.-> PP
    R -.-> RK

    WS -.-> WR
    WS -.-> WF
    WS -.-> T
    WS -.-> C
    WS -.-> ST
    WS -.-> PP
    WS -.-> RK
```

الأسهم المتصلة عقود متزامنة مسموحة. الأسهم المتقطعة اعتماد على Published Events أو Projection Feeds إضافة إلى استدعاء `Authorization` عند القراءة. جميع الأسهم تتجه إلى رتبة أدنى؛ الموديولات الطرفية لا تقدم عقوداً تعتمد عليها موديولات المصدر.

## 3. مصفوفة الاعتماد المباشر

| الموديول | يعتمد مباشرة على |
|---|---|
| `PlatformSettings` | لا شيء |
| `Organization` | لا شيء |
| `Identity` | `Organization`, `PlatformSettings` |
| `Authorization` | `Identity`, `Organization`, `PlatformSettings` |
| `Audit` | `Authorization` |
| `Workflow` | `Organization`, `Authorization`, `Audit` |
| `RecordsGovernance` | `PlatformSettings`, `Authorization`, `Audit` |
| `WorkDefinitions` | `PlatformSettings`, `Workflow`, `Authorization`, `Audit` |
| `Documents` | `RecordsGovernance`, `Authorization`, `Audit` |
| `Collaboration` | `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Tasks` | `Identity`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `WorkRecords` | `WorkDefinitions`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Strategy` | `Organization`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `PortfolioProjects` | `Organization`, `Strategy`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Risk` | `Organization`, `Strategy`, `PortfolioProjects`, `Workflow`, `Tasks`, `Collaboration`, `Documents`, `RecordsGovernance`, `Authorization`, `Audit` |
| `Notifications` | `Identity`, `Authorization`، وأحداث الموديولات المصدرية |
| `Search` | `Authorization`، وأحداث المحتوى القابل للفهرسة |
| `Reporting` | `Organization`, `Authorization`، وأحداث أو Projection Feeds المصدرية |
| `Workspace` | `Authorization`، وأحداث عناصر العمل المصدرية |

## 4. أنماط العلاقة

| النمط | الاستخدام | القاعدة |
|---|---|---|
| Published Contract | قرار فوري أو invariant | العقد يملكه الموديول المقدم، ويعيد DTO ثابتاً |
| Published Event | حقيقة حدثت واستهلاك مؤجل | المنتج يملك schema بصيغة ماضية ويحفظه في Outbox |
| Projection Feed | إسقاط تقريري كبير أو إعادة بناء | المالك يقدم feed محكوماً ومتعدد الصفحات ولا يكشف جدوله |
| Reference by ID | ربط سجل بسجل في سياق آخر | معرف ثابت مع تحقق عبر عقد، بلا Foreign Key عابر لملكية الموديول إذا منع الاستقلال |
| Record Reference | ربط عام بمصدر | `{record_type, record_id}` مع إعادة تفويض عند فتح المصدر |
| Customer/Supplier | موديول أعلى رتبة يستهلك عقد الأدنى | المستهلك لا يفرض نموذج تخزينه على المورد |

## 5. خريطة الحقائق الرئيسية

| الحقيقة | المالك الوحيد | ما يحتفظ به الآخرون |
|---|---|---|
| إعدادات المنصة المنشورة | `PlatformSettings` | مفتاح وإصدار أو Cache قابل للإبطال |
| الشخص وPII الأساسية | `Organization` | `person_id` وملخص عرض محدود بلا FK أو join |
| الهيكل والوحدات والمناصب والعلاقات | `Organization` | معرفات وملخصات مشتقة |
| الحساب والجلسة والملف التشغيلي | `Identity` | `user_id` وملخص عرض |
| الدور والقدرة والتفويض وسياسة الحقل | `Authorization` | قرار أو `decision_id` مؤقت، لا نسخة من السياسة |
| تعريف نوع العمل وإصداره | `WorkDefinitions` | `work_type_version_id` |
| مثيل العمل الديناميكي، بما فيه الطلب | `WorkRecords` | `record_ref` وإسقاطات مشتقة |
| تعريف المسار ومثيلاته وقراراته | `Workflow` | `workflow_instance_id` |
| التعليقات والمنشن والمشاركون | `Collaboration` | `thread_id` |
| المهمة وحالتها ومسؤولها | `Tasks` | `task_id` أو إسقاط مساحة عمل |
| الملف وإصداره وتصنيفه | `Documents` | `document_id` وسبب الربط |
| سياسات الاحتفاظ والحجز والإتلاف | `RecordsGovernance` | `governance_subject_id` أو نتيجة قرار |
| المؤشر وتعريفه وقياساته ومستهدفاته | `Strategy` | `indicator_id` وبيانات تخطيط محلية مسموحة |
| المحفظة والبرنامج والمشروع | `PortfolioProjects` | معرفات وروابط وأحداث |
| الخطر والضوابط وخطة المعالجة | `Risk` | معرفات وروابط وأحداث |
| الحقيقة الأمنية والتشغيلية غير القابلة للتعديل | `Audit` | `audit_event_id` فقط |
| نتيجة البحث | `Search` | لا يعتمد عليها مصدر لتقرير الحقيقة |
| التقرير وRead Model | `Reporting` | لا يكتب إلى المصدر |
| عنصر مساحة العمل | `Workspace` | مؤشر مشتق إلى سجل المصدر |
| الإشعار | `Notifications` | رابط إلى المصدر يعاد تفويضه عند الفتح |

## 6. RecordFacts وAuthorization بلا دورات

`RecordFacts` لغة منشورة يملك schema الخاص بها `Authorization`. يبني الموديول المالك القيم من Envelope أو Aggregate الذي يملكه ثم يمررها مع الفاعل والقدرة المطلوبة:

```text
RecordFacts
- record_type
- record_id
- owner_organization_id
- owner_organization_unit_id
- created_by_user_id
- current_assignee_user_id اختياري
- participant_user_ids أو مفتاح مجموعة مشاركة
- visibility_scope
- shared_organization_unit_ids
- classification
- state
- definition_version_id اختياري
- field_policy_key اختياري
- attributes محدودة ومعلنة لكل capability
```

```mermaid
sequenceDiagram
    participant Caller as الموديول المالك
    participant Auth as Authorization
    participant Identity as Identity
    participant Org as Organization

    Caller->>Caller: تحميل Envelope غير الحساس وبناء RecordFacts
    Caller->>Auth: DecideAccess(actor, capability, RecordFacts)
    Auth->>Identity: ResolveActiveIdentity
    Identity-->>Auth: حالة الحساب فقط
    Auth->>Org: ResolveOrganizationScope
    Org-->>Auth: النطاق والعلاقات السارية
    Auth->>Auth: RBAC + ABAC + التصنيف + الحالة + الحقول
    Auth-->>Caller: AccessDecision + allowed_fields + explanation_code
```

قواعد منع الدورة:

- `Authorization` لا يستورد `WorkRecords` أو `Tasks` أو `Documents` أو أي موديول أعمال.
- `Authorization` لا يقرأ جداول السجل ولا يطلب payload منه.
- الموديول المالك مسؤول عن صحة `RecordFacts` ولا يقبلها من العميل.
- الاستعلامات الجماعية تطلب من `Authorization` `ScopePredicate` أو `AuthorizedScopeSet` ثم تطبقها داخل مخزن المالك.
- `Search` و`Reporting` يخزنان حقائق تفويض مشتقة تكفي للترشيح الأولي، ثم يعاد فحص القرار عند فتح السجل أو تصدير الحقول الحساسة.
- `Tasks` و`Documents` و`Collaboration` لا تستدعي الموديول المصدر للتحقق؛ يعيد endpoint المالك أو التطبيق المنسق تمرير `RecordFacts` الصحيحة.

## 7. WorkRecords وWorkDefinitions وWorkflow

```mermaid
flowchart LR
    WD["WorkDefinitions\nSchema + immutable version"]
    WR["WorkRecords\nEnvelope + payload + business state"]
    WF["Workflow\nDefinition/version + execution state"]
    T["Tasks\nIndependent task state"]

    WR -->|"GetPublishedWorkTypeSchema"| WD
    WR -->|"StartWorkflow / RecordDecision"| WF
    WR -->|"CreateTask عند الحاجة الذرية"| T
    WF -.->|"WorkflowCompleted"| WR
```

السهم غير المتزامن الأخير لا يغير `WorkRecords` تلقائياً داخل مستهلك خفي إذا كان الانتقال التجاري ملزماً؛ يتولى منسق صريح إصدار Command إلى `WorkRecords`. لا يملك `Workflow` معنى إكمال الطلب أو المشروع.

## 8. Strategy وPortfolioProjects وRisk

```mermaid
flowchart LR
    ST["Strategy\nPlans, objectives, initiatives, indicators"]
    PP["PortfolioProjects\nPortfolios, programs, projects"]
    RK["Risk\nRisks, controls, treatments"]

    PP -->|"Indicator contracts"| ST
    RK -->|"Objective and indicator references"| ST
    RK -->|"Portfolio/project references"| PP
```

- المبادرة عنصر داخل `Strategy` ولا تدخل تسلسل المحفظة والبرنامج والمشروع.
- التسلسل الوحيد في `PortfolioProjects` هو: المحفظة ← البرنامج ← المشروع.
- `PortfolioProjects` لا يملك المؤشر؛ يقدم الأثر المتوقع والفعلي إلى `Strategy` للاعتماد.
- `Risk` لا ينسخ هدفاً أو مؤشراً أو مشروعاً، ويستخدم `Tasks` لخطط المعالجة و`Workflow` للموافقات و`Documents` للأدلة.

## 9. السياقات المشتقة الطرفية

`Notifications` و`Search` و`Reporting` و`Workspace` مستهلكات نهائية بالنسبة إلى مصادر الأعمال:

- لا يستدعيها المصدر متزامناً داخل معاملته.
- لا تكتب في جداول المصدر.
- تعالج الحدث أكثر من مرة بأمان.
- تحفظ نقطة تقدم أو Inbox يمنع تكرار الأثر.
- يمكن إعادة بناء إسقاطاتها من الأحداث أو Projection Feeds.
- لا تستخدم نتيجة مشتقة لاتخاذ انتقال مجال دون إعادة الرجوع إلى عقد المالك.

## 10. الأنظمة خارج السياق

لا توجد تكاملات خارجية في المرحلة الأولى. «موارد» والأنظمة المالية والسريرية جهات مستقبلية خارج حدود المنصة. لا ينشأ Adapter أو Contract لها قبل تحديد النظام والبيانات والاتجاه والمالك ومتطلبات الأمن والتشغيل المعزول.
