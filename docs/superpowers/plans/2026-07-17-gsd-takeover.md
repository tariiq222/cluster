---
doc_id: PLN-TP-001
title: خطة تنفيذ استلام Codex للعمل وإزالة GSD
type: plans
status: proposed
version: 1.0.0
date: 2026-07-17
owner: مكتب هندسة المنصة
reviewers:
- مسؤول هندسة البرمجيات
- قائد التقنية
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/superpowers/specs/2026-07-17-gsd-takeover-design.md
references:
- docs/plans/implementation-roadmap.md
- docs/governance/document-control.md
---
# GSD Takeover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** نقل الحالة المفيدة إلى مصدر مستقل، وإزالة جميع مكونات GSD التشغيلية ومخرجاتها من المستودع، مع إبقاء OpenCode و`model-swarm` والعمل الجاري دون تغيير.

**Architecture:** تبقى `docs/` مصدر قرارات وخطط المشروع، ويصبح الكود والاختبارات وGit دليل التنفيذ بدلاً من ملفات حالة مولدة. تُنظف نقاط الربط المشتركة أولاً، ثم تحذف المكونات المملوكة للأداة و`.planning/` بعد إنشاء وثيقة تسليم والتحقق منها.

**Tech Stack:** Git، Markdown/YAML، Bash، Node.js، OpenCode plugin configuration، مدقق الوثائق المحلي.

## Global Constraints

- النطاق هو `/Users/tariq/code/R3/cluster` فقط؛ لا حذف من إعدادات المستخدم العامة.
- لا يتغير أي ملف منتج تحت `apps/` أو `infra/` أو سكربت مشروع تحت `scripts/` أو `Makefile`.
- تبقى تغييرات المستخدم غير الملتزمة محفوظة، ولا تستخدم أوامر استعادة أو إعادة ضبط مدمرة.
- تبقى `.opencode/plugins/model-swarm.ts` و`.opencode/instructions/model-swarm.md` وحزمة `@opencode-ai/plugin`، ويقتصر تعديلها على إزالة إحالة GSD.
- لا تُنقل نسب الإنجاز من `.planning/`؛ كل ادعاء حالة يعتمد على كود أو اختبار أو التزام أو وثيقة محكومة.
- لا تُشغّل اختبارات المنتج الواسعة لأن العمل لا يغير سلوك المنتج؛ يقتصر التحقق على الوثائق والإعدادات وبصمة ملفات المنتج.
- لا تُنشأ التزامات تنفيذ تضم تغييرات سابقة للمستخدم؛ تبقى نتيجة التنظيف في شجرة العمل للمراجعة ما لم يطلب المستخدم الحفظ في Git.

---

## File Map

| الإجراء | المسار | المسؤولية |
| --- | --- | --- |
| Create | `docs/plans/active-delivery-status.md` | حالة التسليم المستقلة والأدلة والخطوة التالية |
| Modify | `docs/plans/README.md` | إدراج حالة التسليم في فهرس الخطط |
| Modify | `docs/catalog.yaml` | تسجيل وثيقة حالة التسليم وخطة التنفيذ |
| Modify | `mkdocs.yml` | نشر وثيقة حالة التسليم وخطة التنفيذ |
| Replace | `AGENTS.md` | تعليمات مشروع حالية ومستقلة |
| Modify | `.opencode/opencode.json` | إزالة MCP الخاص بـGSD مع إبقاء تعليمات model-swarm |
| Modify | `.opencode/instructions/model-swarm.md` | إزالة فرض workflow قديم |
| Modify | `.opencode/agents/tui.md` | إزالة عقد العرض المرتبط بمرجع محذوف |
| Delete | `.opencode/gsd-core/` | وقت تشغيل GSD |
| Delete | `.opencode/command/` | أوامر GSD |
| Delete | `.opencode/skills/` | مهارات GSD المحلية |
| Delete | `.opencode/hooks/` | hooks ومكتباتها المملوكة لـGSD |
| Delete | `.opencode/scripts/` | سكربتات التثبيت وMCP المملوكة لـGSD |
| Delete | `.opencode/agents/gsd-*.md` | وكلاء GSD |
| Delete | `.opencode/plugins/gsd-core.js` | plugin الخاص بـGSD |
| Delete | `.opencode/.gsd-profile` | تعريف التثبيت |
| Delete | `.opencode/gsd-file-manifest.json` | بيان ملفات التثبيت |
| Delete | `.opencode/gsd-install-state.json` | حالة ترحيلات التثبيت |
| Delete | `.planning/` | حالة وخطط ومخرجات GSD بعد النقل |

