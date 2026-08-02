# Cluster Local Coding Gateway

بوابة محلية متوافقة مع OpenAI API توضع أمام أي مزود أو Router متوافق. في النسخة الأولى تقوم بحقن سياق المشروع الثابت في كل طلب:

- `AGENTS.md`
- `.ai/NORTH_STAR.md`
- `.ai/CURRENT_STATE.md`
- `.ai/CONSTRAINTS.md`
- `.ai/DEFINITION_OF_DONE.md`

## لماذا المجلد هنا؟

المجلد `tools/coding-gateway` جزء من أدوات تطوير المستودع، وليس جزءًا من تطبيق Cluster التشغيلي. لذلك لا يوضع داخل `apps/api` أو `apps/web` ولا يدخل في نشر المنصة إلا بقرار مستقل.

## التشغيل

من جذر المستودع:

```bash
cd tools/coding-gateway
python3 -m venv .venv
source .venv/bin/activate
pip install -e .
```

حدد الـupstream. يمكن أن يكون LiteLLM أو OpenRouter proxy أو Ollama/OpenAI-compatible server:

```bash
export CLUSTER_GATEWAY_UPSTREAM_BASE_URL=http://127.0.0.1:4000
export CLUSTER_GATEWAY_UPSTREAM_API_KEY=your-key-if-needed
cluster-coding-gateway
```

اختبار الصحة:

```bash
curl http://127.0.0.1:9000/health
```

ثم وجّه OpenCode إلى:

```text
http://127.0.0.1:9000/v1
```

## حدود النسخة الأولى

هذه النسخة تحقن الرؤية والقواعد فقط. لا تنفذ بعد:

- اختيار الملفات المرتبطة تلقائيًا.
- توليد Scope contract لكل مهمة.
- منع تعديل ملفات فعلية.
- تشغيل الاختبارات بعد كل Diff.
- ذاكرة القرارات والتصحيحات.

هذه تضاف في المرحلة الثانية بعد التحقق من عمل مسار OpenCode → Gateway → Model.
