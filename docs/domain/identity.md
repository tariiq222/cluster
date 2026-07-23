---
doc_id: DOM-IDN-001
title: Identity and accounts
type: domain
status: accepted
version: 1.1.0
date: 2026-07-15
owner: Identity module owner
reviewers:
- Software Engineering Lead
- Information Security Lead
classification: internal
review_cycle: on every change
sources:
- docs/adr/012-local-identity-and-session-security.md
- docs/architecture/dependency-rules.md
- docs/adr/004-authorization-and-isolation.md
references:
- docs/architecture/module-catalog.md
- docs/data-security/identity-session-security.md
---
# Identity

## 1. Purpose

This domain represents the operational user identity inside the platform, the lifecycle of its account, its sessions, and its local credentials. It proves that a person can log in with a local account without owning any position, organization unit, role, or business capability. Identity publishes stable contracts to Authorization and other modules and never lets any other module read passwords or credential tables directly.

## 2. Scope

- Creating local accounts and linking them to an optional Person identifier from Organization.
- Storing the password as a strong hash only; never storing the plaintext password and never exposing it to any user.
- Authenticating on the local network with username and password.
- Changing the password and forcing a change on first login or after a governed recovery action.
- Managing account status, temporary lockout, session termination, and account-recovery audit.
- Managing local sessions and the password invalidation token.
- Publishing an identity summary consumable through contracts.

What is out of scope:

- Person, organization unit, position, and assignment — these stay in Organization.
- Roles, capabilities, and access decisions — these stay in Authorization.
- Official HR records, payroll, leave, and promotions.
- External login, SSO, or any cloud identity provider in the current phase.

## 3. Terms

| Term | Definition |
|---|---|
| User | A local account identity that can create a session or perform an action after its status is verified. |
| Account | A user lifecycle record and its operational status; it is not the person or the position. |
| Credential | The password represented as a hash plus change and invalidation settings. |
| Session | A local login session linked to an account, with an expiry, an issuance token, and revocability. |
| Lockout | A temporary login block triggered by failed attempts or a governed security action. |
| Password version | A monotonically increasing number used to invalidate older sessions when the password changes or after a sensitive action. |
| Identity summary | Limited display data such as `user_id`, name, and status, with no secrets and no access decision. |
| Account recovery | A governed, logged procedure to re-enable an account or impose a new password without revealing the old one. |

## 4. Aggregates, entities, and value objects

### 4.1 UserAccount aggregate

- `UserAccount` (root entity): `user_id`, `username`, the `person_id` and `person_version` references, `status`, `password_version`.
- `Username` (value object): normalized, unique, case-insensitive per system policy.
- `AccountStatus` (value object): Pending, Active, Locked, Disabled, Archived.
- The previous revision listed a `UserIdentitySummary` value object; the Domain folder only contains `PasswordPolicy` and `UserAccount`. Identity summary is consumed by other modules through the `ResolvePrincipalContext` contract rather than modeled as a value object.

### 4.2 Credential aggregate

- `PasswordCredential` (root entity within the account): `hash`, `algorithm`, `changed_at`, `must_change`.
- `PasswordPolicy` (value object): the policy snapshot applied at password creation or change.
- `PasswordVersion` (value object): a monotonically increasing number used to invalidate older sessions.

### 4.3 Session aggregate

- `UserSession` (root entity): `session_id`, `user_id`, `issued_at`, `expires_at`, `revoked_at`, `password_version`.
- The previous revision listed a `SessionFingerprint` value object; the Domain folder does not contain it. Session metadata is stored as JSON on `identity_sessions.metadata` and is not modeled as a value object.

### 4.4 Account recovery

- The previous revision modeled an `AccountRecoveryEvent` aggregate and a dedicated `account_recovery_events` table. Neither the Domain folder nor the Identity migration set define them. Recovery is driven through existing commands (`DisableUserAccount`, `UnlockUserAccount`, `ForcePasswordChange`, activation tokens) and audited via the standard `outbox`; no reusable recovery token survives after the procedure completes.

## 5. Tables, constraints, and indexes

### 5.1 `users`

