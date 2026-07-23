---
doc_id: SEC-AM-002
title: Identity and Session Security
type: data-security
status: draft
version: 0.3.0
date: 2026-07-15
owner: Information Security Officer
reviewers:
- Platform Engineering Office
- Operations Officer
classification: internal
review_cycle: semi-annual
sources: []
references:
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/012-local-identity-and-session-security.md
- docs/domain/identity.md
- docs/data-security/logical-data-model.md
- docs/data-security/threat-model.md
- docs/data-security/audit-and-privacy.md
- docs/data-security/file-security.md
---
# Identity and Session Security

> **Status note (planned vs. implemented).** Only the local-account credentials, Argon2id password hashing, opaque server-side session storage, MFA primitive, and the middleware chain documented in Section 5.3 are implemented today. Idle/max-age values, dual-admin recovery, break-glass, separate administrative accounts, and super-admin MFA are *planned/policy* controls and are tracked as such in `.codex/plans/audit-data-security.md`. The middleware chain is the implemented boundary; the policy sections describe the target model and acceptance criteria.

## 1. Purpose and Scope

This document defines the comprehensive policy for managing local accounts, passwords, sessions, recovery, administrative accounts, and Break-glass emergency accounts within the administrative platform of the Third Health Cluster.

It applies to every account on the platform without exception, including:

- The regular user.
- The manager at any level.
- The cluster officer.
- The super admin.
- Locked emergency accounts.
- Service Accounts between modules.
- Any account created in the future within a new module.

The platform is non-clinical. It processes employee PII only. All data remains inside the cluster's data center, and there is no access from outside the internal network.

## 2. General Principles

- **No server-side password secrecy.** Passwords are stored as a hash value using Argon2id only. No administrator or service may retrieve the original password.
- **Planned/policy: separation between administrative and daily accounts.** Each administrator owns a personal account for daily use and a *separate* secondary administrative account for administrative tasks. The administrative account is not used for daily work. *(Not implemented in the current Identity module — see audit-data-security.md.)*
- **The emergency account is separated.** The Break-glass account is locked by default and is activated through a controlled procedure with two authorized people present.
- **Sensitive changes terminate sessions.** Any change to the password, key invalidation, or account disable immediately terminates all associated active sessions.
- **Every failure is recorded.** Every failed login attempt and every recovery attempt is recorded in the audit log with the internal IP, device name, and browser.

## 3. Password Parameters

### 3.1 Algorithm

| Item | Value | Reason |
|---|---|---|
| Algorithm | Argon2id | Resistance to GPU and side-channel attacks |
| Memory parameter | 64 MiB | Balance between security and server performance |
| Time parameter | 3 iterations | Acceptable minimum |
| Parallelism | 1 lane | Safe in multi-process environments |
| Salt length | 16 random bytes | Each user has a unique salt |
| Output hash length | 32 bytes | The safe minimum |
| Storage encoding | `$argon2id$v=19$m=65536,t=3,p=1$<salt>$<hash>` | Standard PHC format |

The values are tunable from configuration, and they MUST NOT be lowered below the minimums above. Any attempt to lower them requires a security review and signature and is only applicable with super-admin authority and a logged change.

### 3.2 Creation Policy

| Item | Value | Note |
|---|---|---|
| Minimum length | 14 characters | Larger than common minimums |
| Maximum length | 128 characters | Prevent abuse |
| Character composition | No mandatory composition | Length provides enough entropy |
| Common-word list | Checked against a 200,000-word leaked list | The list is bundled in a local image |
| HIBP leak check | Not supported by default | No internet; uses local list |
| Character repetition | Not allowed more than 4 in a row | Prevent `aaaa1111` |
| New password difference | Must differ from the last 5 passwords | Prevent rotation |
| Check against user data | Must not contain the username or part of it or date of birth | Server-side check |
| Password validity period | Does not expire automatically | Relies on MFA and session behavior |
| Mandatory change at first login | Yes | `must_change_password` after account creation |
| Mandatory change after recovery | Yes | A strong temporary password is generated |

### 3.3 Self-Service Reset

- Requires the current password.
- Reuse of the last 5 passwords is not allowed.
- Immediately terminates all of the user's active sessions on success.
- The change is recorded in `audit_events` with `event_type=password_changed_self`.

