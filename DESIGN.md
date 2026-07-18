---
name: Third Health Cluster Platform
description: Approved unified design system for an Arabic-first internal operations platform.
colors:
  canvas: "#F2FBFD"
  surface: "#FFFFFF"
  ink: "#0B2F3A"
  muted: "#496A75"
  border: "#CDEEF7"
  primary: "#0077B6"
  primary-hover: "#005F92"
  accent: "#00B4D8"
  success: "#247A42"
  warning: "#9A5B00"
  danger: "#B42318"
  on-color: "#FFFFFF"
  dark-canvas: "#071F2B"
  dark-surface: "#0D2B38"
  dark-muted: "#A9C2CC"
  dark-border: "#315363"
typography:
  display:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "2rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "normal"
  headline:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "normal"
  title:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  body:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "normal"
  label:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  sm: "12px"
  md: "16px"
  pill: "999px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-color}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "10px 16px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.on-color}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "10px 16px"
    height: "44px"
  field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "10px 12px"
    height: "44px"
  panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "24px"
  metric-tile:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "16px"
  status-success:
    backgroundColor: "{colors.success}"
    textColor: "{colors.on-color}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "4px 8px"
---

# Design System: Third Health Cluster Platform

> **OWNER-APPROVED BASELINE — 2026-07-18**
>
> اعتمد المالك اتجاه `rrrr.html` مرجعاً بصرياً للـDashboard والـApp Shell. يطبّق
> الهيكل والألوان والكثافة محلياً، مع بقاء بيانات العرض وCDN والأصول الخارجية خارج المنتج.

## 1. Overview

**Creative North Star: "غرفة عمليات هادئة"**

يخدم التصميم موظفين يعملون على طلبات وقرارات ومؤشرات تشغيلية داخلية. يجب أن يشعر المستخدم بأنه داخل غرفة عمليات هادئة: المعلومات موثوقة، الحالة واضحة، والإجراء التالي ظاهر من دون زخرفة أو ضوضاء. نحافظ على قوة المرجع في العربية وRTL، والمسافات المنتظمة، والبنية المؤسسية، ونصحح ضعف التباين، ازدحام المؤشرات، تناقض الأرقام، وحالات الجوال غير المكتملة.

التصميم موحد عبر تطبيق React واحد. لا ينشئ كل موديول مكتبة أو مكونات أو أيقونات أو مؤشرات خاصة به. الـShell يملك primitives العامة، بينما تسجل الموديولات محتواها ومساراتها من دون نسخ primitives أو معرفة تفاصيل الموديولات الأخرى.

كل أصل يحتاجه المستخدم وقت التشغيل موجود داخل bundle أو صورة النشر. يسمح لمسار البناء بمصادر الحزم المعتمدة والمقفلة في lockfiles وفق `docs/operations/air-gap-supply-chain.md`، لكن المتصفح وحاوية الإنتاج لا يعتمدان على CDN أو خط أو script أو صورة أو API عام. API المنتج الداخلي same-origin جزء من النظام وليس اتصالاً خارجياً.

**Key Characteristics:**

- عربية أولاً مع دعم كامل للإنجليزية وRTL/LTR.
- كثافة تشغيلية منضبطة، بأربع مؤشرات قرار رئيسية كحد أقصى فوق الطية.
- مكونات مألوفة وثابتة الحالات، لا مفاجآت شكلية ولا affordances مخترعة.
- لون رئيسي أزرق مخضر داكن عالي التباين، وحالات دلالية لا تستخدم للزينة.
- أيقونات Lucide موحدة ومضمنة في bundle، وApache ECharts هي مكتبة الرسوم الافتراضية خلف مكونات React داخلية موحدة.
- لا اتصال إنترنت خارجي مطلوب وقت التشغيل، ولا fallback صامت إلى مورد عام.

**The One System Rule.** يوجد مصدر واحد للألوان والخطوط والمسافات والحواف، ومكتبة مكونات واحدة داخل `apps/web/src/components/ui`. يمنع إنشاء primitive مواز داخل موديول أعمال.

**The Runtime-Local Rule.** كل font وicon وimage وscript وstyle مطلوب وقت التشغيل يخدم من نفس الأصل. `connect-src 'self'` هو الحد الافتراضي، وأي استثناء يحتاج قراراً محكوماً ومراجعة أمنية قبل التنفيذ.

