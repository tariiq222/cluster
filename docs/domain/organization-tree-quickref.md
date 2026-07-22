---
doc_id: DOM-ORG-002
title: مرجع شجرة المنظمة السريع
type: domain
status: draft
version: 1.0.0
date: 2026-07-21
owner: مالك موديول Organization
reviewers:
- مسؤول هندسة البرمجيات
classification: internal
review_cycle: مع كل تغيير في النموذج العلائقي
sources:
- docs/domain/organization-and-people.md
references:
- docs/architecture/dependency-rules.md
- docs/contracts/capabilities/temporary-assignment.md
---

# Organization Tree — Quick Reference

This document is a single-page visual summary of how `Cluster`, `Facility`,
`OrgUnit`, and `Position` hang together in the Organization module. It is
intentionally short and diagram-first; for authoritative aggregates, invariants,
capabilities, state machines, and full schema see
[organization-and-people.md](./organization-and-people.md).

## 1. Hierarchy at a Glance

```
Cluster (single root)
│
├── Facility                    [facilities.cluster_id  → clusters.id]
│     └── OrgUnit               [organization_units.parent_type='facility']
│           └── OrgUnit         [organization_units.parent_type='unit']
│                 └── Position  [positions.organization_unit_id  → organization_units.id]
│                       ├── Position (manager)         [positions.manager_position_id  → positions.id]
│                       └── Assignment                 [assignments.{person_id, position_id}]
│
└── OrgUnit (cluster-level)     [organization_units.parent_type='cluster']
      └── ...
```

## 2. Polymorphic Parent of `organization_units`

`organization_units` carries two parent columns that together identify the
father: `parent_id` (UUID) and `parent_type` (string, 16). The valid
`parent_type` values are:

| `parent_type` | Meaning | Example father |
|---|---|---|
| `cluster` | Unit hangs directly off the cluster root | Cluster-level shared-service unit |
| `facility` | Unit hangs off a facility | A hospital department |
| `unit` | Unit hangs off another unit | A subdivision nested N levels deep |

`cluster_id` is denormalised on every row to make ABAC scope lookup cheap; do
not remove it. The materialized `path_cache` and `depth` mirror the same tree
for fast ancestor queries.

## 3. Foreign-Key Map

| Child table | Column | → Parent table | On delete |
|---|---|---|---|
| `facilities` | `cluster_id` | `clusters` | `restrict` |
| `organization_units` | `cluster_id` | `clusters` | `restrict` |
| `organization_units` | `unit_type_id` | `unit_types` | `restrict` |
| `organization_units` | `(parent_id, parent_type)` | polymorphic (`clusters` / `facilities` / `organization_units`) | enforced in domain |
| `positions` | `organization_unit_id` | `organization_units` | `restrict` |
| `positions` | `manager_position_id` (nullable) | `positions` | self-referential — enforced in domain |
| `assignments` | `person_id` | `people` | `restrict` |
| `assignments` | `position_id` | `positions` | `restrict` |
| `temporary_assignments` | `person_id` | `people` | `restrict` |
| `temporary_assignments` | `organization_unit_id` | `organization_units` | `restrict` |

All structural foreign keys use `restrictOnDelete` — a node with active
children cannot be deleted at the database level.

## 4. Two Independent Reporting Lines

The Organization module deliberately keeps **two separate reporting chains**
that must not be confused:

1. **Geographic / operational chain** — `cluster → facility → unit → unit`.
   This is the tree you see in `/admin/organization/structure`.
2. **Supervisory chain** — `position.manager_position_id → position`. This is
   a self-reference on `positions` and is independent of the unit tree.

A position's `manager_position_id` may point to a position in any unit in any
facility in any cluster as long as domain rules allow it; it is not forced to
follow the unit parent chain.

## 5. How People Attach

People are never joined to the tree directly. They enter the Organization
domain only through assignment tables:

| Table | What it does | Lifetime |
|---|---|---|
| `assignments` | Bind a `person` to a `position` for an open-ended period | Permanent (start_date, no end_date or future end_date) |
| `temporary_assignments` | Bind a `person` to a `unit` directly for a finite period, with extra `temporary_assignment_capabilities` | Date-bounded |

If a person needs to do work for a unit but no position exists, create a
`temporary_assignment`, not a structural change.

## 6. Where Each Layer Is Created in the Web App

