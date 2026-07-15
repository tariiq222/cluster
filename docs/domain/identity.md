---
doc_id: DOM-IDN-001
title: الهوية والحسابات
type: domain
status: accepted
version: 1.0.0
date: 2026-07-15
owner: مالك موديول Identity
reviewers:
- مسؤول هندسة البرمجيات
- مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير
sources:
- docs/adr/012-local-identity-and-session-security.md
- docs/architecture/dependency-rules.md
- docs/adr/004-authorization-and-isolation.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/identity-session-security.md
---
# Identity

## 1. الغرض

يمثل هذا المجال هوية المستخدم التشغيلية داخل المنصة ودورة حياة حسابه وجلساته وبيانات الاعتماد المحلية. يثبت أن الشخص يستطيع تسجيل الدخول بحساب محلي، دون أن يملك المنصب أو الجهة أو الدور أو صلاحيات الأعمال. يقدّم Identity عقوداً مستقرة إلى Authorization والموديولات الأخرى، ولا يسمح لأي موديول بقراءة كلمات المرور أو جداول الاعتماديات مباشرة.

## 2. النطاق

- إنشاء الحسابات المحلية وربطها بمعرّف Person اختياري من Organization.
- تخزين كلمة المرور على شكل hash قوي فقط، وعدم تخزين كلمة المرور الصريحة أو إتاحتها لأي مستخدم.
- تسجيل الدخول داخل الشبكة باسم المستخدم وكلمة المرور.
- تغيير كلمة المرور، وإجبار تغييرها عند أول دخول أو بعد إجراء استعادة محكوم.
- إدارة حالة الحساب، القفل المؤقت، إنهاء الجلسات، واستعادة الحساب المسجلة.
- إدارة الجلسات المحلية ورمز إبطال كلمة المرور.
- تقديم ملخص هوية قابل للاستهلاك عبر العقود.

ما لا يدخل في هذا المجال:

- Person والجهة والمنصب والتكليف، وتبقى في Organization.
- الأدوار والقدرات وقرارات الوصول، وتبقى في Authorization.
- الموارد البشرية الرسمية والرواتب والإجازات والترقيات.
- تسجيل الدخول الخارجي أو SSO أو مزود هوية سحابي في المرحلة الحالية.

## 3. المصطلحات

| المصطلح | التعريف |
|---|---|
| المستخدم (User) | هوية حساب محلية يمكنها إنشاء جلسة أو تنفيذ إجراء بعد التحقق من حالتها. |
| الحساب (Account) | سجل دورة حياة المستخدم وحالته التشغيلية، وليس الشخص أو المنصب. |
| بيانات الاعتماد (Credential) | كلمة المرور الممثلة بـ hash وإعدادات تغييرها وإبطالها. |
| الجلسة (Session) | جلسة دخول محلية مرتبطة بحساب، لها انتهاء ورمز إصدار وإمكانية إبطال. |
| القفل المؤقت (Lockout) | منع دخول مؤقت بسبب محاولات فاشلة أو إجراء أمني محكوم. |
| إبطال كلمة المرور (Password Version) | رقم يزيد عند تغيير كلمة المرور أو إجراء حساس لإنهاء الجلسات القديمة. |
| ملخص الهوية (Identity Summary) | بيانات عرض محدودة مثل user_id والاسم والحالة، دون سر أو قرار صلاحية. |
| استعادة الحساب (Account Recovery) | عملية محكومة ومسجلة لإعادة تمكين الحساب أو فرض كلمة مرور جديدة، دون كشف القديمة. |

## 4. الـAggregates والـEntities والـValue Objects

### 4.1 UserAccountAggregate

- `UserAccount` (Entity جذر): user_id، username، person_id المرجعي، status، password_version.
- `Username` (Value Object): مطبّع، فريد، غير حساس لحالة الأحرف وفق سياسة النظام.
- `AccountStatus` (Value Object): Pending، Active، Locked، Disabled، Suspended.
- `UserIdentitySummary` (Value Object): الاسم ومرجع الشخص وبيانات العرض المسموحة.

### 4.2 CredentialAggregate

