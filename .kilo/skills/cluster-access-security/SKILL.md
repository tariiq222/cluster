---
name: cluster-access-security
description: Enforce centralized deny-by-default RBAC and ABAC across protected resources. Use for Identity, Authorization, Documents, Tasks, Workflow, Search, Reporting, export, or download changes.
---

# Cluster Access Security

Read when needed:

- `docs/data-security/authorization-model.md`
- `docs/data-security/file-security.md`
- `docs/adr/004-authorization-and-isolation.md`
- `docs/plans/release-1/w1-3-frontend-slices.md`

## Invariants

- Identity derives `PrincipalContext` from the server session. The browser never supplies trusted roles, scope, or record facts.
- The owning module builds trusted `RecordFacts` from its data.
- Authorization owns policy evaluation and fails closed on missing or invalid inputs.
- Explicit deny, insufficient classification, and record-state restrictions override allow.
- Apply the same resource and field decision to API reads and writes, search, reports, exports, notifications, and downloads.
- Filter list scope before pagination and before exposing names, titles, counts, or existence.
- Hidden fields never reach JSON. Masked fields never reach clients in raw form. Read-only fields reject writes.
- React consumes `allowed_actions` and field access for experience only.
- Task access never grants source-resource access.
- Document access checks both the document and linked resource, preserves quarantine, and records sensitive access.

Tests must include positive and negative scope cases, expiry, explicit deny, classification, fail-closed behavior, and consistency across derived read surfaces when affected.
