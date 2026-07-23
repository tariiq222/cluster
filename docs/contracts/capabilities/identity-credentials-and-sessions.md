---
doc_id: CON-CAP-IDN-001
title: Local Credentials and Session Contract
type: contracts
status: accepted
version: 1.0.0
date: 2026-07-18
owner: Software Engineering Office
reviewers:
- Information Security Officer
- Identity Module Owner
classification: internal
review_cycle: on every change
sources:
- docs/adr/012-local-identity-and-session-security.md
- docs/adr/024-organization-identity-import-boundaries.md
- docs/data-security/identity-session-security.md
- docs/domain/identity.md
references:
- docs/contracts/api/openapi.yaml
- docs/contracts/api/w1-2.openapi.yaml
---
# Local Credentials and Session Contract

## Status and Outcome

**Implementation status:** `implemented` (Phase B + D update)

The contract defines these binding limits: CSRF proof required for every state change; single-use activation link; an account stays in `pending` until it has a usable login credential; TOTP is mandatory for administrative accounts; the server-side session is opaque and stored server-side. on the server without JWT.

This contract specifies the target behavior for credentials, activation, and sessions. Publishing the experimental `/auth/login` route alone does not implement the contract; an account created by Identity without a credential remains in `pending`.

Identity owns credentials, sessions, and activation alone. Organization and Authorization do not write to Identity tables, and no contract or event carries a password, activation code, TOTP secret, or explicit session identifier.

## Credential Creation and Activation Lifecycle

1. A `UserAccount` is created in state `pending` and without a login-capable Credential.
2. Identity issues a single-use activation link bound to the account and purpose. The server stores only the digest of the code and sets an expiry according to the configured Identity policy; this release does not establish a new numeric duration.
3. Reissue invalidates every prior activation link for the account. The link is also invalidated after the first successful consumption, account archival, or expiry.
4. The user sets a password that complies with the current Identity policy. The value is neither stored nor returned in the response or events.
5. If the account is authorized for an administrative action, TOTP enrolment and verification of a valid code complete before activation.
6. Credential creation, account transition, and the Outbox event are written in a single Identity transaction. If any step fails, the account remains `pending` and no partial session is created.

Provisioning does not transfer passwords, MFA, or recovery. The presence of a username or a valid Person does not transition the account to `active` without successful activation consumption and a complete credential.

## Session Contract

- The session identifier is an opaque random value. Session state, revocation, expiry, and the password version are stored on the server, and only the digest of the identifier is stored instead of the reusable value.
- The identifier is sent only in a cookie carrying `Secure`, `HttpOnly`, and `SameSite=Lax`. The login response does not return `access_token` or `refresh_token`, and the browser client does not need to store a Bearer token.
- Every state-changing request requires session-bound CSRF proof that the backend verifies before executing the command. The cookie alone is not sufficient. CSRF failure does not change the account or session and does not count as a password attempt.
- Idle and maximum durations, session count, and expiry follow the approved session security policy. Password change, account disable, or administrative capability revocation invalidates the affected sessions server-side.
- An account in `pending`, `locked`, `disabled`, or `archived` creates no session. Login and activation errors are generic and do not reveal the existence of a username, account, or link validity.

## TOTP for Administrative Accounts

- TOTP is required before creating an administrative session or elevating a daily session to an administrative context. A successful password alone is not sufficient, and no daily session bypasses this requirement.
- The TOTP secret is encrypted inside Identity, is never shown after enrolment, and never appears in logs or events. Recovery codes, if published by a later recovery slice, are single-use and stored as a digest.
- Changing or disabling the TOTP secret is a sensitive administrative action that requires a reason and audit and invalidates administrative sessions.
- Break-glass is not a silent exception; it remains under its own dual, time-bounded contract in the Identity policy.

## Capability Operations

| Operation | Minimum inputs | Outcome |
|---|---|---|
| Issue or reissue activation | `account_id`, authorized actor, correlation | Local governed delivery without returning the code in the admin API |
| Consume activation | Single-use code, new password, and TOTP proof when administrative | Credential and `active` account, or atomic failure keeping it `pending` |
| Login | Username, password, and TOTP code when administrative | Opaque session cookie and no token in JSON |
| Logout | Session cookie and CSRF proof | Idempotent invalidation of the current session |
| End sessions | `account_id`, reason, authorized actor | Invalidate the account's sessions and audit the outcome |

The implementation slice publishes these operations in OpenAPI before building a client for them. Responses use `application/problem+json` and `X-Correlation-ID`, and issue/revoke operations use `Idempotency-Key`. The current paths are live (see `apps/api/routes/web.php`).

## Reconciliation of Prior Contracts

- `docs/data-security/identity-session-security.md` previously described session contents as JWT and refresh token. This contract governs the new transport choice: an opaque server-side session with no JWT or refresh token in the browser, while the session durations and revocation rules in the security document remain in effect.
- `docs/contracts/api/w1-2.openapi.yaml` currently publishes `W12Session.access_token` and Bearer for the W1.2 fixture. That is a fixture representation and is not the real Credential contract. The snapshot is not modified until the implementation slice runs and a client is generated from a new compatible release.
- The old Login limit in OpenAPI does not lower the creation policy. When the real Credential is implemented, the approved Identity policy applies, including the current 14-character minimum.

## Acceptance Criteria

- An activation link cannot be used twice, reissue invalidates the previous one, and the code never appears in any explicit storage, logs, or events.
- An account without a Credential remains `pending` and fails login without creating a Session.
- A successful standard user login returns a cookie with all three attributes and does not return a token in the body.
- A state-change request without a valid CSRF is rejected without business effect.
- An administrative account without a complete TOTP does not become `active` and creates no administrative session.
- Account disable and password change invalidate all of the account's sessions server-side.