- `PasswordCredential` (Entity جذر ضمن الحساب): hash، algorithm، changed_at، must_change.
- `PasswordPolicySnapshot` (Value Object): نسخة السياسة التي طبقت عند إنشاء أو تغيير كلمة المرور.
- `PasswordVersion` (Value Object): رقم أحادي الزيادة يستخدم لإبطال الجلسات القديمة.

### 4.3 SessionAggregate

- `UserSession` (Entity جذر): session_id، user_id، issued_at، expires_at، revoked_at، password_version.
- `SessionFingerprint` (Value Object): معلومات تشغيلية غير سرية لازمة للتدقيق، دون تخزين كلمة المرور أو رمز الجلسة الصريح.

### 4.4 AccountRecoveryAggregate

- `AccountRecoveryEvent` (Entity جذر): نوع الإجراء، منفذه، سببه، user_id، النتيجة، وقت الانتهاء.
- لا يخزن هذا التجمع رمز استعادة قابل لإعادة الاستخدام بعد انتهاء العملية.

## 5. الجداول والقيود والفهارس

### 5.1 `users`

- `id` BIGINT PK.
- `username` VARCHAR(128) NOT NULL.
- `person_id` BIGINT NULL، معرف خارجي يملكه Organization ولا ينشئ Identity له FK مالكاً.
- `display_name_ar` VARCHAR(255) NOT NULL.
- `display_name_en` VARCHAR(255) NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `pending`.
- `must_change_password` BOOLEAN NOT NULL DEFAULT TRUE.
- `password_version` BIGINT NOT NULL DEFAULT 1.
- `last_login_at` DATETIME NULL.
- `failed_login_count` INT NOT NULL DEFAULT 0.
- `locked_until` DATETIME NULL.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- قيد فريد على `(username)` بعد التطبيع.
- فهارس: `(status)`، `(person_id)`، `(locked_until)`.

### 5.2 `credentials`

- `id` BIGINT PK.
- `user_id` BIGINT NOT NULL FK -> `users.id` ON DELETE CASCADE.
- `password_hash` VARCHAR(255) NOT NULL.
- `hash_algorithm` VARCHAR(32) NOT NULL.
- `password_changed_at` DATETIME NOT NULL.
- `policy_version` VARCHAR(32) NOT NULL.
- `created_at` DATETIME NOT NULL، `updated_at` DATETIME NOT NULL.
- قيد فريد على `(user_id)`.
- لا يسمح لأي Query أو Resource بإرجاع `password_hash`.
- فهرس: `(user_id, password_changed_at)`.

### 5.3 `sessions`

- `id` CHAR(36) PK.
- `user_id` BIGINT NOT NULL FK -> `users.id` ON DELETE CASCADE.
- `token_hash` VARCHAR(255) NOT NULL.
- `password_version` BIGINT NOT NULL.
- `issued_at` DATETIME NOT NULL.
- `expires_at` DATETIME NOT NULL.
- `revoked_at` DATETIME NULL.
- `last_seen_at` DATETIME NULL.
- `metadata` JSON NOT NULL.
- قيد فريد على `(token_hash)`.
- فهارس: `(user_id, revoked_at, expires_at)`، `(expires_at)`.

### 5.4 `account_recovery_events`

- `id` BIGINT PK.
- `user_id` BIGINT NOT NULL FK -> `users.id`.
- `requested_by_user_id` BIGINT NOT NULL FK -> `users.id`.
- `action` VARCHAR(32) NOT NULL.
- `reason` VARCHAR(500) NOT NULL.
- `status` VARCHAR(16) NOT NULL.
- `completed_at` DATETIME NULL.
- `created_at` DATETIME NOT NULL.
- فهارس: `(user_id, created_at)`، `(status, created_at)`، `(requested_by_user_id, created_at)`.

## 6. الأوامر والاستعلامات والأحداث

### 6.1 Commands

- `CreateUserAccount`
- `ActivateUserAccount`
- `DisableUserAccount`
- `SuspendUserAccount`
- `UnlockUserAccount`
- `AuthenticateUser`
- `ChangeOwnPassword`
- `ForcePasswordChange`
- `RevokeUserSessions`
- `CreateAccountRecoveryEvent`
- `CompleteAccountRecovery`
- `RevokeSession`