| Concept | Screen | Form / API entry | Capability |
|---|---|---|---|
| Cluster root | `/admin/organization` (panel "Cluster root") | `createCluster(...)` | `organization.cluster.manage` |
| Facility | `/admin/organization` (panel "Facilities") | `createFacility(...)` | `organization.facility.manage` |
| OrgUnit | `/admin/organization/structure` (tree pane) | domain command on `OrganizationUnitHandler` | `organization.unit.manage` |
| Position | `/admin/organization/structure` (positions pane) | domain command on `PositionHandler` | `organization.position.manage` |

The Facility form only renders after the Cluster root exists; creating a
facility without a cluster is a UI-level impossibility and the API validates
the same pre-condition.

## 7. Unique Constraints That Shape the Tree

- `organization_units` has `unique (parent_type, parent_id, code)` — a unit
  code must be unique within its immediate father (cluster, facility, or
  unit) but the same code is allowed at different levels or under different
  fathers. This is the contract that lets a sector and a section be named
  the same code without collision.
- `positions` has `unique (organization_unit_id, code)` — same rule, scoped
  to the owning unit.
- `unit_types.code` is unique across the cluster; renaming a unit type is a
  migration, not an in-place edit.

## 8. Anti-Patterns the Model Forbids

- Creating a unit that has no father. The cluster root must exist first; every
  unit hangs off `cluster`, `facility`, or `unit`.
- Creating a facility before the cluster root. Same precondition as above.
- Linking `position.manager_position_id` cyclically. Domain handlers reject
  cycles; the database does not enforce this, so an import job that produces a
  cycle must be reviewed and rejected manually.
- Deleting a unit or facility that still has children. `restrictOnDelete` is
  the database-side guard; the domain layer is expected to disallow
  deactivation (status='inactive') while children remain active for the
  same reason.
- Treating Facility as a unit type. Facility is its own aggregate; unit
  types are only `sector`, `department`, `section`, `unit`, and any
  additional row added by the super admin through `unit_types` table.

## 9. Sibling Ordering and Reorder Endpoint

`organization_units` carries a denormalised `sort_order INTEGER DEFAULT 0`
column (with the index `organization_units_sibling_order_index` on
`(parent_type, parent_id, sort_order)`) so sibling rendering is stable
across browsers, reloads, and freshly issued tokens.

- The list endpoint returns units ordered by
  `(parent_type, parent_id, sort_order, code, id)`; pagination cursors
  encode the full composite key so paging stays consistent under the new
  order.
- `POST /api/v1/organization/units/reorder` rebalances the whole tree in one
  transaction: groups by parent, sorts each group by (type priority then
  code), assigns sequential `sort_order` starting at 1, emits the outbox
  event `com.cluster.organization.organizationunitsreordered.v1`, and writes
  one `access_decisions` row per call. Requires `organization.unit.manage`
  and is idempotent.
- The web app exposes this as the **إعادة الترتيب** button next to
  *إضافة وحدة* in `OrganizationStructure`. On success it clears the
  `cluster.org-board.layout.v1` localStorage key so the board re-lays out
  from the freshly ordered data instead of the user's drag history.

## 10. Source Cross-Reference

The columns, foreign keys, and parent-type values documented above are
sourced from the API module `apps/api/Modules/Organization/Infrastructure/Persistence` —
specifically the `CreateOrganizationTreeTables` migration for `clusters`,
`facilities`, `unit_types`, `organization_units`, and `positions`; the
`ZCreateOrganizationTemporaryAssignmentsTable` migration for
`temporary_assignments` and `temporary_assignment_capabilities`; and the
`DatabaseResolveOrganizationScopeAncestry` and
`DatabaseResolvePersonOrganizationScope` resolvers for the person-scope walk
along the `parent_type` chain. On the front end the matching screens live at
`apps/web/src/features/organization/OrganizationOverview.tsx` and
`OrganizationStructure.tsx`.

For full aggregates, read models, events, and state machines see
[organization-and-people.md](./organization-and-people.md). For the
temporary-assignment capability contract see
[contracts/capabilities/temporary-assignment.md](../contracts/capabilities/temporary-assignment.md).

## سجل التغيير

| الإصدار | التاريخ | المؤلف | ملخص التغيير |
|---|---|---|---|
| 1.0.0 | 2026-07-21 | مالك موديول Organization | إنشاء مرجع سريع للعلاقات بين Cluster/Facility/OrgUnit/Position |
