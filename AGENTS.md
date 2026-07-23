# Project Instructions

## Product

This repository builds the Third Health Cluster platform as a Laravel modular monolith application with a unified React + TypeScript interface, Arabic by default with English and RTL/LTR support.

## Live Code Agent Operating Model

- This project explicitly excludes the main session from running on `gpt-5.6-sol` at `low` effort; the project setup in `.codex/config.toml` records this at the start of any new trusted session.
- The Sol manager owns code-task decomposition, file ownership distribution, execution follow-up, integration-conflict resolution, and final verification. It performs the small change locally when delegation would be more expensive than the change itself.
- The default output is working code, appropriate targeted checks, and a brief run result. Do NOT create separate tracks for governance, contracts, boundaries, architecture, reports, or doc updates unless the user explicitly asks; project constraints remain silent conditions on code execution.
- Do NOT use agents for questions, clarifications, or status reports. For non-trivial code work, delegate only independent code bundles that are verifiable and own non-overlapping files.
- Use `explorer` for narrow exploration that prevents coding from starting, `spark-medium` for clearly mechanical edits and small tests, `worker` for routine code execution, and `luna-high` for complex, narrowly-scoped execution or debugging.
- Do NOT use Terra until a real technical risk or ambiguity emerges that the Luna path cannot resolve, and do NOT run `sol-high` as a default agent because the manager itself is Sol.
- Use `arabic-content-gemini` for Arabic content writing, translation, localization, terminology, and UX writing when language quality affects the product.
- The manager MAY use any configured direct agent that fits the bundle, and create local agent, command, prompt, or skill under `.kilo/` when a recurring gap is proven that current tools do not cover. Creation must follow least-privilege, no nested delegation, an actually available model, internal English instructions, and a setup check; it does NOT include global or external settings or installing dependencies without explicit delegation.
- The operational cap is the manager and nine direct agents in a single wave, with one delegation depth. Scheduling the rest of the bundles happens in waves, reusing the same agent for related follow-ups.
- Use `fork_turns="none"` by default, and send each agent only the goal, owned files, completion condition, and required check. The agent returns a short summary: files changed, check, and any blocker; the manager inspects the diffs and the merged result.

## Sources of Truth

- `docs/` is the source for governed decisions, contracts, and plans.
- Code, tests, and lockfiles are the proof of the actual implementation state.
- `docs/plans/active-delivery-status.md` records active work, evidence, and the next step.
- Do NOT treat a target document or plan as completed implementation without runnable proof.

## Delivery Flow

- Every non-trivial execution task MUST pass through: goal and acceptance-criteria planning with risks and Lane Matrix per modules, then implement a complete vertical slice, then sync OpenAPI and Orval when the API changes, then progressive verification, then E2E for affected visual journeys, then independent review, then close with actual evidence.
- Completed implementation covers every applicable surface: Backend, Frontend, contracts, data, authorization, loading/empty/denied/error/success states, accessibility, localization, and tests; scaffolding, TODO, permanent mocks, or happy-path-only are NOT completion.
- Orchestration starts with Sol as the only control layer, and bundles are delegated to agents underneath as needed; prefer MiniMax for deterministic module bundles and tests, then Luna for complexity and integration failures, and use Terra only for proven high risk.
- E2E covers every materially-changed user journey with applicable states such as success, validation, denial, error, reload, deep link, session, responsiveness, and RTL/LTR, with artifacts on failure, without redundant duplication of component or integration coverage.
- A single developer (the Engineering Office) programs and tests locally; `docs/engineering/delivery-workflow.md` defines the loop: plan, execute, test, integrate.
- If a test fails, work returns to implementation until it passes; no extra phases, coordinators, or committees.
- Completion is proven by working code, a green test, and CI when available — not by a document. Updating `docs/plans/active-delivery-status.md` does NOT become an independent task unless the user requests it.
- Server provisioning is a separate, final phase after R1, R2, and R3 development is complete, NOT a gate between waves.

## Architecture Boundaries

- Direct queries or joins across business-module tables are forbidden.
- Cross-module collaboration happens through contracts, events, IDs, and governed read models.
- The backend applies the same RBAC + ABAC decision to API, search, reports, export, and download.
- Business change and Outbox event are persisted in a single transaction, and consumers are idempotent.
- Live registries are pinned to the deployed work-type versions and routes.
- The deployment target is a single VPS via direct Docker Compose and Caddy, with the existing MySQL and Redis on the server.

## Work Safety

- Check `git status` before edits and preserve the user's unrelated changes.
- Use `apply_patch` for text edits and do NOT use destructive Git commands.
- Do NOT expand scope beyond the user's request, and do NOT commit or push their changes without delegation.
- Use `rg` and `rg --files` for search first.

## Stage Checks

- Verification chooses the required checks and best practices by change type and risk, and records the reason for any `N/A`.
- Doc changes: `./scripts/validate-docs.sh`.
- Module boundaries: `make verify-boundaries`.
- API: start with the narrowest affected Artisan test then broaden if needed.
- Web: `npm --prefix apps/web run build` then the targeted test.
- Do NOT run broad suites for non-behavioral changes unless the risk justifies it.

## Local OpenCode Tooling

- `.opencode/plugins/model-swarm.ts` and `.opencode/instructions/model-swarm.md` are local development tools and NOT part of the product.
- Do NOT make product build or runtime depend on `.opencode/`.

## Unified UI Components (mandatory)

- Always use the unified `apps/web/src/ui/` components (`Button`, `Field`, `Select`, `Drawer`, `Panel`, `Feedback`, ...) across every module interface, and creating raw `<select>`, buttons, fields, or local dropdowns duplicated inside `features/` is forbidden.
- If a component or behavior is missing, add it to the unified library first (with a unit test and patterns in `ui/ui.css`) then use it; do NOT build a module-specific version.
- The unified `Select` dropdown shows an automatic search when its options exceed 10 items (`SELECT_SEARCH_THRESHOLD`); any longer list must go through it, not a custom alternative.