كل Command يمر عبر Handler يملك Transaction الخاصة بتغيير هوية الحساب. لا يوجد `CommonAuthService` يكتب في جداول Identity من خارج الموديول.

### 6.2 Queries

- `GetUserIdentity`
- `GetActiveUserAccount`
- `GetIdentitySummary`
- `ListUserSessions`
- `GetPasswordPolicy`
- `GetAccountLockState`
- `GetAccountRecoveryHistory`
- `IsAccountActive`

تُرجع Queries بيانات عرض أو عقوداً محددة، ولا تعيد hash أو token أو أسراراً.

### 6.3 Domain وApplication Events

- `UserAccountCreated`
- `UserAccountActivated`
- `UserAccountDisabled`
- `UserAccountSuspended`
- `UserPasswordChanged`
- `UserPasswordChangeRequired`
- `UserAccountLocked`
- `UserAccountUnlocked`
- `UserSessionCreated`
- `UserSessionRevoked`
- `UserSessionsRevoked`
- `AccountRecoveryStarted`
- `AccountRecoveryCompleted`
- `AccountAuthenticationFailed`

الأحداث التي يحتاجها Audit أو الإشعارات تحفظ في Outbox ضمن Transaction المالك.

## 7. State Machines

### 7.1 UserAccount

- `Pending` --(ActivateUserAccount)--> `Active`.
- `Active` --(failed threshold)--> `Locked`.
- `Locked` --(lock period expires or UnlockUserAccount)--> `Active`.
- `Active` --(DisableUserAccount)--> `Disabled`.
- `Locked` --(DisableUserAccount)--> `Disabled`.
- `Disabled` --(ActivateUserAccount)--> `Active` بعد تحقق إداري محكوم.
- `Active` أو `Locked` --(SuspendUserAccount)--> `Suspended`.
- `Suspended` --(ActivateUserAccount)--> `Active`.

### 7.2 UserSession

- `Issued` --(first request)--> `Active`.
- `Active` --(expires)--> `Expired`.
- `Issued` أو `Active` --(revoke, password change, disable)--> `Revoked`.
- `Expired` و`Revoked` حالتان نهائيتان ولا يعاد استخدام الجلسة.

### 7.3 Credential

- `MustChange` --(successful password change)--> `Usable`.
- `Usable` --(ForcePasswordChange)--> `MustChange`.
- تغيير كلمة المرور يزيد `password_version` ويبطل الجلسات وفق السياسة.

## 8. الـInvariants

- لا ينشأ حسابان بالـusername نفسه بعد التطبيع.
- الحساب Disabled أو Suspended لا ينشئ جلسة ولا ينفذ قرار وصول.
- لا تحفظ كلمة المرور الصريحة، ولا تسجل في Logs أو Events أو أخطاء HTTP.
- كل كلمة مرور جديدة تطبق الحد الأدنى الأمني الحالي، ومنع القيم الشائعة، وتستخدم خوارزمية hash معتمدة محلياً.
- لا يعاد استخدام كلمة مرور سابقة ضمن نافذة التاريخ المحددة في السياسة.
- كل جلسة تحمل `password_version` مطابقاً للإصدار الحالي عند قبول الطلب.
- تغيير كلمة المرور في الحالات الحساسة يبطل الجلسات القائمة ذرياً مع تغيير الإصدار.
- القفل المؤقت لا يتحول إلى تعطيل دائم دون Command إداري مسجل.
- لا ينشئ الموظف حسابه أو يغير `person_id` أو حالته أو صلاحياته بنفسه.
- لا يملك Identity قرار رؤية سجل أعمال، ولا يضيف دوراً أو قدرة.
- كل إجراء Recovery يحدد منفذاً وسبباً ونتيجة ولا يكشف كلمة المرور السابقة.
- كل تغيير مهم يكتب حدثاً في Outbox ضمن Transaction التي غيرت حساب Identity.

