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

## السرية

المحتوى داخلي ومملوك. لا تضف أسراراً أو بيانات شخصية أو بيانات تشغيلية حقيقية إلى الوثائق أو الأمثلة أو سجل Git.

## المساهمة

اقرأ `CONTRIBUTING.md` و`SECURITY.md` قبل التغيير.