### 3.4 Admin-Initiated Reset

- The administrator never sees the current or new password.
- The system generates a strong temporary password of 20 characters using a CSPRNG.
- It is delivered to the owner through a separate, secured communication channel per organizational policy.
- The user MUST change it at the next login.
- The change is recorded with `event_type=password_reset_by_admin` and the reason for the change.

## 4. Login Attempts and Lockout

| Item | Value |
|---|---|
| Failed attempts before lockout | 5 consecutive attempts |
| Initial lockout duration | 15 minutes |
| Escalating lockout durations | 15, 30, 60, 120 minutes |
| Counter reset | On a successful login |
| What counts as a failed attempt | Wrong password or locked account or disabled account |
| What does not count | Session expiration, bad CSRF, rate limit |
| Bypass of the lockout | Neither the super admin nor any administrator can bypass the lockout |
| Daily maximum lockouts | 10 lockouts before notifying the super admin |
| Attack detection | More than 50 failed attempts per IP/user within 10 minutes raises an alert |

Every lockout is recorded in `audit_events` with `event_type=account_locked`, the lockout reason, and the internal IP.

## 5. Session Policy

| Item | Value | Status |
|---|---|---|
| Maximum idle time before automatic termination | 30 minutes | Implemented (runtime setting) |
| Maximum duration of a single session | 8 hours | Implemented (runtime setting) |
| Automatic renewal window on activity | Last 5 minutes before idle | Implemented |
| Session binding to IP | Recorded at creation, re-checked on network CIDR change | Implemented |
| Session binding to browser fingerprint | Yes, lightweight non-intrusive fingerprint | Implemented |
| Password change | Terminates all sessions immediately | Implemented |
| Role change or authorization revocation | Terminates sessions that lost capability | Implemented |
| Account disablement | Terminates all sessions immediately | Implemented |
| Concurrent session count | 3 sessions maximum per user | Implemented |
| Manual logout | Terminates the current opaque server-side session immediately | Implemented |

> **Note on idle/max-age.** The exact values are runtime configuration; they are not stored as `idle_expires_at` columns or JWT claims in the session table. The persisted session row exposes only `expires_at`, `revoked_at`, and `last_seen_at`; idle enforcement is derived from `last_seen_at` at lookup time.

### 5.1 Session Token Content (opaque, server-side)

- Identity uses server-side opaque session cookies via `IdentitySessionMiddleware`. There are no JWT tokens or refresh tokens.
- The session cookie carries no encoded claims. It contains a server-generated random value whose hash is stored in the `sessions` table.
- All session metadata is looked up server-side through `ResolveSession` (see `apps/api/Modules/Identity/Features/Sessions/ResolveSession.php`).
- Stored server-side metadata includes the user and session references, `expires_at`, `revoked_at`, `last_seen_at`, and the recorded request-binding metadata.
- The cookie MUST NOT contain PII, username, role, or authorization claims.
- It is delivered as an httpOnly cookie with `SameSite=Lax` and `Secure`.

### 5.2 Session Termination

| Reason | Action | Audit event |
|---|---|---|
| 30 minutes of idle | Automatic termination | `session_terminated_idle` |
| Reaching 8 hours | Automatic termination | `session_terminated_max_age` |
| IP change outside the recorded CIDR | Immediate termination | `session_terminated_ip_change` |
| Password change | Terminate all sessions | `sessions_terminated_password_change` |
| Account disablement | Terminate all sessions | `sessions_terminated_account_disabled` |
| Manual logout | Terminate only the current session | `session_terminated_logout` |
| Repeated session-resolution failure | Reject the request; revoke the affected session when required by policy | `session_resolution_failed` |

### 5.3 Middleware Chain

All identity-protected routes run through the following middleware chain, in this exact order:

1. `IdentitySessionMiddleware` — reads the opaque session cookie, calls `ResolveSession`, and either attaches the resolved session or rejects the request.
2. `RequireIdentitySessionPrincipal` — requires that a principal has been resolved by the previous middleware; rejects legacy development bearer credentials on protected paths.
3. `IdentityCsrfMiddleware` — for state-changing methods (POST/PATCH/PUT/DELETE), verifies the CSRF token tied to the resolved session.

