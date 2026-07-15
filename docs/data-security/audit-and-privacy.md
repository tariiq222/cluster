---
doc_id: SEC-AU-001
title: التدقيق والخصوصية
type: data-security
status: draft
version: 0.2.0
date: 2026-07-15
owner: مسؤول أمن المعلومات
reviewers:
- مكتب هندسة المنصة
- مسؤول العمليات
classification: internal
review_cycle: نصف سنوي
sources: []
references:
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/016-audit-and-records-governance.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/domain/audit.md
- docs/data-security/logical-data-model.md
- docs/data-security/threat-model.md
- docs/data-security/identity-session-security.md
- docs/data-security/file-security.md
---
# التدقيق والخصوصية

## 1. الغرض والنطاق

تحدد هذه الوثيقة سياسة التدقيق في المنصة الإدارية للتجمع الصحي الثالث، وتشمل:

- هيكل سجل التدقيق المركزي.
- نموذج Append-only على مستوى قاعدة البيانات وفصل الأدوار.
- Hash Chain لربط الأحداث وكشف التلاعب.
- التصدير اليومي غير القابل للتغيير لأغراض الامتثال والاحتفاظ طويل المدى.
- معالجة متطلبات PDPL للخصوصية ودورة حياة بيانات الموظف.
- ربط NDMO بدورة حياة البيانات الوصفية.

المنصة غير سريرية. تعالج PII للموظف فقط، ولا تستقبل أو ترسل بيانات خارج مركز بيانات التجمع. لذلك تركز هذه الوثيقة على ما يحدث داخل المنصة، وعلى قنوات التدقيق الداخلية، وعلى ضمانات عدم التلاعب.

## 2. مبادئ التدقيق

- **Append-only حقيقي على مستوى قاعدة البيانات.** لا يوجد أي UPDATE أو DELETE على جداول التدقيق من أي حساب، بمن فيهم DBA. قاعدة البيانات نفسها ترفض التعديل على مستوى محرك التخزين.
- **فصل الدور.** حساب قاعدة البيانات الخاص بالتطبيق لا يملك أي صلاحية على جداول التدقيق. الكتابة تتم عبر Procedure مخصص، والقراءة عبر دور منفصل.
- **Hash Chain لكل حدث.** كل حدث يحمل Hash الحدث السابق، وأي تعديل على حدث سابق يكسر السلسلة ويفشل التحقق.
- **التصدير اليومي غير قابل للتغيير.** حزمة يومية موقعة ومخزنة في مخزن منفصل فيزيائياً.
- **سجلان متكاملان.** سجل أمني مركزي للحوادث الحساسة، وسجل نشاط وظيفي مفهوم للمستخدم يظهر داخل السجل نفسه.
- **الاحتفاظ حسب التصنيف.** مدة الاحتفاظ مرتبطة بتصنيف السجل وسياسة نوع العمل، وتخضع لقواعد إتلاف محكومة.
- **لا تكرار للأحداث.** كل حدث يحمل `event_id` فريد، والمستهلكون Idempotent.

## 3. نموذج البيانات

### 3.1 الجداول

#### 3.1.1 `audit_events`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `event_id` | UUID | PK | معرف فريد للحدث |
| `event_type` | string | إلزامي، مفهرس | نوع الحدث وفق قاموس مركزي |
| `occurred_at` | timestamp(6) | إلزامي | لحظة الحدوث بدقة ميكروثانية |
| `recorded_at` | timestamp(6) | إلزامي | لحظة الكتابة في السجل |
| `actor_user_id` | UUID | اختياري | الفاعل البشري |
| `actor_session_id` | UUID | اختياري | الجلسة |
| `actor_service_account` | string | اختياري | الفاعل الآلي |
| `actor_ip` | string | اختياري | IP داخلي |
| `actor_user_agent` | string | اختياري | المتصفح أو العامل |
| `target_type` | string | إلزامي | نوع الكيان الهدف |
| `target_id` | string | إلزامي | معرف الكيان الهدف |
| `target_owner_org_unit_id` | UUID | اختياري | الجهة المالكة |
| `classification` | enum | إلزامي | تصنيف الحدث: public, internal, confidential, top_secret |
| `outcome` | enum | إلزامي | success, denied, failure, error |
| `reason` | text | اختياري | سبب الإجراء عند الحاجة |
| `module` | string | إلزامي | اسم الموديول المنتج |
| `payload_hash` | string | إلزامي | Hash لـ payload مفصول |
| `payload_size` | int | إلزامي | حجم payload بالبايت |
| `prev_event_hash` | string | إلزامي | Hash الحدث السابق في السلسلة |
| `event_hash` | string | إلزامي، فريد | Hash الحدث الحالي |
| `chain_id` | string | إلزامي | معرف سلسلة فرعية |
| `sequence_no` | bigint | إلزامي | رقم التسلسل داخل السلسلة |
| `export_batch_id` | UUID | اختياري، مفهرس | ربط بحزمة التصدير |