- `id` CHAR(36) UUID PK (the migration uses `$table->uuid('id')->primary()`; the doc previously claimed UUIDv7, which is not verifiable at the migration level).
- `username` VARCHAR(128) NOT NULL.
- `person_id` CHAR(36) UUIDv7 NULL, an external reference owned by Organization; no owning FK is created by Identity.
- `person_version` BIGINT NULL, the latest reference version that was checked or applied for this account.
- `display_name_ar` VARCHAR(255) NOT NULL.
- `display_name_en` VARCHAR(255) NULL.
- `status` VARCHAR(16) NOT NULL DEFAULT `pending`.
- `must_change_password` BOOLEAN NOT NULL DEFAULT TRUE.
- `password_version` BIGINT NOT NULL DEFAULT 1.
- `last_login_at` DATETIME NULL.
- `failed_login_count` INT NOT NULL DEFAULT 0.
- `locked_until` DATETIME NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique constraint on `(username)` after normalization.
- Indexes: `(status)`, `(person_id)`, `(locked_until)`.
- No FK or ORM relation to Person. The application enforces at most one Active account per `person_id`.

### 5.2 `credentials`

- `id` CHAR(36) UUID PK.
- `user_id` CHAR(36) UUID NOT NULL FK -> `users.id` ON DELETE CASCADE.
- `password_hash` VARCHAR(255) NOT NULL.
- `hash_algorithm` VARCHAR(32) NOT NULL.
- `password_changed_at` DATETIME NOT NULL.
- `policy_version` VARCHAR(32) NOT NULL.
- `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL.
- Unique constraint on `(user_id)` (one credential row per user).
- No Query or Resource returns `password_hash`.
- Composite index: `(user_id, password_changed_at)` (matches the migration; the previous revision incorrectly listed only the implicit FK index).

### 5.3 `identity_sessions`

- `id` CHAR(36) UUID PK.
- `user_id` CHAR(36) UUID NOT NULL FK -> `users.id` ON DELETE CASCADE.
- `token_hash` CHAR(64) NOT NULL UNIQUE.
- `password_version` BIGINT NOT NULL.
- `issued_at` DATETIME NOT NULL.
- `expires_at` DATETIME NOT NULL.
- `revoked_at` DATETIME NULL.
- `last_seen_at` DATETIME NULL.
- `metadata` JSON NOT NULL.
- Indexes: `(user_id, revoked_at, expires_at)`, `(expires_at)`.

### 5.4 Tables that are NOT created by Identity

The previous revision listed an `account_recovery_events` table with columns `id`, `user_id`, `requested_by_user_id`, `action`, `reason`, `status`, `completed_at`, `created_at` and three indexes. The Identity migration set (`CreateIdentityAccountTables.php`, `ZAddIdentityCredentialCoreTables.php`, `ZCreateDevelopmentFixtureAccountsTable.php`) does not create that table. Recovery actions are stored through standard account lifecycle events and the `outbox` rather than a dedicated recovery table.

The full set of tables owned by Identity is:

- `users`, `credentials`, `identity_sessions`
- `identity_person_account_claims`, `identity_person_provisioning`, `identity_person_event_watermarks`
- `identity_inbox`, `identity_idempotency_keys`
- `identity_password_history`, `identity_activation_tokens`, `identity_totp`, `identity_auth_attempt_ledgers`

## 6. Commands, queries, and events

### 6.1 Commands

Each command is implemented by exactly one feature handler under `apps/api/Modules/Identity/Features/`.

- `CreateUserAccount` — `UserAccountHandler`.
- `ActivateUserAccount` — `UserAccountHandler`.
- `DisableUserAccount` — `UserAccountHandler`.
- `ArchiveUserAccount` — `UserAccountHandler`.
- `UnlockUserAccount` — `UserAccountHandler`.
- `AuthenticateUser` — `AuthenticationHandler`.
- `ChangeOwnPassword` — `CredentialHandler`.
- `ForcePasswordChange` — `CredentialHandler`.
- `RevokeUserSessions` — `SessionHandler`.
- `RevokeSession` — `SessionHandler`.
- `ActivateAccountToken` — `ActivationHandler`.
- `EnableTotp` / `ConfirmTotp` / `DisableTotp` — `TotpHandler`.
- `ConsumeOrganizationPersonEvent` — `ConsumeOrganizationPersonEventHandler`.

> **Drift correction:** The previous revision listed `CreateAccountRecoveryEvent` and `CompleteAccountRecovery` as commands. No such command or handler exists. Recovery flows use the standard lifecycle commands above; no reusable recovery token survives the procedure.

Each command flows through a handler that owns the transaction that mutates an account's identity. There is no `CommonAuthService` writing into Identity tables from outside the module.

### 6.2 Queries

- `GetUserIdentity` — `UserAccountHandler`.
- `GetActiveUserAccount` — `UserAccountHandler`.
- `ResolvePrincipalContext` — exposed as a contract (`ResolvePrincipalContext.php`).
- `ResolveUserForPerson` — exposed as a contract (`ResolveUserForPerson.php`).
- `ResolveAccountEntitlement` — exposed as a contract (`ResolveAccountEntitlement.php`).
- `ResolveDevelopmentFixturePrincipal` — exposed as a contract (`ResolveDevelopmentFixturePrincipal.php`).
- `ListUserSessions` — `SessionHandler`.
- `GetPasswordPolicy` — `PasswordPolicy`.
- `GetAccountLockState` — derived from `users.locked_until` and `failed_login_count`.
- `IsAccountActive` — derived from `users.status = 'active'`.

> **Drift correction:** The previous revision listed `GetIdentitySummary` and `GetAccountRecoveryHistory` as Identity queries. They are not exposed as contract interfaces; `ResolvePrincipalContext` is the published summary contract, and there is no recovery-history table to query.

Queries return display data or specific contracts; they never return hashes, tokens, or secrets.

### 6.3 Domain and application events

- `UserAccountCreated`
- `UserAccountActivated`
- `UserAccountDisabled`
- `UserAccountArchived`
- `UserPasswordChanged`
- `UserPasswordChangeRequired`
- `UserAccountLocked`
- `UserAccountUnlocked`
- `UserSessionCreated`
- `UserSessionRevoked`
- `UserSessionsRevoked`
- `AccountAuthenticationFailed`

> **Drift correction:** The previous revision listed `UserAccountChanged`, `AccountRecoveryStarted`, and `AccountRecoveryCompleted` as published events. `UserAccountChanged` is not a published integration event, and the two `AccountRecovery*` events have no backing aggregate or table.

Events that Audit or Notifications need are stored in the outbox within the owning transaction.

## 7. State machines

### 7.1 UserAccount

- `Pending` --(`ActivateUserAccount`)--> `Active`.
- `Active` --(failed-attempt threshold)--> `Locked`.
- `Locked` --(lock period expires or `UnlockUserAccount`)--> `Active`.
- `Active` --(`DisableUserAccount`)--> `Disabled`.
- `Locked` --(`DisableUserAccount`)--> `Disabled`.
- `Disabled` --(`ActivateUserAccount`)--> `Active` after governed administrative verification.
- `Pending` / `Active` / `Locked` / `Disabled` --(`ArchiveUserAccount`)--> `Archived`.
- `Archived` is final and is not reactivated; a new account is created after Person reference verification if needed.

### 7.2 UserSession

- `Issued` --(first request)--> `Active`.
- `Active` --(expires)--> `Expired`.
- `Issued` / `Active` --(revoke, password change, disable)--> `Revoked`.
- `Expired` and `Revoked` are final; the session is not reused.

### 7.3 Credential

- `MustChange` --(successful password change)--> `Usable`.
- `Usable` --(`ForcePasswordChange`)--> `MustChange`.
- A password change increments `password_version` and invalidates sessions per policy.

## 8. Invariants

- Two accounts never share the same `username` after normalization.
- An account in `Pending`, `Locked`, `Disabled`, or `Archived` state never creates a session and never executes an access decision.
- At most one `Active` account per Person. Service accounts and break-glass accounts are not linked to a Person.
- Identity records the latest applied `person_version` against the inbox and ignores duplicates or older versions.
- `PersonAccessStatusChanged(Suspended|Left)` transitions the linked account to `Disabled` and revokes its sessions; a return to `Active` is not automatic.
- Plaintext passwords are never stored and never written to logs, events, or HTTP errors.
- Every new password applies the current security floor, blocks common values, and uses an approved local hash algorithm.
- A previously used password is never reused within the history window defined by policy.
- Every session carries a `password_version` that matches the current version when the request is accepted.
- A password change in a sensitive state atomically invalidates the affected sessions alongside the version increment.
- A lockout never escalates to a permanent disable without a logged administrative command.
- An employee never creates their own account and never changes their own `person_id`, status, or capabilities.
- Identity never owns a record visibility decision and never assigns a role or capability.
- Every recovery action records an executor, a reason, and an outcome, and never reveals the old password.
- Every important change writes an outbox event inside the transaction that mutated the Identity account.

## 9. Permissions

- The super admin creates an account, disables it, reactivates it, forces a password change, and ends sessions.
- An active user changes only their own password, after proving the active session and the current password or after a completed recovery.
- A regular user cannot see another user's sessions or detailed attempt history.
- Authorization consumes only `IsAccountActive` and `ResolvePrincipalContext`, then issues the central decision; Identity never rebuilds RBAC or ABAC for it.
- Linking a user to a person or changing person data goes through governed Organization contracts; Identity never creates a position or assignment.
- A successful login does not implicitly grant any organizational scope.

## 10. Failure modes

- Unknown username or wrong password: a generic result that does not reveal which one failed, with the counter incremented per policy.
- Threshold exceeded: temporary lockout, revoke sessions when necessary, audit event.
- Non-Active account: login refused without creating a Session.
- Weak or common password: rejected with field errors, no secret value stored.
- Password change attempt from a stale session: refused after `password_version` comparison.
- Username conflict on creation: rollback with an interpretable message that does not reveal another account.
- Outbox save failure: rollback the account change and never report a false success.
- Session store failure: no partial login; the request is retried with a generic operational message.
- Recovery expiry or replay: refused and the event closed without leaking details.

## 11. Tests

- Unit: username normalization and duplicate prevention.
- Unit: password-policy enforcement, common-value prevention, history check.
- Unit: transitions `Pending` / `Active` / `Locked` / `Disabled` / `Archived`.
- Feature: a successful login creates a Session without revealing a secret.
- Feature: a failed login locks the account after the configured threshold.
- Feature: a password change increments `password_version` and invalidates affected sessions.
- Feature: disabling an account blocks login and ends existing sessions.
- Authorization contract: an active account passes the identity fact; a disabled account makes Authorization deny any access attempt.
- Security: no hash, token, or password appears in responses, logs, or event payloads.
- Integration: replaying the outbox does not duplicate the account-change effect.
- Integration: a duplicate or older `person_version` does not duplicate provisioning or session revocation.
- Boundary: Authorization and Organization never read the `credentials` table directly.
- Recovery: the recovery executor cannot read the old password and cannot replay the procedure.

## 12. Dependencies

- Depends on `Shared/Clock` and `Shared/Identifiers`.
- Depends on the `ValidatePersonReference` contract published by Organization and consumes provisioning events and access-state events without owning Person or Position.
- Publishes account-status and identity-summary contracts to Authorization.
- Does not depend on Authorization to validate the password and does not depend on WorkRecords or Workflow.
- Consumes Audit and Outbox through technical contracts or events; does not write to the Audit table directly.
- WorkRecords, Workflow, and other modules reference `user_id` and re-verify Identity for sensitive operations.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-18 | Identity module owner | Unified account states, Person reference, and idempotent consumption per ADR-024 |
| 1.0.0 | 2026-07-15 | Identity module owner | Unified front-end contract and module boundaries |
| 1.1.1 | 2026-07-23 | Domain audit pass | Dropped the unsupported `account_recovery_events` table, `AccountRecoveryEvent` aggregate, `UserIdentitySummary`, `PasswordPolicySnapshot`, `SessionFingerprint` value objects; UUIDv7 claim softened to UUID; commands/queries aligned to actual feature handlers and contracts |