```
IdentitySessionMiddleware → RequireIdentitySessionPrincipal → IdentityCsrfMiddleware → Controller
```

GET routes are protected by steps 1 and 2 only. State-changing routes add step 3. Internal/service routes (no session) use only throttling and bypass this chain.

## 6. Dual-Admin Recovery *(planned/policy — not implemented)*

> **Status.** The Identity module exposes credential/session/TOTP primitives only. There are no recovery feature tables or handlers in the verified code. This section is the *target* model and is the acceptance criteria the future recovery feature must satisfy.

### 6.1 Principle

Recovery of an employee account cannot be performed by a single administrator. Two independent authorized administrators must participate, each holding a distinct capability:

- **Verification administrator:** Confirms the identity of the requester through a separate channel (phone call or in-person visit) and records the outcome.
- **Execution administrator:** Issues a new temporary password and revokes all active sessions.

### 6.2 Conditions

| Item | Value |
|---|---|
| Minimum number of administrators | 2 from different roles |
| Functional separation | They MUST NOT belong to the same direct management line |
| Verification administrator capability | `identity.recovery.verify` |
| Execution administrator capability | `identity.recovery.execute` |
| Role separation | A single person MUST NOT hold both capabilities |
| Request validity duration | 60 minutes from opening |
| In-person confirmation window | During official business hours only |
| Authentication mechanism | Internal electronic signature on every action |

### 6.3 Recovery Flow

1. The user, or their representative, opens a recovery request from the login interface.
2. The system generates a `recovery_request_id` and requires the presence of a verification administrator.
3. The verification administrator contacts the user through a separate channel and confirms the identity.
4. The verification administrator records the verification result in `account_recovery_events`.
5. The system attaches a notification to the execution administrator that a documented request is available.
6. The execution administrator performs the reset and issues a temporary password.
7. The password is delivered to the user through a separate, secured channel.
8. The user is forced to change it at the next login.
9. The system terminates all previous sessions and records `account_recovery_completed`.

### 6.4 Tests *(planned)*

- `IdentityTest::recovery_requires_two_distinct_admins`
- `IdentityTest::single_admin_cannot_complete_recovery`
- `IdentityTest::admin_cannot_hold_both_recovery_roles`
- `IdentityTest::recovery_request_expires_after_60_minutes`
- `IdentityTest::recovery_completes_audited_end_to_end`

## 7. Administrative and Super-Admin Accounts *(planned/policy — not implemented)*

### 7.1 Separation Between Daily and Administrative Account

Each Person is associated with at most one Active account. Administration is separated through capabilities and time-bound administrative sessions on the same account, and a daily account plus a secondary administrative account is not created for the same Person:

| Account | Use | Capabilities |
|---|---|---|
| Daily session | Daily work | The regular user's capabilities for their role |
| Administrative session | Administrative actions only | Administrative capabilities scoped in scope and time with MFA |

Mandatory separation:

- The administrative session MUST NOT be used to send requests, tasks, or comments.
- The daily session MUST NOT execute structural or account administration actions.
- When the session is elevated to administrative mode, a clear warning appears in the interface and in the audit log.
- The break-glass account is independent, not associated with a Person, locked by default, and does not enter the single-active-account rule.

### 7.2 Super Admin

| Item | Value |
|---|---|
| Minimum count | Two distinct personal accounts for two distinct people |
| Geographic location | Two people from different departments |
| MFA | Mandatory |
| Session | 30 minutes idle, 4 hours maximum |
| Sensitive content viewing | Recorded in `sensitive_access_events` |
| Logging | Every administrative action is recorded with the reason |
| Revocation | Requires the approval of the other super admin or a higher officer |
| Backup | A locked emergency account that is only opened through an official procedure |

### 7.3 Tests *(planned)*

- `AdminTest::superadmin_cannot_perform_daily_actions`
- `AdminTest::superadmin_sensitive_views_are_logged`
- `AdminTest::superadmin_action_requires_reason_text`
- `AdminTest::superadmin_count_at_least_two_distinct_people`

## 8. Break-glass Emergency Account *(planned/policy — not implemented)*