**The Direction Rule.** تستخدم logical properties وتختبر كل primitive في `dir="rtl"` و`dir="ltr"`. لا تستخدم أسهماً ثابتة الاتجاه أو `left/right` عندما يكون المعنى تابعاً لاتجاه اللغة.

## 2. Colors

لوحة باردة وواضحة مستمدة من المرجع، لكن بدرجات داكنة تحقق WCAG 2.2 AA بدلاً من السماوي الفاتح منخفض التباين. اللون لا يحمل المعنى منفرداً؛ يرافقه نص أو رمز أو نمط خط.

### Primary

- **Operational Teal** (`primary`): للإجراء الأساسي، التحديد الحالي، والروابط المهمة فقط. الأبيض عليه يحقق تباين `5.86:1`.
- **Deep Operational Teal** (`primary-hover`): لحالات hover وactive. الأبيض عليه يحقق `7.70:1`.

### Secondary

- **Dashboard Cyan** (`accent`): لفواصل الأقسام والنقاط والمؤشرات الثانوية فقط. لا
  يستخدم لنص عادي أو زر أساسي لأن تباينه على الأبيض غير كافٍ لهذا الدور.

### Tertiary

- **Success Green** (`success`): نجاح مكتمل أو حالة سليمة فقط؛ ليس سلسلة رسم عامة.
- **Warning Ochre** (`warning`): تحذير يحتاج انتباهاً ولا يعني فشلاً.
- **Danger Red** (`danger`): خطأ أو إجراء هدّام فقط.

### Neutral

- **Quiet Canvas** (`canvas`): خلفية التطبيق الفاتحة.
- **Clear Surface** (`surface`): الألواح والقوائم والحقول.
- **Operational Ink** (`ink`): النص والعناوين؛ تباينه على canvas هو `13.40:1`.
- **Readable Muted** (`muted`): النص الثانوي؛ تباينه على canvas هو `5.51:1`.
- **Structural Border** (`border`): فواصل وحدود هادئة، وليست بديلاً عن التباعد.
- **Night Canvas / Surface** (`dark-canvas`, `dark-surface`): طبقتا الوضع الداكن. لا تترك مناطق بيضاء بين المحتوى والتذييل.
- **Night Muted** (`dark-muted`): نص ثانوي يحقق `9.09:1` على dark canvas.

**The Ten Percent Rule.** لا يتجاوز اللون الرئيسي نحو 10% من الشاشة، ويقتصر على الأفعال والتحديد والحالة.

**The Redundant Signal Rule.** كل حالة أو سلسلة رسم تميّز بلون ونص، وتستخدم dash أو marker إضافياً عند المقارنة. يمنع الاعتماد على اللون وحده.

**The Contrast Gate.** النص العادي يحقق `4.5:1` على الأقل، والنص الكبير وعناصر الرسم المهمة تحقق `3:1` على الأقل. لا يقبل token جديد قبل قياس تباينه في الثيم الفاتح والداكن.

## 3. Typography

**Display Font:** IBM Plex Sans Arabic (with Tahoma and Arial fallbacks)

**Body Font:** IBM Plex Sans Arabic (with Tahoma and Arial fallbacks)

**Label/Number Font:** IBM Plex Sans Arabic with `font-variant-numeric: tabular-nums`

**Character:** عائلة واحدة هادئة وواضحة تمنع اختلاف لهجات الواجهة. تستخدم الأوزان والمقاسات لبناء الهرمية، ولا يضاف خط display أو mono لمجرد الزينة.

توزع ملفات IBM Plex Sans Arabic محلياً داخل bundle، ونسخة الحزمة المقترحة `@fontsource/ibm-plex-sans-arabic` بترخيص `OFL-1.1`. يجب حفظ نص الترخيص مع التوزيع. لا تعدل ملفات الخط تحت اسم محجوز، ولا يباع الخط منفرداً.

### Hierarchy

