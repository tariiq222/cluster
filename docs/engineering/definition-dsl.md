---
doc_id: ARC-EN-007
title: Constrained Work Definition DSL
type: engineering
status: draft
version: 1.0.0
date: 2026-07-15
owner: Software Engineering Lead
reviewers:
- Information Security Lead
- Software Engineering Lead
classification: internal
review_cycle: With every change
sources:
- docs/adr/005-work-records-dynamic-data.md
- docs/adr/006-workflow-versioning.md
references:
- docs/architecture/overview.md
- docs/data-security/logical-data-model.md
---

> **NOT IMPLEMENTED.** Repository automation does not verify runtime DSL limits for field count, layout depth, payload size, text length, relation count, or validation-rule count. These limits remain required design constraints until executable validation and boundary tests are present.
# Constrained Work Definition DSL

## Purpose

The DSL describes a work type definition and its version: fields, shape, validation, relationships, and display states. It is validated data, not a programming language, and gives the definition no execution capability outside the approved engine.

## Allowed Surface

The DSL accepts a versioned schema composed of bounded values: approved field types, `required`, ranges and lengths, fixed lists, local comparison rules between declared fields, layouts, and relationships selected from an allowlist of types and modules. The engine interprets these rules deterministically within known time, memory, and depth limits.

## Absolute Prohibitions

Neither the DSL nor its transformers accept:

- SQL, query text, table names, or ORM expressions.
- Network requests, URLs, webhooks, or external integrations.
- File reads or writes, storage paths, or code loading.
- JavaScript, PHP, shell, executable templates, or any arbitrary code.
- Reflection, dynamic imports, or calling a function whose name comes from the definition.

Any text that does not match the schema or exceeds a boundary is a publication error, not a value to ignore silently.

## Versions and Compatibility

1. Every definition has immutable `dsl_version` and `work_type_version` values after publication.
2. A `WorkRecord` retains the definition version with which it was created; an existing record is not reinterpreted using a newer version.
3. A compatible change adds an optional field or a list value without changing prior meaning.
4. An incompatible change creates a new version with an explicit conversion plan and compatibility review; it is never applied silently.
5. The engine retains a limited, declared set of supported DSL versions. When support ends, it rejects new publication and presents an upgrade path. It must not remove a parser required by a stored record before a documented migration.

## Resource Limits and Audit

A supported version is intended to define maximum field count, layout depth, payload size, text length, relationship count, and validation-rule count. Runtime enforcement and automated boundary tests for these limits are not currently verified in the repository. Publication, rejection, and conversion must be recorded with the schema version, definition hash, and approver. Every DSL version requires acceptance, rejection, backward-compatibility, and resource-boundary tests before these controls can be treated as implemented.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Software Engineering Lead | Defined the constrained DSL, versioning rules, security boundaries, and implementation gap |