### Task 1: Preserve a Baseline and Create the Independent Handoff

**Files:**

- Create: `docs/plans/active-delivery-status.md`
- Modify: `docs/plans/README.md`
- Modify: `docs/catalog.yaml`
- Modify: `mkdocs.yml`

**Interfaces:**

- Consumes: `docs/plans/implementation-roadmap.md`، `docs/plans/release-1-platform.md`، Git history، وحالة الشجرة الحالية.
- Produces: وثيقة `PLN-AS-001` التي تسمح بحذف `.planning/` دون فقد الحالة الفريدة.

- [x] **Step 1: Capture the pre-cleanup evidence outside the workspace**

```bash
git status --short > /tmp/cluster-gsd-takeover-status.before
find apps infra scripts -type f \
  -not -path '*/vendor/*' \
  -not -path '*/node_modules/*' \
  -not -path '*/storage/*' \
  -not -path '*/dist/*' \
  -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256 \
  > /tmp/cluster-gsd-takeover-product.before
shasum -a 256 Makefile >> /tmp/cluster-gsd-takeover-product.before
wc -l /tmp/cluster-gsd-takeover-status.before
cat /tmp/cluster-gsd-takeover-product.before
```

Expected: ملف الحالة يحتوي تغييرات المشروع الحالية، وملف البصمة يحتوي سطرين SHA-256.

- [x] **Step 2: Create the independent delivery-status document**

Create `docs/plans/active-delivery-status.md` with this complete content:

```markdown
---
doc_id: PLN-AS-001
title: حالة التسليم النشطة
type: plans
status: proposed
version: 1.0.0
date: 2026-07-17
owner: مكتب هندسة المنصة
reviewers:
- مسؤول هندسة البرمجيات
- قائد التقنية
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-1-platform.md
references:
- docs/architecture/overview.md
---
# حالة التسليم النشطة

## الغرض

هذه الوثيقة هي سجل الحالة التنفيذية النشطة بعد فصل أدوات التخطيط عن المشروع. خارطة التنفيذ
المعتمدة تبقى في `docs/plans/implementation-roadmap.md`، ويُثبت الإنجاز بالكود والاختبارات
والالتزامات والوثائق المحكومة، لا بنسبة مولدة.

## الموضع الحالي

- النطاق النشط: W1.1 Walking Skeleton ضمن R1.
- خط الأساس الملتزم: خطط التأسيس حتى 01-05 ظاهرة في تاريخ Git حتى الالتزام `43649bf`.
- العمل اللاحق موجود في شجرة عمل غير نظيفة ويشمل WorkRecords HTTP، Notifications،
  Outbox relay، واجهة React، واختبارات القبول؛ لا يُعلن اكتماله كوحدة واحدة قبل التحقق.
- قرار التشغيل المعتمد هو خادم داخلي واحد يديره Dokploy من Docker Compose مثبت وفق ADR-023.
- أعمال النشر والجدار الناري والرجوع والنسخ والاستعادة ليست دليلاً مكتملاً حتى توفر
  مدخلات المضيف وتجارب التنفيذ الفعلية.

## القرارات المحمولة إلى التنفيذ

- تطبيق المنتج من `apps/api` و`apps/web`، مع React واحد لكل الأدوار.
- `request` نوع WorkDefinition منشور وليس موديول أعمال مستقلاً.
- حفظ WorkRecord وOutbox event في معاملة واحدة، واستهلاك الأحداث idempotent.
- عزل المنشآت يعتمد على حقائق موثوقة وقرار خلفي موحد، ولا تمنح الواجهة صلاحية.
- لا يبدأ إصدار لاحق قبل بوابة الإصدار السابق إلا بقرار راعٍ مسجل.

## فجوات W1.1 المفتوحة

1. التحقق من رحلة التطبيق المحلية الحالية واختباراتها من الشجرة غير النظيفة.
2. تثبيت عقد مدخلات المضيف ومسار إدارة Dokploy.
3. تثبيت حزمة الإصدار وSBOM وprovenance وimage digest.
4. إثبات جدار المضيف والشبكات الداخلية وعدم نشر خدمات الحالة.
5. إثبات نشر Dokploy والرجوع إلى إصدار معروف بالصحة.
6. قياس النسخ والاستعادة على هدف منفصل وإعادة التحقق من بوابة W1.1.

## قاعدة تحديث الحالة

لا يُنقل بند إلى مكتمل إلا مع أمر تحقق ونتيجة أو التزام محدد أو وثيقة اعتماد. تبقى
الملاحظات غير المثبتة عملاً مفتوحاً، ولا تستخدم هذه الوثيقة نسبة تقدم إجمالية.

## الخطوة التالية

بعد اكتمال تنظيف أدوات التخطيط، تُراجع تغييرات W1.1 الحالية وتُشغل أضيق اختبارات تغطيها،
ثم يُستكمل العمل من أول فجوة غير مثبتة دون إعادة تنفيذ ما يثبت نجاحه.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-17 | مكتب هندسة المنصة | إنشاء سجل التسليم المستقل وحفظ حالة W1.1 القابلة للتحقق |
```