#### 3.1.2 `audit_payloads`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `event_id` | UUID | PK, FK | ربط بالحدث |
| `payload_encrypted` | blob | إلزامي | payload مشفر |
| `payload_kms_key_id` | string | إلزامي | معرف مفتاح KMS |
| `retention_until` | timestamp | إلزامي | نهاية صلاحية الاحتفاظ |

#### 3.1.3 `audit_hash_link`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `chain_id` | string | PK | سلسلة فرعية |
| `sequence_no` | bigint | PK | رقم التسلسل |
| `event_id` | UUID | فريد | الحدث |
| `prev_event_hash` | string | إلزامي | hash السابق |
| `event_hash` | string | إلزامي | hash الحالي |
| `signed_at` | timestamp(6) | إلزامي | زمن التوقيع |

#### 3.1.4 `audit_export_batch`

| الحقل | النوع | القيد | الوصف |
|---|---|---|---|
| `batch_id` | UUID | PK | معرف الحزمة |
| `export_date` | date | فريد | تاريخ التصدير |
| `started_at` | timestamp(6) | إلزامي | بداية التصدير |
| `completed_at` | timestamp(6) | اختياري | لحظة الاكتمال |
| `event_count` | bigint | إلزامي | عدد الأحداث |
| `payload_digest` | string | إلزامي | Hash مجمّع لكل الأحداث |
| `signature` | string | إلزامي | توقيع الحزمة |
| `signature_key_id` | string | إلزامي | مفتاح التوقيع |
| `storage_path` | string | إلزامي | مسار الحزمة في المخزن المنفصل |
| `status` | enum | إلزامي | pending, completed, failed, verified |
| `verified_at` | timestamp(6) | اختياري | لحظة التحقق الخارجي |
| `verifier_user_id` | UUID | اختياري | من قام بالتحقق |
| `failure_reason` | text | اختياري | سبب الفشل |

### 3.2 قاموس أنواع الأحداث

تُصنف الأحداث وفق قاموس مركزي مُصدَّر. لا يُسمح بإضافة نوع حدث دون موافقة السوبر أدمن ومراجعة أمن. تُستخدم بادئات للتقسيم:

| البادئة | الفئة | أمثلة |
|---|---|---|
| `auth.*` | أحداث الهوية والجلسة | `auth.login.success`, `auth.login.failed`, `auth.session.terminated.idle` |
| `recovery.*` | أحداث الاسترداد | `recovery.request.opened`, `recovery.verified`, `recovery.completed` |
| `breakglass.*` | أحداث الطوارئ | `breakglass.activated`, `breakglass.session.started`, `breakglass.session.ended` |
| `access.*` | قرار الوصول | `access.granted`, `access.denied`, `access.sensitive.view` |
| `record.*` | أحداث السجلات | `record.created`, `record.updated`, `record.deleted`, `record.classification.changed` |
| `workflow.*` | أحداث المسارات | `workflow.step.activated`, `workflow.decision.recorded` |
| `task.*` | أحداث المهام | `task.assigned`, `task.completed`, `task.commented` |
| `document.*` | أحداث المستندات | `document.uploaded`, `document.downloaded`, `document.linked`, `document.quarantined` |
| `export.*` | أحداث التصدير | `export.report.run`, `export.audit.batch.created` |
| `admin.*` | أحداث الإدارة | `admin.user.created`, `admin.role.assigned`, `admin.config.changed` |
| `system.*` | أحداث النظام | `system.backup.completed`, `system.restore.started` |

## 4. آلية Append-only

### 4.1 منع التعديل على مستوى محرك MySQL

يُمنع أي UPDATE أو DELETE على `audit_events` و`audit_payloads` و`audit_hash_link` على مستوى MySQL عبر:

