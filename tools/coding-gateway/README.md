# Cluster Local Coding Gateway

بوابة تطوير محلية متوافقة مع OpenAI API، توضع بين OpenCode وأي مزود أو Router متوافق. وظيفتها أن تجعل رؤية مشروع Cluster وقيوده وعقد المهمة الحالي جزءًا ثابتًا من كل طلب، بغض النظر عن النموذج المستخدم.

## مكان البوابة

المجلد `tools/coding-gateway` جزء من أدوات تطوير المستودع، وليس جزءًا من تطبيق Cluster التشغيلي. لذلك لا يوضع داخل `apps/api` أو `apps/web` ولا يدخل في نشر المنصة.

## ما تنفذه النسخة الحالية

- تمرير `GET /v1/models`.
- تمرير وحقن السياق في `POST /v1/chat/completions`.
- تمرير وحقن السياق في `POST /v1/responses`.
- دعم الاستجابات المتدفقة SSE.
- تحميل رؤية المشروع وقواعده من:
  - `AGENTS.md`
  - `PRODUCT.md`
  - `.ai/NORTH_STAR.md`
  - `.ai/CURRENT_STATE.md`
  - `.ai/CONSTRAINTS.md`
  - `.ai/DEFINITION_OF_DONE.md`
- تحميل عقد المهمة الاختياري من `.ai/runtime/current-task.json`.
- منع تكرار حقن السياق في الطلب نفسه.
- منع `required_context` من قراءة ملفات خارج جذر المستودع.

## التشغيل

من جذر المستودع:

```bash
cd tools/coding-gateway
python3 -m venv .venv
source .venv/bin/activate
pip install -e '.[dev]'
```

انسخ إعدادات البيئة:

```bash
cp .env.example .env
```

حمّل المتغيرات وشغل البوابة:

```bash
set -a
source .env
set +a
cluster-coding-gateway
```

اختبار الصحة:

```bash
curl http://127.0.0.1:9000/health
```

اختبار الحزمة:

```bash
pytest -q
```

## ربط OpenCode

انسخ إعداد المزود من `opencode.example.jsonc` إلى إعدادات OpenCode، واستبدل `YOUR_UPSTREAM_MODEL_ID` بمعرف النموذج الذي يعرضه upstream:

```bash
curl http://127.0.0.1:9000/v1/models
```

عنوان البوابة داخل OpenCode:

```text
http://127.0.0.1:9000/v1
```

عند إضافة بيانات الاعتماد استخدم provider id التالي:

```text
cluster-gateway
```

إذا كانت البوابة تحمل مفتاح upstream في `CLUSTER_GATEWAY_UPSTREAM_API_KEY` فيمكن أن تكون قيمة الاعتماد المحلية placeholder؛ المفتاح الحقيقي لا يوضع في مستودع المشروع.

## عقد المهمة الحالي

أنشئ عقدًا من القالب قبل مهمة واسعة أو عالية الخطورة:

```bash
mkdir -p .ai/runtime
cp .ai/CURRENT_TASK.example.json .ai/runtime/current-task.json
```

ثم حدّث:

- `mode`: واحدة من `plan` أو `implement` أو `review`.
- `objective`: نتيجة واحدة محددة.
- `acceptance_criteria`: شروط قابلة للتحقق.
- `allowed_paths`: النطاق المسموح تعديله.
- `forbidden_paths`: النطاق الممنوع.
- `required_context`: الملفات الإضافية المطلوبة لهذه المهمة فقط.
- `verification_commands`: أوامر التحقق الملزمة.
- `risk`: واحدة من `low` أو `medium` أو `high` أو `critical`.

الملف داخل `.ai/runtime/` محلي وغير متتبع في Git، حتى لا تنتقل مهمة قديمة تلقائيًا إلى مهمة جديدة.

لتعطيل التنفيذ دون عقد مهمة:

```bash
export CLUSTER_GATEWAY_REQUIRE_TASK_CONTRACT=true
```

في هذه الحالة سترفض البوابة الطلب إذا لم يوجد عقد صالح.

## الـupstream

يمكن أن يكون:

- LiteLLM.
- OpenRouter أو أي Proxy متوافق مع OpenAI API.
- Ollama أو LM Studio أو خادم نموذج محلي متوافق.

مثال:

```bash
export CLUSTER_GATEWAY_UPSTREAM_BASE_URL=http://127.0.0.1:4000
export CLUSTER_GATEWAY_UPSTREAM_API_KEY='...'
cluster-coding-gateway
```

## حدود النسخة الحالية

هذه النسخة تضبط السياق والعقد قبل إرسال الطلب، لكنها لا تراقب أدوات OpenCode بعد بدء التنفيذ. لا تقوم بعد بـ:

- فهرسة Symbol graph للمستودع.
- اختيار الملفات المرتبطة تلقائيًا.
- منع الكتابة فعليًا خارج `allowed_paths`.
- تشغيل اختبارات تلقائيًا بعد كل diff.
- توجيه Planner/Executor/Reviewer بين نماذج مختلفة.
- حفظ ذاكرة التصحيحات والقرارات من جلسات التنفيذ.

هذه الوظائف تضاف بعد إثبات مسار OpenCode → Gateway → Upstream على جهاز التطوير.
