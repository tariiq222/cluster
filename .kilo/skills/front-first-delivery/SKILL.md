---
name: front-first-delivery
description: Use for implementing a software feature frontend-first with an OpenAPI contract, generated TypeScript client, MSW mocks, frontend state coverage, frontend verification gate, frozen contract, backend implementation, contract conformance, integration, and E2E evidence. Especially relevant to React and Laravel repositories.
license: MIT
compatibility: Kilo Code current VS Code extension or CLI; requires repository-local build and test tools.
metadata:
  version: 1.0.0
  workflow: contract-first-mock-first-frontend-before-backend
---

# Front First Delivery Skill

## Purpose

Deliver a feature as a small vertical slice while allowing the frontend to be completed and verified before the backend exists.

## Canonical pipeline

`SCOUT → CONTRACT → GENERATED CLIENT → MOCKS → FRONTEND → FRONTEND GATE → FREEZE → BACKEND → INTEGRATION → FINAL GATE`

The OpenAPI document is the source of truth. The generated client is the frontend API boundary. Mock handlers emulate the contract. Backend code implements the frozen contract.

## Mandatory gates

Read `references/gates.md` before implementation.

## Contract design

Read `references/openapi-checklist.md` when creating or changing an API contract.

## Stack adaptation

Read `references/stack-adapters.md` to detect and follow repository-native React, Next.js, Vue, Angular, Laravel, Node, or other conventions.

## Working rules

1. Work on one independently demoable vertical slice.
2. Reuse repository conventions before adding libraries.
3. Ask only about decisions that materially affect business behavior.
4. Keep generated code generated; never hand-edit it.
5. Cover relevant loading, empty, success, validation, authorization, not-found, conflict, and server-error states.
6. Do not start backend implementation until the frontend gate passes.
7. After contract freeze, require an impact statement for any contract change.
8. Verify with executable commands and exact results.
9. Route defects to the owning layer; do not patch around them in another layer.
10. Avoid destructive Git commands and secret access.

## Ownership map

- Contract agent: feature contract, OpenAPI, generator config, generated client.
- Mock agent: handlers, scenario selectors, fixtures, mock tests.
- UI agent: pages, components, forms, hooks, UI tests.
- Backend agent: routes, validation, authorization, domain logic, persistence, resources, backend tests.
- Verifier: no edits; only evidence and defect classification.

## Completion definition

A slice is closed only when:

- the frontend is demoable against mocks;
- the frontend gate passed;
- the contract is frozen;
- the backend conforms to the contract;
- integration/E2E or an equivalent boundary test passed;
- no unresolved P0/P1 defect remains.
