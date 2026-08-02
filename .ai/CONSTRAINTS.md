# Cluster Constraints

## قيود ملزمة

- لا تكسر حدود الموديولات أو ملكية الجداول والعقود.
- لا تنفذ SQL مباشرًا على جداول مملوكة لموديول آخر.
- لا تتجاوز محرك القرار المركزي للصلاحيات.
- لا تغيّر OpenAPI أو response envelopes ضمنيًا.
- لا تعدّل الملفات المولدة يدويًا.
- لا تضف Dependency أو خدمة خارجية دون حاجة واضحة.
- لا تحول النظام إلى Microservices في المرحلة الحالية.
- لا توسّع نطاق المهمة تلقائيًا.
- لا تغيّر سلوكًا أمنيًا أو بيانات حساسة دون اختبار Regression مناسب.

## تغييرات عالية الخطورة

تحتاج خطة وتحليل أثر قبل التنفيذ:

- Authentication وSessions وCSRF.
- Authorization وRBAC/ABAC.
- قواعد البيانات وMigrations والحذف.
- Outbox وWorkers والتزامن وIdempotency.
- عقود API وOpenAPI.
- رفع وتنزيل الملفات والتخزين والفحص الأمني.
- Production وDeployment وSecrets.
