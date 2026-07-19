---
name: orval
description: توليد عميل TypeScript مُنمّط من عقود OpenAPI عبر orval — الاستخدام، إضافة تغطية لموديول جديد، وقاعدة «لا عميل يدوي». استخدمها عند إضافة أو تعديل أي endpoint أو عند بناء شاشة تستهلك API.
---

# orval: العميل المولّد من العقد

القاعدة الحاكمة في هذا المشروع: **لا فجوة بين الباك والفرونت** — كل endpoint له عقد
OpenAPI، وكل شاشة تستهلكه عبر العميل المولّد من العقد، لا عبر fetch يدوي.

## خط الأنابيب الموجود (لا تعد بناءه)

```
docs/contracts/api/*.openapi.yaml          ← مصدر الحقيقة (العقد)
        │  npm run api:bundle   (redocly bundle → apps/web/.orval/*.yaml)
        ▼
apps/web/orval.config.ts                   ← مشروع orval لكل عقد
        │  npm run api:generate (orval 8.x → prettier)
        ▼
apps/web/src/api/generated/*.ts            ← عميل fetch مُنمّط، لا يحرَّر يدوياً
```

الأوامر (من `apps/web/`):
- `npm run api:generate` — bundle ثم توليد (شغّله بعد أي تعديل عقد).
- `npm run api:watch` — توليد مستمر أثناء التطوير.
- `npm run api:docs` — مرجع HTML للعقد في `.orval/api-reference.html`.

## إضافة تغطية لموديول/endpoint جديد

1. **العقد أولاً**: أضف المسارات والمخططات في ملف عقد تحت `docs/contracts/api/`
   (وسّع `openapi.yaml` الموحد أو أنشئ `<اسم>.openapi.yaml` بنمط `w1-2.openapi.yaml`).
   الأنماط الإلزامية في العقد: `UUIDv7` بنمطه، طوابع RFC 3339 بلاحقة `Z`،
   رؤوس `Idempotency-Key` و`If-Match` للأوامر، وأغلفة `{ data: ... }` كما في
   العقود القائمة.
2. **اربطه في bundle**: أضف سطر redocly للعقد الجديد في سكربت `api:bundle`
   في `apps/web/package.json` (نفس نمط الأسطر الموجودة).
3. **أضف مشروعاً في `apps/web/orval.config.ts`**: انسخ بنية مشروع `w12` وغيّر
   `input.target` إلى ملف `.orval/` الجديد و`output.target` إلى
   `./src/api/generated/<اسم>.ts`. أبقِ: `client: "fetch"`، `mode: "single"`،
   `schemas: false`، `baseUrl: "/api/v1"`، و`clean: false` (الوحيد `clean: true`
   هو المشروع الأول لأنه يمسح المجلد). حدّث نص `header` لاسم العقد الصحيح.
4. **ولّد**: `npm run api:generate` ثم استورد الدوال من
   `src/api/generated/<اسم>` في الشاشات.
5. **تحقق**: `bash scripts/validate-docs.sh` (يتحقق من تطابق عقد W1.1 مع مسارات
   Laravel الفعلية) و`npm run test:unit` و`npm run build`.

## القواعد

- **ممنوع** كتابة عميل fetch يدوي جديد في `src/api/` لأي endpoint له عقد —
  الملفات اليدوية القائمة (`src/api/day2.ts`، `src/api/w1-3/authorization.ts`)
  دين تقني يُهاجَر إلى عملاء مولّدين عند أول شاشة تمسّها.
- **ممنوع** تحرير `src/api/generated/**` يدوياً — التعديل يكون في العقد ثم توليد.
- عند تغيير endpoint في Laravel حدّث العقد في نفس الـcommit وأعد التوليد؛
  انحراف العقد عن التنفيذ يكسر `validate-docs.sh` لعقد W1.1 ويجب أن يظل كذلك
  عند توسيع الفحص للعقود الأحدث.
- أخطاء API تُعالج في الشاشات عبر `ApiError` الموجود في `src/api.ts` بنفس نمط
  الشاشات القائمة (401 → onSessionExpired، 403 → forbidden، 409/412 → conflict/stale).

## ملاحظات إصدار orval 8.x

- `client: "fetch"` يولّد دوال fetch خام تعيد `{ status, data }`؛ لا تبعية على
  axios أو react-query — لا تغيّر نوع العميل دون قرار معماري مسجّل.
- `schemas: false` يضع الأنواع في نفس ملف الإخراج — أبقها هكذا للاتساق.
- hook ‏`afterAllFilesWrite: prettier` يضمن ثبات التنسيق؛ لا تحذفه وإلا
  اختلف الملف المولّد بين الأجهزة.