- حساب قاعدة بيانات التطبيق `app_role` لا يحصل إلا على `INSERT` و`SELECT` (مقيد بـProcedure) لهذه الجداول.
- حساب التدقيق `audit_writer` يحصل على `INSERT` فقط.
- حساب المراجعة `audit_reader` يحصل على `SELECT` فقط.
- حساب DBA لا يحصل على صلاحيات تعديل إلا عبر إجراء استثناء موثق، يُسجَّل في سجل تشغيلي خارجي.

#### 4.1.1 تطبيق الصلاحيات في MySQL

```sql
REVOKE UPDATE, DELETE ON audit_db.audit_events FROM 'app_role'@'%';
REVOKE UPDATE, DELETE ON audit_db.audit_payloads FROM 'app_role'@'%';
REVOKE UPDATE, DELETE ON audit_db.audit_hash_link FROM 'app_role'@'%';

GRANT INSERT, SELECT ON audit_db.audit_events TO 'audit_writer'@'%';
GRANT INSERT, SELECT ON audit_db.audit_payloads TO 'audit_writer'@'%';
GRANT INSERT, SELECT ON audit_db.audit_hash_link TO 'audit_writer'@'%';

GRANT SELECT ON audit_db.audit_events TO 'audit_reader'@'%';
GRANT SELECT ON audit_db.audit_payloads TO 'audit_reader'@'%';
GRANT SELECT ON audit_db.audit_hash_link TO 'audit_reader'@'%';
```

#### 4.1.2 Triggers تأكيدية

```sql
DELIMITER //
CREATE TRIGGER audit_events_no_update
BEFORE UPDATE ON audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'audit_events is append-only';
END//

CREATE TRIGGER audit_events_no_delete
BEFORE DELETE ON audit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'audit_events is append-only';
END//
DELIMITER ;
```

### 4.2 الكتابة عبر Procedure مخصص

لا يكتب التطبيق مباشرة في الجداول. يستدعي Procedure يأخذ المعاملات، يحسب Hash، ويضيف الحدث. هذا يضمن:

- توليد `event_hash` بشكل موحد.
- التحقق من `prev_event_hash`.
- حساب `sequence_no` الذرّي.
- منع فقدان Hash بسبب خطأ برمجي.

#### 4.2.1 Procedure إضافة حدث

```sql
DELIMITER //
CREATE PROCEDURE audit_append_event(
    IN p_event_type VARCHAR(100),
    IN p_actor_user_id BINARY(16),
    IN p_actor_session_id BINARY(16),
    IN p_target_type VARCHAR(100),
    IN p_target_id VARCHAR(100),
    IN p_owner_org_unit_id BINARY(16),
    IN p_classification VARCHAR(20),
    IN p_outcome VARCHAR(20),
    IN p_module VARCHAR(50),
    IN p_payload VARBINARY(8192),
    OUT p_event_id BINARY(16)
)
proc: BEGIN
    DECLARE v_prev_hash VARCHAR(64);
    DECLARE v_seq BIGINT;
    DECLARE v_chain_id VARCHAR(64);
    DECLARE v_recorded_at TIMESTAMP(6);
    DECLARE v_occurred_at TIMESTAMP(6);
    DECLARE v_event_hash VARCHAR(64);
    DECLARE v_payload_hash VARCHAR(64);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        RESIGNAL;
    END;

    START TRANSACTION;

    SET v_occurred_at = NOW(6);
    SET v_recorded_at = NOW(6);

    SELECT chain_id, sequence_no, event_hash
      INTO v_chain_id, v_seq, v_prev_hash
      FROM audit_hash_link
      ORDER BY sequence_no DESC
      LIMIT 1
      FOR UPDATE;

    IF v_seq IS NULL THEN
        SET v_seq = 1;
        SET v_prev_hash = REPEAT('0', 64);
        SET v_chain_id = UUID();
    ELSE
        SET v_seq = v_seq + 1;
    END IF;

    SET v_payload_hash = SHA2(p_payload, 256);

    SET p_event_id = UUID_TO_BIN(UUID());

    SET v_event_hash = SHA2(
        CONCAT_WS('|',
            LOWER(p_event_type),
            v_occurred_at,
            IFNULL(p_actor_user_id, ''),
            IFNULL(p_target_id, ''),
            v_payload_hash,
            v_prev_hash,
            v_seq
        ),
        256
    );

    INSERT INTO audit_events(
        event_id, event_type, occurred_at, recorded_at,
        actor_user_id, actor_session_id,
        target_type, target_id, target_owner_org_unit_id,
        classification, outcome, module,
        payload_hash, payload_size,
        prev_event_hash, event_hash, chain_id, sequence_no
    ) VALUES (
        p_event_id, p_event_type, v_occurred_at, v_recorded_at,
        p_actor_user_id, p_actor_session_id,
        p_target_type, p_target_id, p_owner_org_unit_id,
        p_classification, p_outcome, p_module,
        v_payload_hash, LENGTH(p_payload),
        v_prev_hash, v_event_hash, v_chain_id, v_seq
    );

    INSERT INTO audit_hash_link(
        chain_id, sequence_no, event_id, prev_event_hash, event_hash, signed_at
    ) VALUES (
        v_chain_id, v_seq, p_event_id, v_prev_hash, v_event_hash, v_recorded_at
    );

    INSERT INTO audit_payloads(
        event_id, payload_encrypted, payload_kms_key_id, retention_until
    ) VALUES (
        p_event_id, AES_ENCRYPT(p_payload, @audit_payload_key),
        @audit_kms_key_id,
        DATE_ADD(v_recorded_at, INTERVAL @retention_years YEAR)
    );

    COMMIT;
END proc//
DELIMITER ;
```

