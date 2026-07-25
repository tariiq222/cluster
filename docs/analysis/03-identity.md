# 03 · موديول Identity (المصادقة، الجلسات، TOTP، كلمات المرور)

> **المسار:** `apps/api/Modules/Identity/`
> **Rank:** 1 (يستهلك Organization، يخدم Authorization)
> **عدد الملفات:** 47 PHP

## 1 · نبذة عامة
موديول `Identity` مسؤول عن:
- حسابات المستخدمين (`users`)، الهوية (`identities`)، اعتماداتهم (credentials, password history, activation tokens, TOTP, auth attempt ledgers).
- جلسات الكوكي المربوطة بـ IP+UA، مع CSRF token.
- JWT/fixture bearer للاختبارات المحلية فقط.
- Auth-event outbox للأمان.
- استقبال أحداث Organization (`PersonRegistered`, `PersonUpdated`, `PersonAccessStatusChanged`, `IdentityProvisioningRequested`) عبر `IdentityPersonStreamWorker`.

## 2 · الوحدات الفرعية

| النظام الفرعي | المسار | المسؤولية |
|--------------|-------|-----------|
| Authentication | `Features/Authentication/` | خط أنابيب الدخول: throttle → lock → hash → TOTP → session issue → outbox security event |
| Sessions | `Features/Sessions/` | إصدار/حل/تدوير/إلغاء الجلسات، CSRF proof |
| Credentials | `Features/Credentials/` | ضبط كلمة المرور عند التفعيل، تغيير كلمة المرور، PasswordPolicy |
| TOTP | `Features/Totp/` | تسجيل TOTP، تأكيد، تحقق، تشفير secret بـ Crypt |
| Activation | `Features/Activation/` | إصدار واستهلاك activation tokens |
| UserAccount | `Features/UserAccount/` | CRUD لحسابات المستخدمين (إنشاء/قائمة/transition) |
| Sessions + Consume Org Events | `Features/ConsumeOrganizationPersonEvents/` | Stream Worker لتيارات Organization |
| DevelopmentFixtureLogin | `Features/DevelopmentFixtureLogin/Http/` | تطوير فقط — fixture bearer login |
| Http Helpers | `Http/IdentityApi.php` | correlation-id / idempotency-key / if-match / ETag / problem+json / cloudEvent builder |
| Persistence | `Infrastructure/Persistence/Migrations/` | جداول Identity (CreateIdentityAccountTables, ZAddIdentityCredentialCoreTables, ZCreateDevelopmentFixtureAccountsTable) |
| Security | `Infrastructure/Security/` | PasswordHasher، LocalUsernameDenylist، PersistentPreAuthThrottle |
| Outbox | `Infrastructure/Outbox/IdentityOutbox.php` | كتابة أحداث security في outbox_events |
| Resolvers | `Infrastructure/DatabaseResolveAccountEntitlement.php`، `Infrastructure/SessionPrincipalContextResolver.php`، `Infrastructure/Persistence/ResolveUserForPerson.php` | تنفيذ الـ Contracts |

## 3 · أهم العقود (Contracts)

| العقد | الموقع | الوظيفة |
|------|-------|--------|
| `PrincipalContext` | `Contracts/PrincipalContext.php` | DTO موثوق لـ principal على الخادم (actor + legacy helpers) |
| `ResolvePrincipalContext` | `Contracts/ResolvePrincipalContext.php` | يحلّ ويخزّن الـ scope المختار |
| `ResolveAccountEntitlement` | `Contracts/ResolveAccountEntitlement.php` | يرجّع `{active, administrator}` لـ user_id |
| `ResolveUserForPerson` | `Contracts/ResolveUserForPerson.php` | person_id → account_id |
| `ResolveDevelopmentFixturePrincipal` | `Contracts/ResolveDevelopmentFixturePrincipal.php` | محصور على `local/testing` |
| `AuthenticateUser` | `Features/Authentication/Contracts/AuthenticateUser.php` | خط أنابيب الدخول العام |
| `PreAuthThrottle` | `Features/Authentication/Contracts/PreAuthThrottle.php` | throttle قبل الـ hash |
| `PreAuthThrottleDecision` | `Features/Authentication/Contracts/PreAuthThrottleDecision.php` | قرار: allowed/scope/blockedUntil/lockLevel |
| `ChangePassword` | `Features/Credentials/Contracts/ChangePassword.php` | تغيير كلمة المرور مع history |
| `UsernameDenylist` | `Features/Credentials/Contracts/UsernameDenylist.php` | قائمة username محظورة |
| `ResolveSession` | `Features/Sessions/Contracts/ResolveSession.php` | حل session + تحقق CSRF |
| `SessionTransport` | `Features/Sessions/Contracts/SessionTransport.php` | cookie + csrf payload |
| `TrustedRequestBindingContext` | `Features/Sessions/Contracts/TrustedRequestBindingContext.php` | IP+UA context |
| `IssueActivationToken` | `Features/Activation/Contracts/IssueActivationToken.php` | إصدار token التفعيل |

