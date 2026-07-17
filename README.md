---
doc_id: GOV-ROOT-001
title: منصة التجمع الصحي الثالث
type: governance
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مكتب هندسة المنصة
reviewers:
  - مسؤول الحوكمة
  - مسؤول أمن المعلومات
classification: internal
review_cycle: ربع سنوي
sources:
  - docs/README.md
references:
  - docs/governance/document-control.md
---

# منصة التجمع الصحي الثالث | Third Health Cluster Platform

هذه الحزمة هي مصدر الوثائق الجاري لمنصة داخلية مملوكة للتجمع الصحي الثالث. الوثائق الأساسية بالعربية مع المصطلحات التقنية الإنجليزية اللازمة، وتوجد تحت `docs/`.

## البداية

- نقطة الدخول المعمارية: `docs/architecture/overview.md`.
- ضوابط الملكية والمراجعة: `docs/governance/document-control.md`.
- فهرس الوثائق المعتمدة: `docs/README.md`.

## التحقق

ثبّت اعتمادات التوثيق من المصدر الداخلي المعتمد ثم شغّل الفحص المحلي:

```sh
python -m pip install -r requirements-docs.txt
./scripts/validate-docs.sh
```

يتحقق الفاحص من YAML وJSON وصياغة Shell والواجهة الأمامية والمراجع والروابط ومقاطعها وتغطية الكتالوج و`mkdocs.yml` وعلامات عدم الاكتمال والمجلدات والملفات غير المصرح بها. يجري CI فحص Mermaid في مهمة مستقلة عند ضبط صورة Mermaid الداخلية.

لبناء موقع التوثيق، يلزم توفر اعتمادات `requirements-docs.txt` مسبقاً في البيئة:

```sh
mkdocs build --strict
```

لا يفترض هذا المستودع اتصالاً بالإنترنت أثناء التحقق أو CI.

### تحقق عقد المضيف وPreflight (W11-OPS-01)

ثبّت اعتمادات اختبارات التشغيل من المصدر الداخلي المعتمد، ثم شغّل التحقق المحلي
على مثال المدخلات غير السرية والـ manifest الموجودين في المستودع:

```sh
python3 -m pip install -r requirements-ops-test.txt
python3 scripts/host_preflight.py validate \
  --inputs infra/platform/environments/host.example.json \
  --secrets infra/platform/contracts/required-secrets.json \
  --receipt /tmp/cluster-host-inputs-receipt.json
```

يمكن تشغيل بوابات OPS-01 المحلية المركزة عبر `make test-unit-w11-ops-01`
و`make test-integration-w11-ops-01` و`make test-e2e-w11-ops-01-local` أو تجميعها
مع تحقق المدخلات عبر `make verify-w11-ops-01-local`. لا تمثل هذه الأوامر قبول E2E
على Staging ولا تنشئ الهدف العام `test-e2e-w1-1-ops` قبل اكتمال بقية مهام الموجة.
أما preflight الحقيقي فيتطلب ملف مدخلات خاصاً بالبيئة خارج Git وmanifest أسماء الأسرار؛
لا تُحفظ قيم الأسرار في المستودع. الفحص read-only ولا يغيّر حالة المضيف، باستثناء
كتابة receipt منقح يحدده المستخدم.

يشغّل مالك المضيف الفحص الحي بعد توفير `HOST_INPUTS` و`HOST_RECEIPT` واعتمادات
القراءة فقط المطلوبة في متغيرات البيئة:

```sh
make preflight-w11-ops-01-live \
  HOST_INPUTS=/secure/outside-git/host.json \
  HOST_RECEIPT=/secure/evidence/host-preflight.json
```

### تحقق حزمة الإنتاج (W11-BLD-02)

تبني البوابة المحلية صورتي API وWeb من lockfiles، وتفحص أن طبقات runtime تعمل بغير
root ولا تحتوي أدوات البناء، ثم تشغل Compose المؤقت ورحلتي المتصفح:

```sh
make verify-w11-bld-02-local
```

يمكن تشغيل المستويات منفصلة عبر `make test-unit-w11-bld-02` و
`make test-integration-w11-bld-02` و`make test-e2e-w11-bld-02-local`. يبقي Compose
`APP_ENV=production` افتراضياً؛ يضبط E2E المحلي `testing` داخل العملية المؤقتة فقط حتى
تتوفر حسابات fixture، وينظف الحاويات والـvolumes بعد النتيجة. لا تشمل هذه البوابة SBOM
أو التوقيع أو نشر Dokploy؛ هذه مخرجات المهام التالية.

## السرية

المحتوى داخلي ومملوك. لا تضف أسراراً أو بيانات شخصية أو بيانات تشغيلية حقيقية إلى الوثائق أو الأمثلة أو سجل Git.

## المساهمة

اقرأ `CONTRIBUTING.md` و`SECURITY.md` قبل التغيير.