### 4.3 اختبار مقاومة التعديل

يحاول المنفذ رفض أي محاولة:

- `UPDATE` على `audit_events` يفشل على مستوى Trigger.
- `DELETE` على `audit_events` يفشل على مستوى Trigger.
- `TRUNCATE` مرفوض على مستوى حساب التطبيق.
- حتى DBA يحتاج لإجراء استثناء موثق ومراجعة.

### 4.4 معالجة فقدان سلسلة

إذا اكتشف التحقق كسراً في Hash Chain:

- ينتقل النظام إلى وضع `audit_degraded`.
- يُنشئ تنبيه فوري للسوبر أدمن وفريق الأمن.
- يتوقف قبول الإجراءات الحساسة حتى استعادة السلسلة.
- لا يُسمح بإعادة بناء السلسلة تلقائياً؛ يتطلب تدخلا يدوياً موثقاً.
- تُسجَّل كل محاولة إعادة بناء كحدث جديد في السلسلة الجديدة.

## 5. Hash Chain وكشف التلاعب

### 5.1 خصائص السلسلة

- كل سلسلة `chain_id` تحمل `sequence_no` متصاعد.
- `event_hash` يحوي `prev_event_hash` كمدخل.
- أي تعديل على حدث سابق يغير `event_hash` ويكسر جميع الأحداث اللاحقة.
- التحقق خطي للأمام، ومسح دوري يبدأ من نقطة آخر توقيع مسبق موثوق.

### 5.2 نقاط التوقيع الخارجي

كل ساعة يوقّع النظام الجذر Merkle لكل السلاسل الفرعية ويكتب:

- `merkle_root` في جدول `audit_merkle_roots` مع التوقيع.
- `audit_export_batch` بنسخة مكررة في مخزن منفصل.

### 5.3 التحقق

#### 5.3.1 التحقق الداخلي

- فاحص يعمل كـjob خلفي يتحقق كل ساعة من آخر 10000 حدث.
- يفشل الفحص عند أي انقطاع في Hash.
- يحفظ آخر تسلسل تم التحقق منه كنقطة استئناف.

#### 5.3.2 التحقق الخارجي

- يومياً، يحمّل المدقق الخارجي قائمة `merkle_root` الموقعة وآخر `event_hash`.
- يعيد التحقق من تجزئة الجذر.
- يخزن النتيجة في تقرير موقع إلكترونياً.

### 5.4 الاختبارات

- `AuditChainTest::event_hash_includes_prev_hash`
- `AuditChainTest::tampering_with_event_breaks_chain`
- `AuditChainTest::merkle_root_recomputes_correctly`
- `AuditChainTest::external_verifier_detects_modification`
- `AuditChainTest::replay_attack_blocked_by_sequence`

## 6. التصدير اليومي غير القابل للتغيير

### 6.1 الجدولة

- مهمة مجدولة يومياً في وقت محدد (03:00 صباحاً بتوقيت المركز).
- تلتقط كل أحداث اليوم السابق حتى 23:59:59.
- تُنفّذ في منطقة زمنية `Asia/Riyadh` بشكل صريح.

### 6.2 محتوى الحزمة

```text
audit-export-YYYY-MM-DD/
├── manifest.json
├── events.parquet
├── events.sha256
├── payloads.enc
├── payloads.sha256
├── signature.sig
└── chain-roots.json
```

