# 04 · موديول Authorization (RBAC + ABAC + Decisions)

> **المسار:** `apps/api/Modules/Authorization/`
> **Rank:** 2
> **عدد الملفات:** 61 PHP

## 1 · نبذة عامة
موديول `Authorization` هو **محرك الترخيص المركزي** للمنصة. يطبّق:
- **RBAC**: Roles + Capabilities + RoleAssignments.
- **ABAC**: Authorizations (scopes)، Classification، FieldAccess، Delegation، ExplicitDeny، SensitiveAccess.
- **Bootstrap gate**: `BootstrapGatedDecideAccess` يحجب كل القدرات ما عدا setup قبل إكمال bootstrap.
- **Production engine**: `RbacAbacDecideAccess` يُرجع `AccessDecision` (decision, action, reasonCodes, policyVersion, factsVersion, classification).
- **Operations Office**: كتالوج Roles لإدارة المكتب التشغيلي.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Contracts | `Contracts/` | DecideAccess، PersistAccessDecision، AccessDecision، RecordFacts، CapabilityCatalog، CountOperationsOfficeMembers، AccessProjection، AuthorizationResourceReference، ResolveAuthorizationSimulationFacts، AuthorizationSimulationFactsProvider |
| Domain | `Domain/` | AuthorizationScope، Capability، ClassificationLevel، ClassificationPolicy، Delegation، ExplicitDeny، FieldAccessTemplate، FieldDecision، OfficeApprovalGuard، Role، RoleAssignment، SensitiveAccessEvent، UuidV7 |
| Features (OperationsOffice) | `Features/OperationsOffice/` | BootstrapOperationsOffice، OperationsOfficeMembershipResolver، OperationsOfficeRoleCatalog |
| Infrastructure | `Infrastructure/` | RbacAbacDecideAccess (engine)، BootstrapGatedDecideAccess (gate)، FixtureFacilityDecision (fixtures)، DatabasePersistAccessDecision، CountOperationsOfficeMembers، AuthorizationBootstrapState، AuthorizationHttpGateway، ListActiveRoleSummariesForUser، ListEffectiveCapabilitiesForUser، ValidateDelegationAuthority، ValidateGrantAuthority، RegisteredAuthorizationSimulationFactsResolver |
| Migrations | `Infrastructure/Persistence/Migrations/` | CreateAuthorizationExplicitDenyTables، CreateAuthorizationFieldAuditTables، CreateAuthorizationRbacDataTables، W13*، W15CreateOperationsOffice، ZAddAuthorizationHttpTables |
| Http | `Http/AuthorizationApi.php` | مساعد HTTP (correlation, problem, cloud event) |
| Tests | `Tests/` | 12 ملف اختبار |

## 3 · أهم العقود (Contracts)

| العقد | الوظيفة |
|------|---------|
| `DecideAccess` | `decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision` |
| `AccessDecision` | DTO: decision/action/reasonCodes/policyVersion/factsVersion/classification |
| `RecordFacts` | resourceType + factsVersion + classification |
| `PersistAccessDecision` | تخزين AccessDecision في `sensitive_access_events` |
| `AccessProjection` | تمثيل صريح للقرار |
| `CapabilityCatalog` | كتالوج القدرات |
| `CountOperationsOfficeMembers` | عدد أعضاء المكتب التشغيلي |
| `AuthorizationResourceReference` | مرجع موحَّد للمورد |
| `ResolveAuthorizationSimulationFacts` | حلّ facts للـ simulation |
| `AuthorizationSimulationFactsProvider` | مزوّد facts للـ simulation |

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `AuthorizationScope` — تمثيل scope (facility_id, organization_unit_ids, roles, classification, break_glass).
- `Capability` + `Role` + `RoleAssignment` — RBAC.
- `ClassificationLevel` + `ClassificationPolicy` — ABAC.
- `Delegation` — تفويض وقت-محدود لـ capability.
- `ExplicitDeny` — رفض صريح (override للـ role).
- `FieldAccessTemplate` + `FieldDecision` — حجب/تعديل حقول معينة من payload.
- `OfficeApprovalGuard` — بوابة موافقة المكتب التشغيلي.
- `SensitiveAccessEvent` — سجل وصول للبيانات الحساسة.
- `UuidV7` — utility.