## 9. الصلاحيات

- السوبر أدمن ينشئ الحساب ويعطله ويعيد تفعيله ويفرض تغيير كلمة المرور وينهي الجلسات.
- المستخدم النشط يغير كلمة مروره فقط بعد إثبات جلسته وكلمة المرور الحالية أو Recovery مكتمل.
- لا يحق لمستخدم عادي رؤية جلسات مستخدم آخر أو سجل محاولاته التفصيلي.
- Authorization يستهلك `IsAccountActive` و`GetIdentitySummary` فقط، ثم يصدر القرار المركزي؛ لا يعيد Identity بناء RBAC أو ABAC.
- ربط المستخدم بشخص أو تغيير بيانات الشخص يمر عبر عقد Organization محكومة، ولا ينشئ Identity منصباً أو تكليفاً.
- تسجيل الدخول الناجح لا يمنح أي نطاق تنظيمي ضمنياً.

## 10. الفشل

- username غير معروف أو كلمة مرور غير صحيحة: نتيجة عامة لا تكشف أيهما فشل، مع زيادة العداد وفق السياسة.
- تجاوز حد المحاولات: قفل مؤقت، إبطال الجلسات عند الحاجة، وحدث تدقيق.
- حساب معطل أو موقوف: رفض الدخول دون إنشاء Session.
- كلمة مرور ضعيفة أو شائعة: رفض مع أخطاء حقلية دون حفظ قيمة سرية.
- محاولة تغيير كلمة المرور من جلسة قديمة: رفض بعد مقارنة `password_version`.
- تعارض إنشاء username: Rollback ورسالة قابلة للتفسير دون كشف حساب آخر.
- فشل حفظ Outbox: Rollback لتغيير الحساب وعدم إرجاع نجاح كاذب.
- فشل Session store: لا ينشأ دخول جزئي، ويعاد الطلب برسالة تشغيلية عامة.
- انتهاء Recovery أو إعادة استخدامه: رفض وإغلاق الحدث دون تسريب تفاصيل.

## 11. الاختبارات

- Unit: تطبيع username ومنع التكرار.
- Unit: التحقق من سياسة كلمة المرور ومنع القيم الشائعة والتاريخ السابق.
- Unit: انتقالات Pending وActive وLocked وDisabled وSuspended.
- Feature: تسجيل دخول ناجح ينشئ Session دون كشف السر.
- Feature: تسجيل دخول فاشل يقفل الحساب بعد العتبة المحددة.
- Feature: تغيير كلمة المرور يزيد `password_version` ويبطل الجلسات المطلوبة.
- Feature: تعطيل الحساب يمنع الدخول وينهي الجلسات القائمة.
- Authorization contract: الحساب النشط يمرر حقيقة الهوية، والحساب المعطل يجعل Authorization يصدر Deny لأي محاولة وصول.
- Security: لا يظهر hash أو token أو password في Response أو Log أو Event payload.
- Integration: إعادة تشغيل Outbox لا تكرر أثر تغيير الحساب.
- Boundary: لا يقرأ Authorization أو Organization جدول `credentials` مباشرة.
- Recovery: لا يستطيع منفذ Recovery قراءة كلمة المرور القديمة أو إعادة استعمال العملية.

## 12. الاعتماديات

- يعتمد على `Shared/Clock` و`Shared/Identifiers`.
- يستهلك من Organization معرف Person وعقد الربط فقط، دون امتلاك Person أو Position.
- يقدّم إلى Authorization عقود حالة الحساب وملخص الهوية.
- لا يعتمد على Authorization كي يتحقق من كلمة المرور، ولا يعتمد على WorkRecords أو Workflow.
- يستهلك Audit وOutbox عبر عقود تقنية أو أحداث، ولا يكتب جدول Audit مباشرة.
- WorkRecords وWorkflow والموديولات الأخرى تشير إلى `user_id` وتعيد التحقق من Identity عند الإجراءات الحساسة.

## سجل التغيير

| الإصدار | التاريخ | الدور | التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | مالك موديول Identity | توحيد الواجهة الأمامية وحدود الموديول |