- `manifest.json` يصف الحزمة وعدد الأحداث وقيم التجزئة.
- `events.parquet` يحوي حقول التدقيق الأساسية.
- `payloads.enc` يحوي payloads مشفرة.
- `signature.sig` يحوي توقيع ECDSA P-256 على `manifest.json`.
- `chain-roots.json` يحوي جذور Merkle لكل سلسلة فرعية مع توقيع.

### 6.3 خصائص عدم القابلية للتغيير

- الحزمة تُكتب بمفتاح KMS منفصل عن الإنتاج.
- لا يمكن لمستخدم الإنتاج قراءة أو تعديل الحزمة.
- النقل يتم عبر حساب خدمة منفصل `audit_export_role`.
- التحقق من التوقيع يكون عبر المفتاح العام المخزن في HSM داخلي.
- أي فشل في التحقق يوقف القراءة ويُسجَّل في سجل تشغيلي منفصل.

### 6.4 الاحتفاظ

- الحزم تحفظ لمدة 7 سنوات في مخزن التدقيق المنفصل.
- يُفصل فيزيائياً عن الإنتاج (مخزن مستقل، VLAN مستقل).
- نسخة احتياطية على شريط مشفرة خارج المنطقة.

### 6.5 مؤشرات الفشل

- فشل التصدير خلال 30 دقيقة من الجدولة يرفع تنبيهاً.
- فشل التحقق يرفع تنبيهاً حرجاً.
- أي محاولة تعديل على مخزن التدقيق ترفع تنبيهاً حرجاً.

### 6.6 الاختبارات

- `AuditExportTest::daily_export_contains_all_events_of_previous_day`
- `AuditExportTest::export_signature_verifies_with_public_key`
- `AuditExportTest::tampering_with_export_fails_signature`
- `AuditExportTest::export_storage_path_is_write_only_for_app_role`
- `AuditExportTest::failure_alerts_within_30_minutes`

## 7. تطبيق الخصوصية وفق PDPL

### 7.1 مبادئ المعالجة

| المبدأ | التطبيق |
|---|---|
| أساس المعالجة | كل نوع عمل يحمل `processing_basis` ضمن تعريفه |
| تقليل البيانات | كل حقل ديناميكي يحمل `purpose` و`retention_years` |
| دقة البيانات | صلاحية تعديل PII للمالك فقط، تسجيل كل تعديل |
| الاحتفاظ | `retention_until` محسوب من `retention_years` على نوع العمل |
| حقوق صاحب البيانات | تدفق محكوم ضمن الطلبات الداخلية |
| أمن البيانات | تشفير PII، فصل الأدوار، تدقيق الاطلاع |
| الإخطار بالاختراق | آلية كشف وتنبيه خلال 24 ساعة |

### 7.2 معالجة حقوق صاحب البيانات

- **حق الاطلاع:** نموذج طلب ضمن تدفق محكوم، يولد تقرير بكل بياناته عبر Read Model مخصص. يُسجَّل الحدث.
- **حق التصحيح:** نموذج طلب، صلاحية التصحيح للمالك أو من يفوضه النظام. يُسجَّل الحدث.
- **حق الحذف:** متاح فقط خارج الإطار القانوني والمهني. لا يحذف السجلات الخاضعة للاحتفاظ النظامي. يُوثق الرفض مع الأساس.
- **حق الاعتراض:** يحال للجهة المختصة للنظر.

### 7.3 الاستثناءات والقيود

- لا يحق للموظف حذف سجلات محاسبية أو إدارية خاضعة للاحتفاظ النظامي.
- لا يحق للموظف الاعتراض على قرارات إدارية عبر هذا التدفق.
- لا تنطبق حقوق PDPL على البيانات المجهولة الهوية المجمعة لأغراض المؤشرات.

### 7.4 الاختبارات

- `PrivacyTest::pii_fields_marked_with_purpose_and_retention`
- `PrivacyTest::data_subject_access_request_works`
- `PrivacyTest::deletion_blocked_for_legal_hold_records`
- `PrivacyTest::pii_edits_audit_with_actor_and_reason`

## 8. تطبيق NDMO

### 8.1 تصنيف البيانات

