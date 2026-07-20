# Project Operating Model

This repository is a Laravel modular monolith with a unified React and TypeScript web application.

## Sources Of Truth

1. Governed decisions and contracts under `docs/`.
2. Current code, tests, generated artifacts, and lockfiles for implemented state.
3. `docs/plans/active-delivery-status.md` for active delivery order and evidence.

Never treat a plan or target document as implemented without runnable evidence.

## Execution Rules

- The primary `orchestrator` mode uses Sol and owns decomposition, file ownership, integration, conflict resolution, and final verification.
- The primary `plan` mode uses the persistent Kimi, MiniMax, Sol, and Fable planning loop.
- Execute a small change locally when delegation would cost more than the change.
- For non-trivial work, delegate only independent, verifiable packages with non-overlapping write surfaces.
- Run at most three direct subagents in one wave. Subagents must not spawn other agents.
- Prefer Kimi K3 and MiniMax M3 for research, structure, mechanical changes, focused tests, and bounded low-risk implementation.
- Use Luna for routine production implementation and bounded complex debugging.
- Use Terra only after evidence exposes genuine architecture, security, migration, or cross-module uncertainty.
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

## Verification Selection

- Documentation-only changes: `./scripts/validate-docs.sh`.
- API changes: begin with the narrowest affected Artisan test.
- Cross-module contracts, imports, SQL, or migrations: include `make verify-boundaries`.
- Web changes: run focused Vitest tests and `npm --prefix apps/web run build`.
- OpenAPI or generated-client changes: lint the contract, regenerate with Orval, check generated output, and build the web app.
- Run E2E, Docker, and broad suites only when the behavioral risk or package closure justifies them.