- [x] **Step 3: Register the handoff document in all documentation indexes**

Add a row to `docs/plans/README.md` using these exact values:

```markdown
Label: حالة التسليم النشطة
Target: active-delivery-status.md
Purpose: الحالة التنفيذية المثبتة والخطوة التالية
```

Add this entry to `docs/catalog.yaml` beside the other plan documents:

```yaml
  - {path: plans/active-delivery-status.md, title: حالة التسليم النشطة, category: plans, status: proposed, owner: مكتب هندسة المنصة, phase: R1-R3, source_of_truth: false, generated: false}
```

Add this entry under the plans navigation in `mkdocs.yml`:

```yaml
      - حالة التسليم النشطة: plans/active-delivery-status.md
```

- [x] **Step 4: Validate the handoff before deleting any source**

Run:

```bash
./scripts/validate-docs.sh
```

Expected: `Documentation validation passed.`

- [x] **Step 5: Review the migration-only diff**

Run:

```bash
git diff --check -- docs/plans/active-delivery-status.md docs/plans/README.md docs/catalog.yaml mkdocs.yml
git diff -- docs/plans/active-delivery-status.md docs/plans/README.md docs/catalog.yaml mkdocs.yml
```

Expected: لا أخطاء whitespace، والتغييرات المضافة تخص سجل التسليم وفهرسته فقط بجانب تغييرات المستخدم الموجودة.

### Task 2: Replace Generated Instructions and Decouple OpenCode

**Files:**

- Replace: `AGENTS.md`
- Modify: `.opencode/opencode.json`
- Modify: `.opencode/instructions/model-swarm.md`
- Modify: `.opencode/agents/tui.md`

**Interfaces:**

- Consumes: معمارية المشروع الحالية ومسارات التحقق في `Makefile`.
- Produces: تعليمات مستقلة وإعداد OpenCode لا يستدعي أي ملف سيحذف في Task 3.

- [x] **Step 1: Replace AGENTS.md with concise current instructions**

Replace `AGENTS.md` with:

```markdown
# Project Instructions

## Product

هذا المستودع يبني منصة التجمع الصحي الثالث كتطبيق Laravel modular monolith مع واجهة
React + TypeScript موحدة، عربية افتراضياً وتدعم الإنجليزية وRTL/LTR.

## Sources of Truth

- `docs/` هو مصدر القرارات والعقود والخطط المحكومة.
- الكود والاختبارات والـlockfiles هي دليل الحالة التنفيذية الفعلية.
- `docs/plans/active-delivery-status.md` يسجل العمل النشط والأدلة والخطوة التالية.
- لا تُعامل وثيقة هدف أو خطة على أنها تنفيذ مكتمل من دون دليل قابل للتشغيل.

## Architecture Boundaries

- يحظر الاستعلام أو join المباشر بين جداول موديولات الأعمال.
- التعاون بين الموديولات يكون عبر contracts وevents وIDs وread models محكومة.
- يطبق الخلف قرار RBAC + ABAC نفسه على API والبحث والتقارير والتصدير والتنزيل.
- يحفظ تغيير الأعمال وOutbox event في معاملة واحدة، ويكون المستهلك idempotent.
- تبقى السجلات الجارية مثبتة على إصدارات أنواع العمل والمسارات المنشورة.
- النشر المستهدف خادم داخلي واحد يديره Dokploy من Docker Compose مثبت.

## Work Safety

- افحص `git status` قبل التعديل واحفظ تغييرات المستخدم غير ذات الصلة.
- استخدم `apply_patch` للتعديلات النصية ولا تستخدم أوامر Git مدمرة.
- لا توسع النطاق خارج طلب المستخدم، ولا تلتزم أو تدفع تغييراته دون تفويض.
- استخدم `rg` و`rg --files` للبحث أولاً.

## Verification

- تغييرات الوثائق: `./scripts/validate-docs.sh`.
- حدود الموديولات: `make verify-boundaries`.
- API: ابدأ بأضيق اختبار Artisan متأثر ثم وسع عند الحاجة.
- الويب: `npm --prefix apps/web run build` ثم الاختبار المستهدف.
- لا تشغل suites واسعة لتغيير غير سلوكي ما لم يبرر الخطر ذلك.

## Local OpenCode Tooling

- `.opencode/plugins/model-swarm.ts` و`.opencode/instructions/model-swarm.md` أدوات تطوير محلية وليست جزءاً من المنتج.
- لا تجعل بناء المنتج أو تشغيله يعتمد على `.opencode/`.
```