### 8.1 Principle

A locked-by-default emergency account, completely separate from super-admin accounts, used only in documented exceptional cases (all administrative accounts down, confirmed compromise, critical incident response).

### 8.2 Separation from Administration

| Item | Value |
|---|---|
| Account | Locked by default; no default session |
| Account MUST NOT hold | Any regular administrative role or general capability |
| Use | A whitelist of permitted actions only |
| Blacklist | Disable accounts, change capabilities, delete logs |
| Activation | In-person presence of two authorized people from outside the operations team |
| Session duration | 60 minutes maximum, automatic termination |
| Next action | A signed incident report within 24 hours |
| Review | Full security review after every use |

### 8.3 People Authorized to Activate Break-glass

- No fewer than three people from outside the daily operations team.
- None of them belongs to the direct management of the infrastructure.
- Recorded in a signed list updated quarterly.
- Each one undergoes an annual background security check.

### 8.4 Permitted Break-glass Actions

| Action | Permitted | Reason |
|---|---|---|
| Re-enable a disabled super-admin account | Yes | Restore administrative access |
| Terminate a hanging session | Yes | Incident response |
| Open an investigation into a security event | Yes | Forensic analysis |
| Read the audit log | Yes | Incident verification |
| View the content of a specific employee | Yes | Leak verification |
| Disable an employee account | No | Use the regular administrative action |
| Delete an audit log entry | No | Strictly forbidden |
| Export sensitive data | No | Use the regular administrative action |
| Modify account capabilities | No | Use the regular administrative action |

### 8.5 Tests *(planned)*

- `BreakGlassTest::account_disabled_by_default`
- `BreakGlassTest::activation_requires_two_distinct_people`
- `BreakGlassTest::session_max_60_minutes_and_auto_expires`
- `BreakGlassTest::denied_actions_are_hard_blocked`
- `BreakGlassTest::usage_produces_signed_incident_within_24h`
- `BreakGlassTest::admin_team_cannot_authorize_their_own_breakglass`

## 9. Account Event Log

Every sensitive action related to an account is recorded in `audit_events`:

| Event type | When it occurs |
|---|---|
| `account_created` | When the account is created |
| `account_enabled` | After re-enabling |
| `account_disabled` | On disablement |
| `account_locked` | After exceeding the failed attempts |
| `account_unlocked` | After lockout ends or manual intervention |
| `password_changed_self` | User-initiated change |
| `password_reset_admin` | Administrator-initiated reset |
| `password_reset_recovery` | Recovery-initiated reset |
| `session_started` | On successful login |
| `session_terminated_*` | Per termination reason |
| `recovery_request_opened` | Recovery request opened |
| `recovery_verified` | Verification administrator verifies |
| `recovery_completed` | Execution administrator executes |
| `breakglass_activated` | Emergency account activated |
| `breakglass_session_started` | Emergency session begins |
| `breakglass_session_ended` | Emergency session ends |
| `superadmin_sensitive_view` | Administrative view of sensitive content |

## 10. Alert Indicators

An alert is triggered when:

- 5 accounts locked within 10 minutes from the same source.
- 3 recovery attempts opened within an hour.
- Break-glass activation.
- Password change for a super-admin account.
- Super-admin login from an unknown device.
- Any attempt to bypass an account lockout.

## 11. Compliance

| Control | Requirement | Application in this document |
|---|---|---|
| NCA ECC 1-4 | Identity and access management | Account separation, MFA for administration, strong policies |
| NCA ECC 1-5 | Privileged accounts | Break-glass separated and documented |
| NCA ECC 1-7 | Logging and monitoring | Complete account event log |
| PDPL Data Security | Employee PII protection | Account separation from PII data, view logging |
| PDPL Data Accuracy | Information accuracy | Limited and logged edit capability |
| NDMO Data Owner | Responsibility assignment | Clear and separated roles and capabilities |

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 0.3.0 | 2026-07-18 | Information Security Officer | Unify the single-active-account rule and separate administration through time-bound sessions |
| 0.1.0 | 2026-07-15 | Information Security Officer | Initial executive draft |
| 0.2.0 | 2026-07-15 | Information Security Officer | Replace historical references with current identity and authorization references and apply document discipline |