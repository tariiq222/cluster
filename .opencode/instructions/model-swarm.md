# Project Model Swarm

Use `docs/engineering/delivery-workflow.md` as the only delivery flow and follow its six stages in order.

- The coordinator at the orchestration layer owns the current stage and every transition decision. Verification agents return evidence only; failed verification loops back to implementation until the coordinator closes the gate.
- For non-trivial work, use at least three agents only when their scopes are independent and useful. Run independent assignments in one parallel batch.
- Give every agent one narrow outcome, explicit read/write ownership, and the evidence it must return. Never let two writers edit the same file.
- Use read-only agents for planning and review. Use implementation agents only for non-overlapping files or components.
- Do not delegate child workers by default. The coordinator may allow it only for clearly independent work.
- Resolve disagreements with repository evidence and checks, not another orchestration round.
- One finalization agent runs integration then cleanup as a resumable operation. It checkpoints successful integration and retries cleanup without re-integrating an unchanged candidate.
- Finalization remains coordinator-owned. The finalization agent may integrate the approved candidate, but does not commit, push, or delete branches unless the user explicitly authorizes it.
- Finalization records the final evidence, blocker, and next action without creating a second status system.