- [x] **Step 2: Remove the GSD MCP from OpenCode configuration**

Replace `.opencode/opencode.json` with:

```json
{
  "$schema": "https://opencode.ai/config.json",
  "instructions": [
    ".opencode/instructions/model-swarm.md"
  ]
}
```

- [x] **Step 3: Remove the two residual workflow references without overwriting user edits**

In `.opencode/instructions/model-swarm.md`, replace only:

```markdown
- Follow `AGENTS.md` and the repository GSD workflow before any edit.
```

with:

```markdown
- Follow `AGENTS.md` before any edit.
```

Delete only the `<project_display_contract>...</project_display_contract>` block from `.opencode/agents/tui.md`.

- [x] **Step 4: Verify shared configuration no longer references the removable runtime**

Run:

```bash
node -e "const c=require('./.opencode/opencode.json'); if (c.mcp) process.exit(1); if (c.instructions?.[0]!=='.opencode/instructions/model-swarm.md') process.exit(2)"
rg -n -i 'gsd|gsd-core|/gsd-' AGENTS.md .opencode/opencode.json .opencode/instructions/model-swarm.md .opencode/agents/tui.md
```

Expected: Node exits `0` and `rg` produces no output.

- [x] **Step 5: Check the focused diff**

Run:

```bash
git diff --check -- AGENTS.md .opencode/opencode.json .opencode/instructions/model-swarm.md .opencode/agents/tui.md
```

Expected: no output and exit `0`.

### Task 3: Remove the GSD Runtime and Planning Tree

**Files:**

- Delete: `.opencode/gsd-core/`
- Delete: `.opencode/command/`
- Delete: `.opencode/skills/`
- Delete: `.opencode/hooks/`
- Delete: `.opencode/scripts/`
- Delete: `.opencode/agents/gsd-*.md`
- Delete: `.opencode/plugins/gsd-core.js`
- Delete: `.opencode/.gsd-profile`
- Delete: `.opencode/gsd-file-manifest.json`
- Delete: `.opencode/gsd-install-state.json`
- Delete: `.planning/`

**Interfaces:**

- Consumes: وثيقة التسليم الناجحة من Task 1 وإعداد OpenCode المفصول من Task 2.
- Produces: مستودع بلا وقت تشغيل أو حالة تخطيط تخص GSD.

- [x] **Step 1: Prove the broad directories contain only the agreed targets**

Run:

```bash
test -z "$(find .opencode/command -type f ! -name 'gsd-*' -print)"
test -z "$(find .opencode/skills -mindepth 1 -maxdepth 1 ! -name 'gsd-*' -print)"
test "$(find .opencode/agents -type f -name 'gsd-*' | wc -l | tr -d ' ')" -eq 34
test -f .opencode/agents/tui.md
test -f .opencode/plugins/model-swarm.ts
test -f docs/plans/active-delivery-status.md
```

Expected: all assertions pass; `tui.md` and `model-swarm.ts` are explicitly preserved.

- [x] **Step 2: Verify preserved OpenCode files have no dependency on deleted paths**

Run:

```bash
rg -n '\.opencode/(gsd-core|command|skills|hooks|scripts)|plugins/gsd-core' \
  .opencode/opencode.json \
  .opencode/instructions/model-swarm.md \
  .opencode/plugins/model-swarm.ts \
  .opencode/agents/tui.md
```

Expected: no output and exit `1` from `rg` because no match exists.

- [x] **Step 3: Delete the exact approved runtime targets**

Run:

```bash
rm -rf \
  .opencode/gsd-core \
  .opencode/command \
  .opencode/skills \
  .opencode/hooks \
  .opencode/scripts
rm -f \
  .opencode/agents/gsd-*.md \
  .opencode/plugins/gsd-core.js \
  .opencode/.gsd-profile \
  .opencode/gsd-file-manifest.json \
  .opencode/gsd-install-state.json
```

