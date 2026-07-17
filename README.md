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
على Staging ولا تمثّل هدف قبول W1.1 النهائي قبل اكتمال بقية مهام الموجة.
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
أو التوقيع أو نشر Dokploy الحي؛ هذه مخرجات المهام التالية. توجد عقود وأدوات تحقق محلية
للمهام SC-03 وNET-04 وDEP-05 وDR-06 وGATE-07، لكنها لا تحول الدليل المحلي إلى قبول حي.

### تحقق سلسلة التوريد (W11-SC-03)

السطح المحلي موجود في `scripts/release_descriptor.py` و
`infra/platform/release/release-descriptor.schema.json` واختبارات `tests/ops/`. شغّل:

```sh
make verify-w11-sc-03-local
```

أو أعد التحقق من descriptor صادر عن CI، مع إبقاء جذر artifacts خارج Git عند الحاجة:

```sh
COSIGN_BINARY="$(command -v cosign)" \
COSIGN_VERSION="v2.4.3" \
COSIGN_PUBLIC_KEY=/secure/keys/cosign.pub \
RELEASE_DESCRIPTOR=/secure/release/release-descriptor.json \
RELEASE_ROOT=/secure/release-root \
make verify-build
```

هذا يثبت البنية والhashes محلياً فقط. يلزم workflow GitHub Actions حية على revision واحد، وصور
أدوات وDocker DinD مثبتة بالـdigest، وregistry credentials وCOSIGN_PRIVATE_KEY / COSIGN_PUBLIC_KEY
محجوبة حسب بيئات GitHub المحمية، ثم artifacts
SBOM وprovenance وsignature وbundle قابلة لإعادة التحقق. hashes خطط migration/rollback بعد
remediation تُربط بالdescriptor؛ تنفيذها الحي وسجلها يثبتان في DEP-05.

### تحقق الشبكة والمضيف (W11-NET-04)

توجد policy وverifier في `infra/platform/network/` و`scripts/net04_network_policy.py`.
الأمر التالي مباشر وقراءة فقط، لكنه يحتاج policy وCompose وقيم endpoints/CIDRs حقيقية خارج Git:

```sh
export W11_REVISION="$(git rev-parse HEAD)"

python3 scripts/net04_network_policy.py verify-host \
  --policy /secure/cluster/net-policy.json \
  --compose infra/platform/production/compose.yaml \
  --receipt /secure/evidence/net-host.json \
  --revision "$W11_REVISION"
python3 scripts/net04_network_policy.py verify-edge \
  --policy /secure/cluster/net-policy.json --perspective user \
  --receipt /secure/evidence/net-user.json \
  --revision "$W11_REVISION"
python3 scripts/net04_network_policy.py verify-edge \
  --policy /secure/cluster/net-policy.json --perspective management \
  --receipt /secure/evidence/net-management.json \
  --revision "$W11_REVISION"
```

فحص OPS-01 الحي يظل عبر `make preflight-w11-ops-01-live` مع `HOST_INPUTS` و`HOST_RECEIPT`
خارج Git، وأسماء الأسرار/اعتمادات القراءة فقط في مدير الأسرار؛ لا توضع القيم في receipt.
مثال policy الموجود في المستودع placeholder وليس دليلاً لمضيف حي.

### أدلة Dokploy والنسخ والاستعادة (W11-DEP-05 وW11-DR-06)

تتحقق `scripts/deployment_evidence.py` من evidence ملتقط فعلياً لمسار N→N+1→rollback N،
وتتحقق `scripts/backup_restore_evidence.py` من manifest مشفر وrestore على target مستقل وقياس
RPO/RTO. لا تنفذ الأداتان نشرًا أو backup أو restore؛ يجب أن تأتي الأدلة من Dokploy/Staging
وهدف الاستعادة، ثم تشغّل مباشرة بملفات خارج Git:

```sh
python3 scripts/deployment_evidence.py \
  --evidence /secure/evidence/dokploy-n-n1-rollback.json \
  --evidence-root /secure/evidence \
  --receipt /secure/evidence/dokploy-receipt.json
python3 scripts/backup_restore_evidence.py \
  --manifest /secure/evidence/backup-manifest.json \
  --restore /secure/evidence/restore-receipt.json \
  --receipt /secure/evidence/dr-receipt.json \
  --artifact-root /secure/backup-artifacts \
  --evidence-root /secure/evidence \
  --artifact-path backup.enc \
  --signature-path backup.enc.sig \
  --bundle-path backup.enc.bundle \
  --public-key backup-evidence.pub \
  --cosign-binary "$(command -v cosign)" \
  --cosign-sha256 ... \
  --cosign-version "v2.4.3" \
  --as-of "2026-07-17T00:00:00Z"
```

ينشئ تشغيل DEP-05 العادي أعلاه receipt غير موقع مرتبطاً بمحتوى evidence الملتقط. أما
`--dry-run` فلا يقرأ evidence ولا يتحقق منه؛ يكتب خطة دنيا غير مقبولة للبوابة ويستخدم
للتخطيط فقط، لا كدليل نشر أو rollback.

يلزم أن تثبت الأدلة revision وimage/Compose digests، migration compatibility وpre-backup،
health ورحلتي `ar` و`en`، checksum/signature والتشفير والهدف المنفصل، دون أسرار أو بيانات
حقيقية في Git. لا توجد حالياً نتيجة Dokploy أو تمرين restore حي.

### بوابة قبول W1.1 (W11-GATE-07)

يجمع `scripts/w1_1_acceptance_gate.py` الأدلة offline ويرفض receipt ناقصاً أو قديماً أو
محلياً أو غير متطابق revision. المسار الموحد هو:

- `make verify-w1-1-all` مع المتغيرات المطلوبة في Makefile (عبر `check-w1-1-live-inputs`).

```sh
export HOST_INPUTS=/secure/outside-git/host.json
export HOST_RECEIPT=/secure/evidence/host-preflight.json
export NET04_POLICY=/secure/cluster/net-policy.json
export NET04_COMPOSE=infra/platform/production/compose.yaml
export NET04_HOST_RECEIPT=/secure/evidence/net-host.json
export NET04_USER_RECEIPT=/secure/evidence/net-user.json
export NET04_MANAGEMENT_RECEIPT=/secure/evidence/net-management.json
export NET04_REVISION="$(git rev-parse HEAD)"
export GATE_MANIFEST=/secure/evidence/w1-1-gate.json
export GATE_TRUST_POLICY=/secure/policies/w1-1-trust-policy.json
export GATE_RELEASE_ROOT=/secure/release-root
export GATE_EVIDENCE_ROOT=/secure/evidence
export GATE_RECEIPT=/secure/evidence/w1-1-gate-receipt.json
export COSIGN_BINARY="$(command -v cosign)"
export COSIGN_SHA256=...
export COSIGN_VERSION="v2.4.3"
export GATE_AS_OF="2026-07-17T00:00:00Z"
export RELEASE_DESCRIPTOR=/secure/release/release-descriptor.json
export RELEASE_ROOT=/secure/release-root
export COSIGN_PUBLIC_KEY=/secure/keys/cosign.pub

make verify-w1-1-all
```

يتطلب manifest نتائج `TEST-R1-W1.1-01` حتى `TEST-R1-W1.1-08` على Git revision واحد،
receipts حية للمضيف والشبكة وDokploy والاستعادة، freshness و`as_of`، وموافقات Go المسماة
من قائد التقنية وSRE وأمن المعلومات بعد اكتمال البوابات الآلية. حتى تكتمل هذه المدخلات
تبقى W1.1 `implemented-local / blocked-external` وليست مكتملة.

## السرية

المحتوى داخلي ومملوك. لا تضف أسراراً أو بيانات شخصية أو بيانات تشغيلية حقيقية إلى الوثائق أو الأمثلة أو سجل Git.

## المساهمة

اقرأ `CONTRIBUTING.md` و`SECURITY.md` قبل التغيير.
