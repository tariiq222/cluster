# Front First Quality Gates

## Gate 1 — Contract Ready

PASS requires:

- one vertical slice with actor, trigger, result, scope, and Definition of Done;
- screen-state matrix;
- acceptance-to-endpoint traceability;
- OpenAPI paths, schemas, errors, examples, and authorization;
- successful OpenAPI parse/validation;
- successful generated-client command;
- no blocking ambiguity.

## Gate 2 — Mock Ready

PASS requires:

- typed handlers aligned with contract paths and methods;
- deterministic fixtures;
- relevant success, empty, validation, authorization, not-found, conflict, and server-error scenarios;
- no duplicated DTO definitions;
- mock setup works in tests or local development.

## Gate 3 — Frontend Verified

PASS requires:

- generated client used by production UI;
- no ad-hoc response-shape adapters;
- required screen states implemented;
- typecheck passes;
- lint passes;
- relevant component/integration tests pass;
- frontend demo works without backend;
- contract and generated client are current.

After this gate, freeze the contract.

## Gate 4 — Backend Conformant

PASS requires:

- every frozen operation implemented;
- validation, authorization, status codes, error shape, and response schemas match;
- backend focused tests pass;
- no frontend or contract edits by the backend agent;
- no silent drift.

## Gate 5 — Integrated and Closed

PASS requires:

- frontend works against the real backend;
- contract conformance passes;
- relevant integration/E2E test passes or an equivalent HTTP-boundary test is justified;
- touched applications pass typecheck/lint/tests;
- generated client has no drift;
- no unresolved P0/P1 issue.

## Failure routing

| Failure | Owner |
|---|---|
| Requirement ambiguity | Contract |
| Missing endpoint/schema/state | Contract |
| Handler or fixture mismatch | Mock |
| Component/form/query defect | UI |
| Route/validation/auth/domain defect | Backend |
| Cross-boundary wiring defect | UI or Backend based on evidence |
| Broken command or unavailable service | Test/environment owner |