Expected: only the listed runtime paths disappear.

- [x] **Step 4: Verify the independent OpenCode allowlist survived**

Run:

```bash
test -f .opencode/plugins/model-swarm.ts
test -f .opencode/instructions/model-swarm.md
test -f .opencode/agents/tui.md
test -f .opencode/goals/state.json
test -f .opencode/package.json
test -f .opencode/package-lock.json
test -d .opencode/node_modules
```

Expected: all assertions pass.

- [x] **Step 5: Delete the planning tree only after the handoff is valid**

Run:

```bash
./scripts/validate-docs.sh
test -f docs/plans/active-delivery-status.md
rm -rf .planning
test ! -e .planning
```

Expected: documentation passes before deletion and `.planning` is absent afterward.

### Task 4: Verify the Takeover and Record the Result

**Files:**

- Modify: `docs/plans/active-delivery-status.md`

**Interfaces:**

- Consumes: cleaned repository from Task 3 and the baseline captured in Task 1.
- Produces: evidence that the takeover is complete and product files are unchanged.

- [x] **Step 1: Verify OpenCode configuration and its retained dependency**

Run:

```bash
node -e "const fs=require('node:fs'); const c=JSON.parse(fs.readFileSync('.opencode/opencode.json','utf8')); if ('mcp' in c) process.exit(1); if (!fs.existsSync('.opencode/plugins/model-swarm.ts')) process.exit(2)"
npm --prefix .opencode ls @opencode-ai/plugin --depth=0
```

Expected: Node exits `0` and npm lists `@opencode-ai/plugin@1.18.1`.

- [x] **Step 2: Prove no operational GSD residue remains**

Run:

```bash
test ! -e .planning
test -z "$(rg --files -uu .opencode -g '!.opencode/node_modules/**' | rg -i '(^|/)gsd')"
rg -n -i 'gsd|gsd-core|/gsd-' AGENTS.md .opencode \
  --glob '!.opencode/node_modules/**'
```

Expected: both assertions pass and the final `rg` produces no output.

- [x] **Step 3: Verify documentation and build it when the declared tool is available**

Run:

```bash
./scripts/validate-docs.sh
if python3 -c 'import mkdocs' >/dev/null 2>&1; then
  python3 -m mkdocs build --strict
else
  printf '%s\n' 'MkDocs unavailable locally; strict build remains the GitHub Actions gate.'
fi
```

Expected: custom validation passes; MkDocs either builds strictly or prints the explicit local-prerequisite message.

- [x] **Step 4: Prove product files did not change during cleanup**

Run:

```bash
find apps infra scripts -type f \
  -not -path '*/vendor/*' \
  -not -path '*/node_modules/*' \
  -not -path '*/storage/*' \
  -not -path '*/dist/*' \
  -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256 \
  > /tmp/cluster-gsd-takeover-product.after
shasum -a 256 Makefile >> /tmp/cluster-gsd-takeover-product.after
cmp /tmp/cluster-gsd-takeover-product.before /tmp/cluster-gsd-takeover-product.after
```

Expected: `cmp` exits `0` with no output.

- [x] **Step 5: Add the takeover evidence to the delivery-status document**

Append this section before `## سجل التغيير` in `docs/plans/active-delivery-status.md`:

```markdown
## استلام إدارة العمل

اكتمل في 2026-07-17 فصل أدوات التخطيط السابقة عن المستودع:

- أزيل وقت التشغيل والأوامر والمهارات والوكلاء وhooks وMCP المرتبطة به.
- حذفت شجرة الحالة الوسيطة بعد نقل الوضع والقرارات والفجوات القابلة للتحقق.
- بقي OpenCode و`model-swarm` مستقلين وقابلين للتحميل.
- اجتاز مدقق الوثائق، وطابقت بصمة ملفات المنتج لقطة ما قبل التنظيف.
```

- [x] **Step 6: Run the final static gate and display the preserved dirty state**

Run:

```bash
./scripts/validate-docs.sh
git diff --check
git status --short
du -sh .opencode
```

Expected: validation and diff checks pass; Git shows the pre-existing product changes plus the explicit takeover deletions/additions, and `.opencode` is materially smaller than 131 ميجابايت.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-17 | مكتب هندسة المنصة | إنشاء خطة تنفيذ تفصيلية لاستلام العمل وإزالة GSD |
