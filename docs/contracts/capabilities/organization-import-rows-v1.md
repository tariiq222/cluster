---
doc_id: CON-CAP-IMP-001
title: Organization Import Row Contract v1
type: contracts
status: accepted
version: 1.0.0
date: 2026-07-18
owner: Software Engineering Office
reviewers:
- Organization Module Owner
- Information Security Officer
classification: internal
review_cycle: on every change
sources:
- docs/adr/024-organization-identity-import-boundaries.md
- docs/domain/organization-and-people.md
- docs/contracts/api/openapi.yaml
references:
- docs/contracts/capabilities/document-signed-direct-upload.md
- docs/contracts/schemas/facilities-import-row-v1.schema.json
- docs/contracts/schemas/organization-units-import-row-v1.schema.json
- docs/contracts/schemas/positions-import-row-v1.schema.json
---
# Organization Import Row Contract v1

## Status and Scope

**Implementation status:** `implemented` (Phase B + D update)

This contract defines the target behavior for import templates; publishing the schemas alone does not transfer bytes nor apply rows.

v1 defines a single create row that matches the payload of the current Create operation for each of `facilities`, `organization_units`, and `positions`. It does not add persistence or response fields, and does not include update, archive, or move. `people_assignments` remains outside this contract.

## Template to Schema Mapping

- `facilities`: uses [schema v1](../schemas/facilities-import-row-v1.schema.json). Requires `cluster_id`, `type_code`, `code`, and `name`; permits `name_en`.
- `organization_units`: uses [schema v1](../schemas/organization-units-import-row-v1.schema.json). Requires `cluster_id`, `code`, `name`, and `type_code`; permits `parent_id` and `name_en`.
- `positions`: uses [schema v1](../schemas/positions-import-row-v1.schema.json). Requires `organization_unit_id`, `code`, and `title`; permits `manager_position_id`. The published schema also exposes `job_title_id`, but the runtime applicator does not apply this field; it is ignored on import. Operators must supply `title` for every position row.

The current request does not carry `template_version`. The server binds each `template_code` above to v1 server-side and stores the schema id/hash used with the ImportJob. Adding version to HTTP or changing a field's name, type, or constraint requires a new contract release, not a silent change to v1.

## Normalization and Structural Validation

- The first CSV row or the marked XLSX header row uses the field names above literally. Extra or duplicate columns are rejected; the parser does not silently translate domain names like `name_ar` or `title_ar` into new names.
- UUIDs are lowercase UUIDv7. The `type_code` and `code` patterns and text lengths match the Create API. A blank cell is not a value for a required field.
- A blank cell for an optional field normalizes to `null` or the absence of the field per the schema; it does not normalize to a synthetic UUID or required text.
- Schemas set `additionalProperties=false`. The parser does not accept status, id, lock_version, path, depth, actor, or Identity secrets.

## Semantic Invariants Matching Create Operations

### facilities

- `cluster_id` references the single existing Cluster.
- `type_code` references an active, governed FacilityType.
- `code` is unique within the Cluster. Conflicts do not become updates within v1.

### organization_units

- `cluster_id` exists. Absence of `parent_id` or equality with `cluster_id` means a Cluster-typed parent.
- Otherwise the parent must be a non-archived Facility or a non-archived OrganizationUnit within the same Cluster. A parent outside the cluster or invalid is rejected.
- `type_code` is active, and `code` is unique within `(parent_type, parent_id)`. The applicator computes `parent_type`, `path_cache`, and `depth`; it does not accept them from the file.

### positions

- `organization_unit_id` references an existing OrganizationUnit.
- `manager_position_id`, if present, references an existing Position and does not create a self-reference or administrative cycle.
- `code` is unique within the OrganizationUnit. The applicator computes `is_active` and `lock_version` and does not accept them from the file.
- `title` is required for every row and is applied to the Position. `job_title_id` is exposed by the schema for compatibility with adjacent contexts but is not applied by the ImportJob applicator.

## Import Lifecycle and Failure

1. An ImportJob is created only from a `quarantine_object_id` whose result is `clean` and whose purpose is an Organization import matching the template; Organization does not access Object Storage directly.
2. The parser breaks the file into normalized rows, applies the schema, and then the owner invariants without writing business state.
3. The raw payload is stored encrypted, and the API exposes redacted errors by row, field, and code only.
4. Any critical error, including a missing required field or an invalid parent/type, makes the entire file unapplicable. No single valid row is written.
5. The uploader differs from the approver. Only after `approved` does Organization apply all rows and the Outbox in a single transaction; replay is idempotent.

Although the general domain document mentions `ImportDecision=Update`, v1 here is create-only to match the Create API and the `ImportJobRow.proposed_action` published for the current slice (`create|skip`). Update requires a separate template and schema release and a conflict contract.

## Acceptance Criteria

- Every schema-valid fixture yields a payload that matches the corresponding Create operation with no extra fields.
- A missing required field, an invalid `code` pattern, or a non-UUIDv7 UUID fails validation before apply.
- An invalid parent, type, or manager fails the entire file without partial writes or a partial Outbox.
- Replaying the same ImportJob does not create resources or events twice.
- Errors, logs, and events do not return the raw row, sensitive filename, or `quarantine_object_id`.