### 4.2 Infrastructure
- `RbacAbacDecideAccess` — الـ engine الإنتاجي. يأخذ `GetActiveSupervisoryRelationships` و `PersistAccessDecision`. يطبّق RBAC + ABAC + Delegation + ExplicitDeny + Classification + FieldAccess.
- `BootstrapGatedDecideAccess` — بوابة: في حالة `AuthorizationBootstrapState::isPending()`، كل القدرات تُرفض ما عدا `organization.bootstrap`، `identity.bootstrap`، `authorization.bootstrap.complete` (ما لم يكن `isPlatformOwner`).
- `FixtureFacilityDecision` — للـ fixtures والاختبارات.
- `RegisteredAuthorizationSimulationFactsResolver` — يجمّع كل `AuthorizationSimulationFactsProvider` المُسجَّل في الـ container (الحالي **فارغ**، فالـ resolver يُرجع facts افتراضية).
- `DatabasePersistAccessDecision` — حفظ في `sensitive_access_events` (مع idempotency على `access_evaluation_id`).
- `AuthorizationBootstrapState` — حالة الـ bootstrap (pending/completed).
- `AuthorizationHttpGateway` — HTTP gateway لـ admin endpoints.
- `CountOperationsOfficeMembers`, `ListActiveRoleSummariesForUser`, `ListEffectiveCapabilitiesForUser` — DB readers.
- `ValidateDelegationAuthority` و `ValidateGrantAuthority` — validation.

## 5 · مصادر البيانات (DB tables)

- `authorizations` — ABAC authorizations (resource_type, resource_id, scope, conditions).
- `roles`, `capabilities`, `role_capabilities` — RBAC catalog.
- `role_assignments` — role-to-user bindings.
- `delegations`, `delegation_capabilities` — delegations.
- `explicit_denies` — explicit denies (with `lock_version` من W13).
- `classification_policies` — policies حسب level.
- `field_access_templates` — قوالب حجب الحقول.
- `sensitive_access_events` — سجلات الوصول الحساسة.
- `audit_events` — مذكور في TABLE_OWNERS لـ Authorization (انحراف ملكية — `Audit` موديول مُخطَّط).

## 6 · نقاط الـ API (HTTP layer)

موديول `Authorization` نفسه لا يعرّف routes مباشرة، لكن الـ legacy controllers في `app/Http/Controllers/Authorization/` تخدم هذه المسارات (مُسجَّلة في `routes/web.php`):
- `POST /api/v1/authorization/bootstrap/complete` — `CompleteAuthorizationBootstrapController`.
- `GET /api/v1/authorization/bootstrap` — `GetAuthorizationBootstrapController`.
- `POST /api/v1/authorization/decide` — `DecideAccessController`.
- `GET /api/v1/authorization/explain` — `ExplainAccessDecisionController`.
- `POST /api/v1/authorization/admin/...` — `AuthorizationAdminController` (إدارة Roles/Capabilities/Assignments).

## 7 · الاختبارات
- `AuthorizationCatalogSeederTest.php` — seeder الكتالوج.
- `AuthorizationDataDomainTest.php` — Domain layer.
- `AuthorizationFieldAuditMigrationTest.php` — تهجيرات الحقول.
- `AuthorizationHttpAdapterTest.php` — HTTP adapter.
- `AuthorizationPersistenceTest.php` — DB layer.
- `AuthorizationPolicyAdminHttpAdapterTest.php` — admin.
- `CapabilityCatalogTest.php`.
- `ClassificationFieldAuditDomainTest.php`.
- `ExplicitDenyDomainTest.php`.
- `FixtureFacilityDecisionTest.php`.
- `ListEffectiveCapabilitiesForUserTest.php`.
- `OperationsOfficeBootstrapTest.php`.
- `PlatformOwnerRoleTest.php`.
- `RbacAbacDecideAccessTest.php`.