- **Display** (700, `2rem`, `1.25`): عنوان صفحة واحد فقط.
- **Headline** (700, `1.5rem`, `1.3`): عناوين المناطق الكبرى.
- **Title** (600, `1.125rem`, `1.4`): عنوان لوحة أو مجموعة.
- **Body** (400, `1rem`, `1.6`): النص التشغيلي والمساعدة، بحد قراءة `65-75ch` للنصوص الطويلة.
- **Label** (600, `0.875rem`, `1.4`): الأزرار والتسميات والحالات.
- **Meta** (400, `0.8125rem`, `1.4`): الوقت والمصدر والفترة، ولا يستخدم للمعلومة الحرجة.

**The Fixed Product Scale Rule.** تستخدم الواجهة مقاسات `rem` ثابتة لا `clamp()` للعناوين التشغيلية. الاستجابة تعيد ترتيب البنية بدلاً من تصغير النص حتى يفقد وضوحه.

**The One Numeral Policy.** تعرض الأرقام وفق locale الحالي في كل المؤشرات والمحاور والنسب. يمنع خلط الأرقام العربية والغربية داخل اللوحة نفسها.

## 4. Elevation

النظام مسطح افتراضياً. التباعد، اختلاف طبقة السطح، والحدود الهادئة تبني البنية. الظلال ليست زخرفة ولا تستخدم على كل بطاقة؛ تظهر فقط عندما يعلو عنصر مؤقتاً مثل قائمة أو dialog.

### Shadow Vocabulary

- **Resting Surface** (`none`): الألواح والبطاقات في الحالة العادية.
- **Floating Control** (`0 6px 22px rgb(11 47 58 / 12%)`): قائمة أو popover أو
  بطاقة الدخول المركزة التي طلب المالك إبرازها عن canvas.
- **Dialog** (`0 16px 48px rgb(7 31 43 / 24%)`): dialog فوق backdrop واضح.

الحركة تنقل الحالة خلال `150-250ms` باستخدام ease-out. يمنع تحريك `width`, `height`, `margin`, أو `padding` عندما يمكن استخدام transform أو opacity. كل حركة لها بديل فوري تحت `prefers-reduced-motion`.

**The Flat-By-Default Rule.** إذا لم يتغير مستوى العنصر وظيفياً فلا يستحق ظلاً.

**The No Glass Rule.** لا backdrop blur أو glassmorphism زخرفي في shell أو البطاقات. الشفافية لا تكون بديلاً عن هرمية واضحة.

## 5. Components

توجد primitives العامة داخل `apps/web/src/components/ui` فقط. كل مكون يملك API صغيراً، ويدعم العربية والإنجليزية، ويوثق الحالات التالية حيث تنطبق: default، hover، focus، active، selected، disabled، loading، empty، error، stale، وrestricted.

### Buttons

- **Shape:** انحناء موحد وحديث لعناصر التحكم (`12px`).
- **Primary:** `primary` مع `on-color`، ارتفاع `44px`، ومساحة داخلية `10px 16px`.
- **Hover / Focus:** `primary-hover` للـhover، وحلقة focus موحدة بعرض `3px` لا تعتمد على اللون وحده.
- **Secondary:** surface مع نص ink وحد border. لا ينشأ زر ghost ثالث إلا لحاجة مثبتة.
- **Icon Button:** `44x44px`، وله accessible name وtooltip عند غياب النص المرئي.
- **Loading:** يحتفظ بعرضه، يعطل التكرار، ويعلن الحالة للقارئ الآلي.

### Chips

- تستخدم للفلاتر أو الحالات القصيرة فقط، لا كزينة.
- الحد الأدنى للهدف التفاعلي `44px` عندما تكون قابلة للنقر.
- كل حالة تحمل نصاً واضحاً، ولا تستخدم نقطة ملونة وحدها.

### Cards / Containers

- **Corner Style:** `16px` موحد للبطاقات والأسطح، و`12px` للعناصر الداخلية.
- **Background:** surface على canvas، أو dark-surface على dark-canvas.
- **Shadow Strategy:** بلا ظل في السكون.
- **Border:** `1px` من border عند الحاجة للفصل.
- **Internal Padding:** `16px` للمؤشر الصغير و`24px` للوحة التحليل.
- لا بطاقات داخل بطاقات، ولا شبكة بطاقات متطابقة عندما لا تكون العناصر من النوع نفسه.

### Inputs / Fields

- ارتفاع `44px` على الأقل، label مرئي، وhelp/error مرتبطان بـ`aria-describedby`.
- تستخدم الحقول إشعاع focus خفيفاً بلا outline أزرق صلب بطلب المالك، بينما تحتفظ
  الأزرار والروابط بحلقة outline أوضح؛ كلاهما معرف عبر tokens موحدة.