## 4 · Domain / Handlers / Infrastructure

### 4.1 Domain
- `Domain/UserAccount.php` — readonly VO: UUIDv7 + KC normalization + `toArray()`.
- `Domain/PasswordPolicy.php` — محرك قواعد كلمة السر، يقوده `GetEffectivePlatformSettings` + `LocalUsernameDenylist`.

### 4.2 Handlers
- `AuthenticationHandler.php` — خط أنابيب كامل (throttle, lock, dummy/real hash, TOTP, session issue, outbox security event).
- `SessionHandler.php` — إصدار/حل/تدوير/إلغاء، IP+UA binding، TOTP gate.
- `CredentialHandler.php` — activation password set، change password مع history، PasswordPolicy enforcement.
- `TotpHandler.php` — enroll/confirm/verify مع Crypt-encrypted secret.
- `ActivationHandler.php` — issue + consume activation tokens، TOTP chain للمدراء.
- `UserAccountHandler.php` — إنشاء/إيجاد/قائمة/transition مع idempotency replay و cursor pagination مشفّر.
- `ConsumeOrganizationPersonEventHandler.php` — استقبال أحداث Person من Organization.
- `DevelopmentFixturePrincipalResolver.php` — يحلّ fixture principal من الكاش.

### 4.3 Workers
- `IdentityPersonStreamWorker.php` — Redis Streams worker على 3 تيارات:
  - `platform.organization.identity-provisioning-requested.v1`
  - `platform.organization.person-access-status-changed.v1`
  - `platform.organization.person-updated.v1`
  - مع `GROUP = identity.organization-person-events.v1`، `MAX_ATTEMPTS = 3`، `RECLAIM_IDLE_MS = 60_000`، DLQ = `platform.dlq.v1`.

### 4.4 Infrastructure
- `Infrastructure/Security/PasswordHasher.php` — تغليف `Hash::make`/`Hash::check`.
- `Infrastructure/Security/LocalUsernameDenylist.php` — قائمة محلية.
- `Infrastructure/Security/PersistentPreAuthThrottle.php` — تنفيذ `PreAuthThrottle` مع ثبات.
- `Infrastructure/Persistence/ResolveUserForPerson.php` — DB impl.
- `Infrastructure/DatabaseResolveAccountEntitlement.php` — DB impl.
- `Infrastructure/SessionPrincipalContextResolver.php` — يحلّ Principal من session attribute.
- `Infrastructure/Outbox/IdentityOutbox.php` — كتابة أحداث security.

## 5 · مصادر البيانات (DB tables)

من `ModuleBoundariesTest::TABLE_OWNERS` (Identity):
- `identities`, `users`
- `identity_sessions`
- `identity_person_account_claims`
- `identity_idempotency_keys`
- `identity_inbox`
- `identity_person_event_watermarks`
- `identity_person_provisioning`
- `identity_development_fixture_accounts`
- `credentials`
- `identity_password_history`
- `identity_activation_tokens`
- `identity_totp`
- `identity_auth_attempt_ledgers`

**ملاحظة:** فعلياً الـ migration `CreateIdentityAccountTables.php` ينشئ `users` و `identity_sessions` و `identity_password_history` و `identity_activation_tokens` و `identity_totp` و `identity_auth_attempt_ledgers`. لا يُنشئ `identities` أو `identity_inbox` أو `identity_person_event_watermarks` أو `identity_person_provisioning` صراحةً — هذه الجداول مذكورة في `TABLE_OWNERS` لكن مولِّدها غير واضح.

## 6 · نقاط الـ API (من `routes/web.php`)

| المسار | Method | Middleware | CSRF | ملاحظات |
|--------|--------|------------|------|---------|
| `/api/v1/identity/login` | POST | none (stateless) | — | IdentityLoginController (legacy in app/) |
| `/api/v1/identity/activation` | POST | `throttle:6,1` | — | ConsumeActivationController (legacy) |
| `/api/v1/identity/me` | GET | IdentitySession + RequirePrincipal | — | GetCurrentIdentityController (legacy) |
| `/api/v1/identity/csrf` | POST | IdentitySession + RequirePrincipal | — | RefreshIdentityCsrfController |
| `/api/v1/me` | GET | IdentitySession + RequirePrincipal | — | GetCurrentPrincipalController |
| `/api/v1/me/scopes` | GET | IdentitySession + RequirePrincipal | — | ListMyScopesController |
| `/api/v1/me/scope` | PUT | IdentitySession + RequirePrincipal + IdentityCsrf | ✅ | SelectMyScopeController |
| `/api/v1/identity/logout` | POST | IdentitySession + RequirePrincipal + IdentityCsrf | ✅ | IdentityLogoutController |
| `/api/v1/identity/password` | POST | IdentitySession + RequirePrincipal + IdentityCsrf | ✅ | ChangePasswordController |
| `/api/v1/identity/accounts/{accountId}/activation` | POST | IdentitySession + RequirePrincipal + IdentityCsrf | ✅ | IssueActivationController |

