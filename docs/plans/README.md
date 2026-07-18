---
doc_id: PLN-IDX-001
title: فهرس خطط التنفيذ
type: plans
status: accepted
version: 4.0.0
date: 2026-07-19
owner: التنفيذ التقني
reviewers: []
classification: internal
review_cycle: عند تغير السلوك أو النطاق
sources: []
references: []
---
# خطط التنفيذ

هذه المجلدات لخدمة بناء النظام، وليست مسار موافقات. مصدر الحالة الوحيد هو
`active-delivery-status.md`، ومصدر ترتيب العمل هو `implementation-roadmap.md`.

| الوثيقة | استخدامها الآن |
|---|---|
| [خارطة التنفيذ](implementation-roadmap.md) | برنامج الخمسة أيام من W1.3 حتى تكامل R3 |
| [حالة التسليم](active-delivery-status.md) | ما اكتمل فعلياً وما يُنفذ الآن |
| [R1](release-1-platform.md) | بقية قدرات المنصة العامة واختبارها الآلي |
| [بطاقة W1.3](release-1/w1-3-frontend-slices.md) | حزمة اليوم الأول النشطة لمسارات Authorization في React |
| [R2](release-2-strategy-portfolio.md) | الشريحة الرأسية للاستراتيجية والمؤشرات والمشاريع |
| [R3](release-3-risk.md) | الشريحة الرأسية للمخاطر والضوابط والمعالجة |
| [جاهزية التشغيل](readiness-checklist.md) | أوامر آلية قبل النشر الفعلي، بلا توقيعات |
| [سجل اكتمال W1.2](release-1/w1-2-frontend-slices.md) | عقد منجز للرجوع فقط، وليس عملاً قادماً |
| [تشغيل W1.1 المؤجل](w1-1-remaining-delivery-tasks.md) | ثلاث عمليات آلية تنفذ عند النشر النهائي |

لا تُنشأ خطة مستقلة لكل شاشة أو API. تضاف التفاصيل إلى اختبار أو عقد قريب من
الكود، وتُحدّث هذه الوثائق فقط إذا تغير النطاق أو ترتيب التنفيذ.