## 8 · الوضع الحالي
- ✅ **Production-grade engine**: `RbacAbacDecideAccess` + `BootstrapGatedDecideAccess`.
- ✅ **RBAC catalog** كامل + ABAC layers.
- ✅ **OperationsOffice** كمصدر لإدارة Roles المكتب التشغيلي.
- ⚠️ **Simulation dead path**: `RegisteredAuthorizationSimulationFactsResolver::$providers` فارغ افتراضياً.
- ⚠️ `audit_events` مملوك لـ Authorization لكنه ينتمي منطقياً للـ Audit المخطَّط.
- ⚠️ HTTP layer ما زال في `app/Http/Controllers/Authorization/` legacy.
- ⚠️ `CompleteAuthorizationBootstrapController` يستخدم `DB::table('clusters')` مباشرة (انتهاك boundary module).

## 9 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| A1 | `RegisteredAuthorizationSimulationFactsResolver::$providers` فارغ — الـ simulation لا يعمل | `AppServiceProvider.php:151` |
| A2 | `audit_events` مُسجَّل لـ Authorization (ownership drift، Audit مخطَّط) | `ModuleBoundariesTest.php:86` |
| A3 | `CompleteAuthorizationBootstrapController` يستخدم `DB::table('clusters')` (انتهاك cross-owner) | `ModulePlacementInventory.php:19` |
| A4 | 5 legacy controllers في `app/Http/Controllers/Authorization/` | `ModulePlacementInventory.php:18-22` |
| A5 | `RbacAbacDecideAccess::isPlatformOwner` لا تظهر في الـ tests — غموض المنطق | `BootstrapGatedDecideAccess.php:28` |
| A6 | `ExplicitDeny` مع `lock_version` (W13) — لم يُلاحَظ اختبار concurrency | `W13AddExplicitDenyLockVersion.php` |
| A7 | `ClassificationPolicy` لا يظهر مَن يكتبها في الكود (admin-only؟ fixture-only؟) | `Domain/ClassificationPolicy.php` |
| A8 | `FieldAccessTemplate` يحتاج RuleSpec واضح لكن لا يوجد DSL visible | `Domain/FieldAccessTemplate.php` |
| A9 | `OfficeApprovalGuard` غير مغطى بـ tests | `Domain/OfficeApprovalGuard.php` |
| A10 | `SensitiveAccessEvent` — لا تأكيد أن `PersistAccessDecision` يُلحق دائماً في كل قرار (risk: bypass in fast paths) | `DatabasePersistAccessDecision.php` |
| A11 | لا يوجد مَن يمنع `FixtureFacilityDecision` من الـ production (يحتاج production guard) | `AppServiceProvider.php:142-150` |

## 10 · التحسينات المقترحة

1. **إنقاذ `RegisteredAuthorizationSimulationFactsResolver`**: تسجيل `WorkRecordAuthorizationFacts` و `WorkRecordWorkflowSourceAuthorizationFacts` كـ providers، أو حذفه إن كان dead code.
2. **نقل `audit_events` إلى موديول `Audit`** بمجرد تنفيذه (أو إنشاء موديول Audit).
3. **استبدال `DB::table('clusters')` في `CompleteAuthorizationBootstrapController`** بـ `Organization\Contracts\ResolveOrganizationScopeAncestry` أو ما يشابه.
4. **نقل 5 legacy controllers** إلى `Modules/Authorization/Features/*/Http/`.
5. **تأكيد أن `RbacAbacDecideAccess::isPlatformOwner` له unit test** مع كل role variations.
6. **تأكيد أن `PersistAccessDecision` يُستدعى في كل قرار (sync)** — إضافة `assert_called` في tests.
7. **حماية `FixtureFacilityDecision` بـ production guard**: التأكد أنه غير bindable في production.
8. **تغطية `OfficeApprovalGuard` بـ tests** لجميع المسارات (approve/reject/escalate).
9. **توحيد `ExplicitDeny` precedence**: ExplicitDeny يجب أن يفوز على Role. لا تأكيد من الـ tests.
10. **توثيق `AuthorizationSimulationFactsProvider` contract** في `docs/architecture/`.
