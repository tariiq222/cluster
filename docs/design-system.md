---
doc_id: DS-001
title: النظام التصميمي لمنصة التجمع الصحي الثالث
type: engineering
status: accepted
version: 1.0.0
date: 2026-08-01
owner: مكتب هندسة المنصة
reviewers:
  - مسؤول هندسة البرمجيات
  - مسؤول المنتج
classification: internal
review_cycle: مع كل تغيير تصميمي رئيسي
sources:
  - docs/design-system.md
  - docs/README.md
  - docs/engineering/README.md
references:
  - docs/README.md
  - docs/engineering/README.md
---

# النظام التصميمي لمنصة التجمع الصحي الثالث

> **المصدر المرجعي:** `DESIGN.md`
>
> **الأسطح التنفيذية الحالية:** `apps/web/src/index.css`، `apps/web/src/app/AppShell.css`، `apps/web/src/ui/ui.css`، `apps/web/src/main.tsx`

## 1) الهدف

النظام التصميمي هنا ليس مجرد ألوان؛ هو لغة تشغيل كاملة لواجهة عربية أولاً، كثيفة، هادئة، وقابلة للمسح السريع. المرجع البصري المعتمد هو صفحة HTML المحلية `access-management-dashboard.html`، وتمت مواءمتها إلى تطبيق React + TypeScript داخل المستودع.

### المبادئ

- **غرفة عمليات هادئة:** البنية واضحة قبل الزخرفة.
- **نظام واحد:** tokens ومكوّنات موحدة بدل primitives محلية داخل الموديولات.
- **RTL/LTR متكافئ:** كل primitive يجب أن يعمل في الاتجاهين.
- **محلي بالكامل وقت التشغيل:** لا CDN، لا خطوط خارجية، لا assets عامة.
- **إشارات واضحة:** اللون وحده لا يحمل المعنى؛ النص/الأيقونة/النمط يكمله.

## 2) لوحة الألوان

### أساسية

| Token | Value | Use |
|---|---:|---|
| `--color-primary` | `#293B85` | الإجراء الأساسي، التحديد الحالي، الروابط المهمة |
| `--color-primary-hover` | `#253679` | hover / active |
| `--color-accent` | `#3DAAE1` | فواصل، مؤشرات ثانوية، highlights |
| `--color-ink` | `#1A2735` | النص والعناوين |
| `--color-muted` | `#5A6875` | النص الثانوي |

### محايدة

| Token | Value |
|---|---:|
| `--color-canvas` | `#F6F7F9` |
| `--color-surface` | `#FFFFFF` |
| `--color-border` | `#E4E4E7` |
| `--color-border-strong` | `#CED6DF` |
| `--color-selected` | `#E8ECF7` |
| `--color-primary-soft` | `#EEF0F9` |

### دلالية

| Token | Value |
|---|---:|
| `--color-success` | `#247A42` |
| `--color-warning` | `#9A5B00` |
| `--color-danger` | `#B42318` |

### Dark surfaces

| Token | Value |
|---|---:|
| `--color-dark-canvas` | `#000E22` |
| `--color-dark-surface` | `#082036` |
| `--color-dark-muted` | `#9EB0C3` |

## 3) الخطوط والهرمية

- **Font family:** `IBM Plex Sans Arabic`, ثم `Tahoma`, ثم `Arial`.
- **Numbers:** `tabular-nums` حيث يلزم.
- **Display:** 32px / 700.
- **Headline:** 24px / 700.
- **Title:** 18px / 600.
- **Body:** 16px / 400.
- **Label:** 14px / 600.
- **Meta:** 12px أو أقل عندما يكون النص غير حرِج.

## 4) المسافات والحواف والظلال

- **Radii:** 12px للأدوات، 16px للأسطح، 999px للـpills.
- **Spacing:** 8 / 12 / 16 / 24 / 32 / 48.
- **Surfaces:** flat by default.
- **Shadows:**
  - `--shadow-float` للقوائم المنبثقة.
  - `--shadow-dialog` للـdialogs وdrawers.
- **Motion:** 150–250ms ease-out، مع `prefers-reduced-motion`.

## 5) الهيكل العام للتطبيق

### App shell

- Sidebar يسار/يمين بحسب اتجاه الصفحة، لكن بسلوك RTL/LTR صحيح.
- Sidebar داكن بتدرج مؤسسي أزرق.
- Topbar sticky فاتح، بسيط، بلا glassmorphism.
- Content stage على canvas الرمادي.
- Footer محايد، منخفض الارتفاع.

### Mobile

- Navigation تتحول إلى drawer حقيقي.
- زر إغلاق ظاهر.
- الإغلاق بـEscape وbackdrop click.
- focus يعود للعنصر السابق.

## 6) المكوّنات الموحدة

### `Button`

- الأنماط الحالية: `primary`, `secondary`, `quiet`.
- ارتفاع 44px.
- `primary` للأفعال الأساسية فقط.
- `secondary` للأفعال الثانوية.
- `quiet` لعمليات أقل بروزاً.

### `Field`

- label مرئي دائماً.
- help/error مربوطة دلالياً.
- ارتفاع التحكم 44px.
- لا تستخدم placeholder كبديل عن label.

### `Select`

- search يظهر تلقائياً عند تجاوز العتبة المحددة.
- trigger button حقيقي.
- supports keyboard navigation and outside click close.

### `Drawer`

- Surface جانبي موحد.
- focus management مدمج.
- dismissable controlled.

### `Page` / `Panel`

- `Page` للغلاف العام للشاشات.
- `PageHeader` للعناوين العليا.
- `Panel` للأسطح التشغيلية.
- `PanelGrid` لشبكتين قابلة للانهيار على الشاشات الصغيرة.

### `Feedback`

- `EmptyState` للحالة الفارغة.
- `InlineError` للخطأ القابل للاستئناف.
- `SkeletonList` للحمل.
- `StatusBadge` للحالات المتكررة.

## 7) أنماط الشاشات

### Dashboard

- حتى 4 مؤشرات أساسية فوق الطية.
- الحالة + المصدر + الفترة + freshness عند الحاجة.

### Tables

- رأس واضح.
- صفوف بخطوط هادئة.
- scroll أفقي عند الضرورة فقط.

### Trees / Boards

- selection مميز بدون صخب.
- hover وخطوط tree دقيقة.
- لا بطاقات داخل بطاقات.

### Forms

- labels فوق الحقول.
- errors قرب الحقل.
- layouts مستقرة على mobile.

## 8) الوصولية

- تباين AA على النصوص الأساسية.
- focus visible واضح.
- aria labels للأزرار الأيقونية.
- لا تعتمد أي حالة على اللون فقط.

## 9) الأصول والشبكة

- الخطوط والأيقونات محلية داخل bundle.
- `lucide-react` هو مصدر الأيقونات الوحيد.
- لا CDN، لا fonts.googleapis.com، لا unpkg.
- API الداخلي same-origin فقط.

## 10) ملفات التعديل عند تغيير التصميم

- `apps/web/src/index.css`
- `apps/web/src/app/AppShell.css`
- `apps/web/src/ui/ui.css`
- `apps/web/src/main.tsx`
- `DESIGN.md`

## 11) ملاحظات تشغيلية

- أي تعديل جديد يجب أن يمر على RTL وLTR.
- يجب التحقق من صفحات login/dashboard/tables/drawers بعد كل تغيير تصميمي كبير.
- أي primitive جديدة تبدأ من `apps/web/src/ui` قبل استخدامها في أي feature.