- focus موحد، والخطأ يظهر قرب الحقل مع ملخص عند النماذج الطويلة.
- placeholder لا يحل محل label، ويحقق التباين المطلوب.

### Navigation

- Shell واحد للتنقل العلوي والجانبي. يظهر الموقع الحالي بنص وحالة `aria-current`.
- القائمة الجانبية المتنقلة dialog حقيقي: زر إغلاق ظاهر، focus trap، background inert، Escape، واستعادة focus.
- العناصر المغلقة أو خارج الشاشة تزال من tab order.
- لا تتجاوز الوجهة الواحدة خمسة خيارات عليا قبل التجميع أو progressive disclosure.

### Icons

- المكتبة الوحيدة هي `lucide-react`، مضافة إلى lockfile ومضمنة في Vite bundle؛ يمنع UMD وCDN و`data-lucide` runtime replacement.
- تستورد الأيقونات المطلوبة بأسماء صريحة لدعم tree-shaking. الأحجام المعتمدة `16`, `20`, `24px`، و`strokeWidth={1.75}`.
- لا تخلط Lucide مع emoji أو icon fonts أو مكتبة SVG ثانية.
- الأيقونة الزخرفية `aria-hidden="true"`. الأيقونة التي تنفذ فعلاً تكون داخل زر مسمى.
- الأيقونات الاتجاهية تعكس حسب RTL/LTR عندما يتغير معناها، بينما الأيقونات المحايدة لا تعكس.
- `lucide-react` ترخيصه `ISC`; يجب حفظ copyright ونص الترخيص في ملف إشعارات التوزيع.

### Dashboard Indicators

- primitives الوحيدة للمؤشرات: `MetricTile`, `StatusBadge`, `ProgressBar`, `ChartLegend`, `ChartTooltip`, و`DataFreshness`.
- لا تعرض الصفحة أكثر من أربع `MetricTile` رئيسية فوق الطية. تنقل المؤشرات الثانوية إلى مجموعة لاحقة أو progressive disclosure.
- كل مؤشر يحدد: الاسم، الوحدة، الفترة، وقت التحديث، المصدر، ومعنى zero/empty/unavailable.
- الصفر قيمة حقيقية؛ empty غياب سجل؛ unavailable نقص صلاحية أو مصدر. لا تتشابه بصرياً أو نصياً.
- النسبة تعرض البسط والمقام عندما يؤثران على القرار. يمنع عرض `46.2%` بجانب `5/6` إذا لم يكونا القياس نفسه.

### Charts

- مكتبة الرسوم الافتراضية والوحيدة هي Apache ECharts، وتستخدم خلف مكون React داخلي موحد مثل `DashboardChart` حتى لا تتسرب خيارات المكتبة إلى موديولات الأعمال.
- تثبت ECharts في lockfile وتضمن داخل Vite bundle؛ يمنع تحميلها من CDN أو استخدامها عبر API خارجي.
- تستخدم imports انتقائية من `echarts/core` مع أنواع الرسوم والمكونات المطلوبة فقط، ويكون `SVGRenderer` هو renderer الافتراضي. يمنع استيراد الحزمة الكاملة من `echarts` من دون قياس bundle ومبرر موثق.
- تفعل `AriaComponent` و`aria.show` وdecal patterns حيث تلزم، لكن ARIA المولد لا يغني عن جدول أو ملخص نصي مكافئ.
- كل سلسلة لها color + dash + marker فريد، والـlegend يستخدم الرمز نفسه.
- يتوفر عنوان، وصف، فترة، وحدة، freshness، empty/loading/error/stale، وجدول أو ملخص نصي مكافئ للقارئ الآلي ولوحة المفاتيح.
- tooltip لا يكون الطريق الوحيد للقيمة، ويعمل بالتركيز واللمس إضافة إلى hover.
- الجوال لا يصغر الرسم حتى تصبح المحاور غير مقروءة؛ يستخدم ملخصاً مخصصاً أو plot بحد ارتفاع `220px` وتمرير داخلي مضبوط.
- Apache ECharts ترخيصه `Apache-2.0`; يجب حفظ LICENSE وأي NOTICE موزع، وتوثيق أي تعديل محلي على المصدر.

