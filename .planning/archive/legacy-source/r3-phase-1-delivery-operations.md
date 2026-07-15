# R3 Phase 1 delivery operations

`r3-flow` advances one Phase 1 plan at a time. It selects the allowed Codex route, journals external actions, creates an isolated worktree, runs the versioned gates, merges locally, verifies `main`, and cleans only proven merged work.

P1-00 is infrastructure, not product completion. The goal stays active through P1-11 and authenticated pilot acceptance.

## Prerequisites

- Python 3.11 or newer.
- Git with local user name and email configured.
- Codex CLI 0.144.1 or newer, authenticated for the current user.
- macOS with `/usr/bin/sandbox-exec` for verification gates. Unsupported hosts fail closed until a reviewed gate-sandbox adapter exists.
- A clean `main` checkout with no Git remote while `allow_remote` is false.
- No production credentials, employee data, or pilot signing keys in prompts, reports, commits, or command output.

Run `make flow-validate` before starting. Validation is read-only and accepts a clean `main` checkout or clean `codex/*` delivery worktree.

## Normal commands

Run these commands from the repository root:

```bash
make codex-install
make flow-validate
make flow-start
make flow-resume
make flow-status
make flow-stop
```

- `codex-install` installs or replaces only `skills/r3-platform-delivery` under `CODEX_HOME`.
- `flow-start` selects the next dependency-satisfied plan. An unplanned plan enters `PLANNING`; its schema-validated plan is materialized only inside the new plan worktree, where execution commits the plan and manifest contract with the implementation. Model-planned verification is limited to reviewed command families and arguments; inline interpreter payloads, arbitrary repository executables, Docker, MySQL, and mutating Git commands are rejected.
- `flow-resume` performs one durable state transition. Repeat it until completion, a blocker, or P1-11 pilot acceptance.
- `flow-status` reports the current plan, state, attempt, last gate, and blocker.
- `flow-stop` records a durable stop request; the next resume enters `HALTED` without merging or deleting work.

Run the complete P1-00 verification independently with:

```bash
make verify
```

## Advanced recovery and pilot commands

Use recovery only when runtime state is missing or corrupt and committed Git/report evidence exists:

```bash
tools/codex/r3-flow recover --plan P1-01
```

Recovery requires one unreverted plan merge and the committed plan report, reruns the current-main verification, and then returns control to normal cleanup. It does not infer success from a green restored `main` or from an uncommitted file.

When a plan is `HALTED`, inspect the blocker and evidence, correct the external condition, then run `make flow-resume`. Resume returns to `PLANNING`, `REPAIRING`, or `DIAGNOSING` only through the defined state transitions. Do not delete the worktree or edit the action journal.

P1-11 stops at `AWAITING_PILOT_ACCEPTANCE`. An authorized human uses an interactive terminal:

```bash
tools/codex/sign-pilot-acceptance --decision accepted --approver pilot.one
git add docs/delivery/phase-1-acceptance.json
git commit -m "docs: record Phase 1 pilot decision"
tools/codex/r3-flow pilot-decision --evidence docs/delivery/phase-1-acceptance.json
```

The approver must already exist in the reviewed `.codex/pilot-approvers.json` registry, and the terminal must provide the matching `R3_PILOT_KEY_<NORMALIZED_APPROVER_ID>` value. The signer never commits the record. The controller requires the exact configured committed record and consumes its nonce once.

## Exit codes

| Code | Meaning |
|---:|---|
| `0` | Command completed its requested transition. |
| `2` | Invalid contract, argument, schema, or runtime evidence. |
| `3` | Safe blocker or human action required. |
| `4` | Required verification gate failed. |
| `5` | Codex invocation or structured result failed. |
| `6` | Git safety check failed. |

## Evidence locations

- `.r3-flow/plans/<plan-id>/events.jsonl`: append-only state and action intent/result journal.
- `.r3-flow/runs/<run-id>/`: normalized Codex outputs, redacted stdout/stderr, and gate evidence.
- `.r3-flow/runs/<run-id>/gates/`: gate stdout/stderr files with secret-like values redacted.
- `docs/delivery/phase-1/<plan-id>.md`: versioned delivery report used by recovery and review.
- `docs/delivery/phase-1-acceptance.md`: required human pilot narrative format.
- `docs/delivery/phase-1-acceptance.json`: committed authenticated pilot decision.

`.r3-flow` is local runtime evidence and is never committed. Operator intervention is required only for an explicit blocker or the authenticated pilot decision. Strategy, projects, and enterprise risk management remain outside Phase 1.

Every gate runs with a minimal environment and an OS sandbox: network access is denied, user-home and unrelated temporary data cannot be read, writes are limited to the active worktree and gate scratch directory, and Git metadata is read-only. The controller then requires unchanged branch/main heads, a clean worktree, and an in-scope diff. Later container or service integration must use a separately reviewed isolated gate adapter; the automatic planner cannot grant host Docker access.
