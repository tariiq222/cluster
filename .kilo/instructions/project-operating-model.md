# Project Operating Model

This repository is a Laravel modular monolith with a unified React and TypeScript web application.

## Sources Of Truth

1. Governed decisions and contracts under `docs/`.
2. Current code, tests, generated artifacts, and lockfiles for implemented state.
3. `docs/plans/active-delivery-status.md` for active delivery order and evidence.

Never treat a plan or target document as implemented without runnable evidence.

## Execution Rules

- The primary `orchestrator` mode uses Sol as the top-level control layer, with subagents handling the bounded lanes underneath it, and owns decomposition, file ownership, integration, conflict resolution, and final verification.
- The primary `plan` mode uses the persistent Kimi, MiniMax, and Fable planning loop, with GPT-family escalation kept rare.
- Execute a small change locally when delegation would cost more than the change.
- For non-trivial work, delegate only independent, verifiable packages with non-overlapping write surfaces.
- Run at most nine direct subagents in one wave. Subagents must not spawn other agents.
- Prefer Kimi K3 and MiniMax M3 for research, structure, mechanical changes, focused tests, and bounded low-risk implementation.
- Use MiniMax for routine production implementation and bounded complex debugging; use Kimi for research-heavy bounded work.
- Use Terra only after evidence exposes genuine architecture, security, migration, or cross-module uncertainty.
- Use `arabic-content-gemini` for Arabic writing, translation, localization, terminology, and UX copy where language quality is material.
- The orchestrator may use any configured direct agent that fits the lane. It may create a narrowly scoped project-local agent, command, prompt, or skill under `.kilo/` only when repository evidence shows a recurring capability gap. New capabilities must use an available model, least privilege, no nested delegation, English internal instructions, explicit verification, and no external or global configuration changes without user authorization.
- Use `visual-inspector` with `webvue/gpt-image-2` for screenshots or image files. The other configured Webvue models are text-only.
- Keep shared integration files under one owner. Typical shared files include routes, service providers, root application shells, OpenAPI entry points, generated clients, lockfiles, and global styles.
- Never commit, push, amend, deploy, or delete worktrees unless the user explicitly requests it.

## Parallel Work

- Split by module or feature directory first, then by contract owner, frontend slice, and integration owner.
- Never assign two writers to the same file.
- Reads and independent checks may run in parallel.
- Contract generation, migrations sharing an order, full builds, browser tests, Docker Compose tests, and deployment run sequentially unless isolation is proven.
- In a dirty worktree, preserve all unrelated user and agent changes. Do not use stash-based flows.

## Architecture Invariants

- No direct query, join, foreign key, ORM relation, or write across business-module table ownership.
- Cross-module collaboration uses published contracts, events, IDs, or governed read models.
- Synchronous dependencies point only to lower-ranked modules and expose DTOs rather than ORM models.
- The caller owns a write transaction. Source state and its Outbox event are stored in the same transaction.
- At-least-once consumers are idempotent.
- Authorization is deny-by-default and applies consistently to API, search, reports, exports, and downloads.
- React consumes server decisions for user experience only and never becomes an authorization boundary.

## Mandatory Delivery Pipeline

Every implementation follows a complete, risk-proportionate pipeline:

1. Plan the objective, acceptance criteria, current implementation, dependencies, risks, affected user journeys, and a module-first lane matrix with exclusive file ownership.
2. Implement the complete applicable vertical slice across backend, frontend, contracts, generated clients, data, authorization, integration states, accessibility, localization, and tests. Partial scaffolding, permanent mocks, TODOs, disconnected layers, and happy-path-only delivery are not completion.
3. For API changes, treat OpenAPI as authoritative: update contract and backend together, lint the contract, regenerate with Orval, check generated drift, integrate the React consumer, and never hand-edit generated output.
4. Verify progressively: focused tests, scratch reproduction only when diagnosis requires it, then applicable static analysis, typecheck, unit/component tests, API/integration tests, migration and boundary checks, production build, and browser E2E. Convert scratch reproductions into permanent regression tests.
5. Cover materially changed user-visible journeys with deterministic browser E2E, including applicable success, validation, denied, error, reload, deep-link, session, responsive, accessibility, localization, and RTL/LTR behavior. Preserve failure artifacts.
6. Run an independent read-only review for security, authorization, boundaries, regressions, data and transaction safety, contract drift, diff quality, and missing tests. Resolve material findings and rerun affected gates.
7. Finish only after reconciling acceptance criteria, inspecting the integrated diff, updating genuinely affected governed documentation or status, and reporting actual verification evidence.

Use Sol as the top-level control layer, keep subagents beneath it for evidence and bounded work, prefer MiniMax for deterministic module lanes and focused tests, reserve GPT-family models for rare escalation, and use Terra only for proven unresolved high risk. Up to nine independent agents may run per wave, but shared files, generation, builds, E2E, Docker, and deployment remain serialized unless isolation is proven.

## Verification Selection

- Documentation-only changes: `./scripts/validate-docs.sh`.
- API changes: begin with the narrowest affected Artisan test.
- Cross-module contracts, imports, SQL, or migrations: include `make verify-boundaries`.
- Web changes: run focused Vitest tests and `npm --prefix apps/web run build`.
- OpenAPI or generated-client changes: lint the contract, regenerate with Orval, check generated output, and build the web app.
- Run E2E, Docker, and broad suites only when the behavioral risk or package closure justifies them.