## 7 · الاختبارات (تحت `Modules/Identity/Tests/`)
- `IdentityAccountHttpAdapterTest.php` — HTTP layer لحسابات.
- `IdentityCredentialCoreTest.php` — كلمة المرور + TOTP.
- `IdentityCredentialHttpAdapterTest.php` — تغيير كلمة المرور.
- `IdentityPersonStreamWorkerTest.php` — Stream Worker.
- `IdentityProvisioningConsumerTest.php` — handler provisioning.
- `PlatformSecurityPolicyIntegrationTest.php` — تكامل مع PlatformSettings.
- `PrincipalContextResolverTest.php` — SessionPrincipalContextResolver.
- `ScopeSelectionHttpAdapterTest.php` — اختيار scope.

## 8 · الوضع الحالي
- ✅ Production-grade: Authentication, Sessions, Credentials, TOTP, Activation, UserAccount.
- ✅ Stream Worker للأحداث الصادرة من Organization مع reclaim + DLQ.
- ⚠️ HTTP layer (login/me/...) ما زال في `app/Http/Controllers/Identity/` legacy.
- ⚠️ `IdentityIdempotency.php` و `ResolvesScopeSelection.php` helpers لم تُنقَل بعد.
- ⚠️ TABLE_OWNERS يذكر جداول (`identities`, `identity_inbox`, ...) لا تظهر في التهجيرات.

## 9 · المشاكل / المخاطر

| # | الوصف | المرجع |
|---|-------|--------|
| I1 | 16 legacy controller في `app/Http/Controllers/Identity/` | `ModulePlacementInventory.php:32-46` |
| I2 | `identities` و`users` كلاهما مُسجَّل لـ Identity — غموض ملكية | `ModuleBoundariesTest.php:66-67` |
| I3 | `identity_inbox`, `identity_person_event_watermarks`, `identity_person_provisioning`، `identity_person_account_claims` مذكورة في `TABLE_OWNERS` لكن لا تهجيرة منشئتها مرئية | `ModuleBoundariesTest.php:69-72` |
| I4 | `IdentitySessionMiddleware` يسمح لـ fixture-bearer في `local/testing` لكن `assertAuthorizationRuntimeSafe` يعتمد على `app->environment('production')` فقط — بيئة `staging` غير مغطاة | `IdentitySessionMiddleware.php:32-61`، `AppServiceProvider.php:413-416` |
| I5 | لا يوجد rate-limit على `/identity/login` (throttle:6,1 مفروض على activation فقط) — login مفتوح لـ credential stuffing | `routes/web.php:117-119` |
| I6 | `DevelopmentFixtureLoginController` و `DevelopmentFixturePrincipalResolver` ممرَّمان عبر `auth/login`، يجب التأكد من تعطيل المسار في staging | `routes/web.php:115-116` |
| I7 | TOTP secret مخزَّن بـ `Crypt::encryptString` (Laravel Crypt) — يعتمد على APP_KEY. لو فُقد المفتاح، جميع الحسابات تتعطّل | `Features/Totp/Handler/TotpHandler.php` |
| I8 | `PreAuthThrottle` يستهلك في كل محاولة دخول (good) لكن ليس له تجاوز لمستخدمين تم تقييدهم (lock level=account). الـ handler في `AuthenticationHandler.php` يتحقق من النتيجة | OK |
| I9 | `identity_idempotency_keys` table مذكور لكن لا يظهر في التهجيرات | `ModuleBoundariesTest.php:70` |

## 10 · التحسينات المقترحة

1. **نقل 16 legacy controller** إلى `Modules/Identity/Features/*/Http/`.
2. **دمج `IdentityIdempotency` و `ResolvesScopeSelection`** كـ `Modules/Identity/Infrastructure/Http/Support/...`.
3. **توضيح ملكية `identities` vs `users`** في `TABLE_OWNERS` (هل أحدهما alias؟).
4. **تأكيد أن تهجيرات Identity تغطي** `identity_inbox`, `identity_person_event_watermarks`, `identity_person_provisioning`, `identity_person_account_claims`, `identity_idempotency_keys`.
5. **إضافة rate-limit** على `POST /identity/login` و `POST /identity/activation` (موجود للثاني فقط).
6. **إضافة staging environment guard**: في `staging` يجب أن يكون `assertAuthorizationRuntimeSafe` مفعّلاً.
7. **تشفير احتياطي لـ TOTP secret** عبر KMS envelope encryption لتفادي الاعتماد الكلي على `APP_KEY`.
8. **مراجعة `IdentitySessionMiddleware` fixture-bearer** لمنعها في staging.
9. **استخراج `IdentityOutbox` كـ contract** (مثل `AppendIdentitySecurityEvent`) لجعل المعالجة متّسقة.
10. **توثيق EventTypes الصادرة**: `com.cluster.identity.authenticated.v1`, `com.cluster.identity.locked.v1`, إلخ.