### Runtime Assets and Network

- الخطوط والأيقونات والشعار والصور وCSS وJavaScript ملفات محلية مضمّنة في build أو صورة النشر.
- Apache ECharts وLucide والخطوط حزم بناء محلية داخل bundle ولا تنفذ network request أو telemetry أو update check وقت التشغيل.
- يمنع `fonts.googleapis.com`, `fonts.gstatic.com`, `unpkg.com`, وأي CDN أو script عام.
- API الداخلي يستخدم مسارات same-origin مثل `/api/...` خلف Caddy/nginx. يمنع component أو library من الاتصال بعنوان خارجي مباشرة.
- سياسة CSP المستهدفة تبدأ بـ`default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'`.
- أي حزمة جديدة تمر بمراجعة: الحاجة، حجم bundle، الترخيص، lockfile، الثغرات، network calls، telemetry، وبديل الإزالة.

**The Complete State Rule.** primitive بلا حالات loading/error/disabled/focus المناسبة غير مكتمل ولا يستخدم في feature.

**The No Silent Network Rule.** يمنع fallback إلى CDN أو telemetry أو update check أو remote asset. فشل الأصل المحلي يظهر في البناء أو الاختبار، لا يختبئ خلف اتصال عام.

## 6. Do's and Don'ts

### Do:

- **Do** استخدم tokens هذا الملف ومكونات `apps/web/src/components/ui` لكل الموديولات.
- **Do** استخدم `lucide-react` فقط للأيقونات، مع imports صريحة وحفظ إشعار ترخيص ISC.
- **Do** استخدم Apache ECharts عبر `DashboardChart` فقط، مع `SVGRenderer` وimports انتقائية وحفظ متطلبات Apache-2.0.
- **Do** حمّل IBM Plex Sans Arabic داخل bundle واحفظ ترخيص OFL-1.1.
- **Do** اختبر كل شاشة بالعربية RTL والإنجليزية LTR عند desktop وtablet وmobile وzoom 200%.
- **Do** اجعل أهداف اللمس الأساسية `44x44px` على الأقل، والروابط المضمنة `24x24px` على الأقل.
- **Do** وفر جدولاً أو ملخصاً نصياً لكل رسم، وميز السلاسل باللون والنمط والmarker.
- **Do** اعرض freshness والفترة والوحدة ومعنى الصفر لكل مؤشر قرار.
- **Do** استخدم same-origin API، وطبّق CSP ويفشل الاختبار عند وجود URL عام في runtime bundle أو HTML.
- **Do** راجع ترخيص وثغرات وسلوك الشبكة لأي حزمة قبل إضافتها، وأبلغ المالك بأي قيد قبل الاعتماد.

### Don't:

- **Don't** تعتمد على CDN أو Google Fonts أو unpkg أو remote image أو API عام وقت التشغيل.
- **Don't** تسمح لمكتبة UI أو icon أو chart بإرسال telemetry أو check للتحديث أو fetch خارجي.
- **Don't** تضف مكتبة رسوم ثانية أو تستعمل ECharts مباشرة داخل موديول أعمال متجاوزاً المكون الموحد.
- **Don't** تنشئ مكتبة مكونات أو tokens أو icon set مختلفة داخل موديول أعمال.
- **Don't** تستخدم icon font أو emoji بجانب Lucide كجزء من لغة المنتج.
- **Don't** تعرض أكثر من أربع مؤشرات رئيسية متساوية الوزن فوق الطية.
- **Don't** تخلط الصفر مع empty أو unavailable، أو تعرض أرقاماً متناقضة بين KPI والرسم والقائمة.
- **Don't** تعتمد على اللون وحده أو hover وحده لنقل قيمة أو حالة.
- **Don't** تستخدم `border-left` أو `border-right` أكبر من `1px` كشريط accent على بطاقة أو تنبيه.
- **Don't** تستخدم gradient text أو glassmorphism أو nested cards أو زخارف لا تخدم قرار المستخدم.
- **Don't** تترك drawer مغلقاً داخل tab order، أو drawer مفتوحاً بلا focus trap وزر إغلاق.
- **Don't** تضف حزمة أو أصلاً قبل توثيق ترخيصه والقيود الواجبة في التوزيع.
