---
doc_id: PLN-R1-AGT-001
title: لوحة وكلاء تنفيذ R1
type: plans
status: proposed
version: 1.0.0
date: 2026-07-17
owner: مكتب هندسة المنصة
reviewers:
- قائد التقنية
- مسؤول العمليات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/plans/release-1-parallel-delivery-plan.md
- docs/plans/active-delivery-status.md
references:
- docs/architecture/dependency-rules.md
- docs/engineering/vertical-slices.md
---
# لوحة وكلاء تنفيذ R1

## قواعد التحكم

- يبقى worktree الرئيسي على `main` للدمج والتحقق فقط.
- لكل بطاقة branch وworktree وملفات مملوكة حصرياً.
- لا يعدل أي وكيل `docs/catalog.yaml` أو `mkdocs.yml` أو سجل الحالة؛ يدمجها المنسق.
- لا يدفع أي وكيل إلى remote ولا يدمج فرعه. يسلم commit محلياً مع دليل التحقق.
- إذا احتاج مساران الملف نفسه ينتقلان إلى `blocked` حتى يعيد المنسق تقسيم الملكية.
- لا تحفظ أسرار أو receipts حية أو host inputs داخل Git.

## البطاقات

| البطاقة | الحالة | branch | worktree | ملكية الكتابة | بوابة التسليم |
|---|---|---|---|---|---|
| P0-A CI/Release | ready | `agent/p0-ci-release` | `cluster-worktrees/p0-ci-release` | `.github/workflows/ci.yml` و`scripts/verify_ci_config.rb` و`infra/platform/release/` و`docs/engineering/ci-cd-and-release.md` | inputs حقيقية أو blocker مثبت؛ `make verify-ci-config`؛ لا placeholders جديدة ولا أسرار |
| P0-B Host/NET-04 | ready | `agent/p0-host-net04` | `cluster-worktrees/p0-host-net04` | `scripts/host_preflight.py` و`scripts/net04_network_policy.py` و`infra/platform/network/` و`infra/platform/environments/` | policy ومدخلات حية خارج Git أو blocker مثبت؛ الاختبارات المستهدفة ناجحة |
| W12-REQ | ready | `agent/w12-requirements` | `cluster-worktrees/w12-requirements` | ملفات W1.2 requirements/gate الجديدة فقط تحت مجلد خطة R1 | REQ/TEST ثابتة، قرارات مفتوحة ظاهرة، لا تعديل `apps/`، ومدقق الوثائق ناجح بعد تكامل الفهارس |
| W12-ADR | ready | `agent/w12-boundary-adr` | `cluster-worktrees/w12-boundary-adr` | ADR جديد واحد لملكية Organization/Identity/Import فقط | بدائل وقرار وتبعات وتحديثات لاحقة محددة؛ لا تعديل وثائق accepted الحالية قبل المراجعة |
| W12-FE | ready | `agent/w12-frontend-contracts` | `cluster-worktrees/w12-frontend-contracts` | ملف frontend slices جديد واحد تحت مجلد خطة R1 | route/file ownership وOpenAPI subset وmocks واختبارات RTL/LTR/a11y محددة؛ لا تعديل `apps/web` |

## ترتيب الدمج

1. تراجع مخرجات W12-ADR أولاً لأنها تقيد W12-REQ.
2. تراجع W12-REQ ثم W12-FE، ويضيف المنسق مراجع catalog وMkDocs مرة واحدة.
3. يدمج P0-A وP0-B فقط عندما ترتبط الأدلة بالrevision نفسه ولا تحتوي أسراراً.
4. بعد كل دمج يشغل المنسق مدقق الوثائق وحدود الموديولات والاختبارات المستهدفة.
5. لا تفتح بطاقات implementation لـW1.2 قبل إغلاق P0 أو قرار راعٍ مسجل.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-17 | مكتب هندسة المنصة | إنشاء لوحة worktrees والملكية وبوابات التسليم لأول خمسة وكلاء |