| المستوى | الوصف | ضوابط |
|---|---|---|
| عام (`public`) | بيانات منشورة | ضوابط النشر والنطاق |
| داخلي (`internal`) | بيانات إدارية داخلية | قرار Authorization والنطاق التنظيمي |
| سري (`confidential`) | PII للموظف | تشفير، صلاحية حقل، تدقيق |
| سري للغاية (`top_secret`) | هويات وطنية ومعلومات حساسة | تشفير أعمدة، فصل إداري، تدقيق موسع |

### 8.2 ملكية البيانات

- كل سجل أعمال يحمل `owner_organization_unit_id`.
- كل نوع عمل يحمل `data_steward_role`.
- كل تغيير في تعريف نوع عمل يتطلب موافقة مالك البيانات.

### 8.3 دورة حياة البيانات الوصفية

| المرحلة | التطبيق |
|---|---|
| الإنشاء | توليد `record_id` و`created_at` و`created_by` ضمن Transaction |
| الاستخدام | تسجيل كل اطلاع على محتوى سري |
| الأرشفة | نقل للقراءة فقط، إيقاف الكتابة |
| الإتلاف | عملية محكومة بموافقة مالك البيانات والسوبر أدمن |

### 8.4 البيانات الرئيسية

- `Person` و`UserAccount` مملوكان لـ Identity.
- `OrgUnit` و`Position` واللجان و`Employment` و`PositionAssignment` و`TemporaryAssignment` و`CommitteeMembership` مملوكة لـ Organization.
- `WorkRecord` مملوك لـ`WorkRecords`، و`Workflow` مملوك لـ`Workflow`.
- لا تكرار لـ PII خارج Identity.

### 8.5 الاختبارات

- `NdmoTest::every_workrecord_has_owner_org_unit`
- `NdmoTest::data_steward_role_required_for_definition_changes`
- `NdmoTest::archived_records_are_read_only`
- `NdmoTest::destruction_requires_dual_approval`

## 9. اختبارات حارسة دورية

- `AuditTest::append_only_enforced_at_db_level`
- `AuditTest::audit_writer_role_lacks_update_grants`
- `AuditTest::audit_reader_role_lacks_insert_grants`
- `AuditTest::app_role_lacks_audit_table_grants`
- `AuditTest::hash_chain_intact_after_backup_restore`
- `PrivacyTest::no_pii_in_application_logs`
- `PrivacyTest::pii_columns_use_kms_encryption`
- `NdmoTest::classification_levels_present_on_records`
- `NdmoTest::retention_policy_present_on_work_types`

## 10. مؤشرات الإنذار

- فشل التحقق من Hash Chain.
- فشل التصدير اليومي.
- محاولة تعديل على `audit_events` أو `audit_payloads`.
- محاولات وصول من حسابات تدقيق من خارج قائمة الحسابات المعتمدة.
- تجاوز معدل كتابة التدقيق للحد الأعلى الطبيعي (كشف هجوم أو عطل).
- تجاوز مدة الاحتفاظ لحد بدون خطة إتلاف.
- نمو غير طبيعي في حجم جداول التدقيق.

## 11. خطة الاستجابة للحوادث المتعلقة بالتدقيق

- رصد الفشل → تنبيه فوري للسوبر أدمن.
- عزل القراءة والكتابة من التطبيق مؤقتاً.
- تجميد المخزن المنفصل.
- تحليل الفجوة في السلسلة.
- تقرير موقع خلال 24 ساعة.
- قرار الاستئناف أو الإيقاف.
- تسجيل كل إجراء في تدقيق تشغيلي خارجي.

## 12. الامتثال

| المتطلب | التطبيق |
|---|---|
| NCA ECC 1-7 التسجيل والمراقبة | سجل تدقيق كامل مع فصل الأدوار |
| NCA ECC 1-10 إدارة النسخ الاحتياطي | تصدير يومي مع توقيع ومستقل |
| PDPL أمن البيانات | تشفير payloads وتدقيق الاطلاع |
| PDPL حقوق صاحب البيانات | تدفق محكوم ومُسجَّل |
| PDPL الاحتفاظ | سياسات retention مرتبطة بنوع العمل |
| NDMO تصنيف البيانات | 4 مستويات مع ضوابط |
| NDMO مالك البيانات | تعيين إلزامي على كل سجل |

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 0.1.0 | 2026-07-15 | مسؤول أمن المعلومات | إنشاء المسودة التنفيذية |
| 0.2.0 | 2026-07-15 | مسؤول أمن المعلومات | توحيد التصنيف واستبدال المراجع التشغيلية التاريخية وضبط الوثيقة |
