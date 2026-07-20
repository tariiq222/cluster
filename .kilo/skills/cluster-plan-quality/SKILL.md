---
name: cluster-plan-quality
description: Enforce complete cold-start implementation plans with a bounded Sol-Fable loop. Use for deep and critical planning.
---

# Cluster Plan Quality

## Bounded Pipeline

1. Kimi gathers repository evidence.
2. MiniMax builds requirement, dependency, ownership, and verification matrices.
3. Sol writes the complete draft.
4. Fable independently returns `APPROVE`, `REVISE`, or `NEEDS_USER`.
5. Sol revises evidence-backed findings.
6. Repeat Fable review no more than three times.
7. MiniMax simulates cold-start execution after approval.
8. Sol finalizes the plan and execution packets.

Do not allow an unbounded loop. After the third non-approval, stop with one highest-impact unresolved question.

## Required Plan Coverage

Goal, observable outcome, verified current state, requirements, assumptions, accepted decisions, blockers, scope, exclusions, architecture, security, contracts, data and migrations, compatibility, packages, dependencies, parallel waves, file ownership, shared files, tests, E2E, integration risks, rollback or forward-fix, merge order, and done conditions.

## Execution Packet Gate

Every package specifies:

- context and verified current behavior;
- required behavior;
- dependencies;
- owner role and model;
- owned and forbidden files;
- tasks and invariants;
- acceptance criteria;
- exact verification commands;
- integration handoff;
- done condition.

Fail readiness when any executor must guess.
