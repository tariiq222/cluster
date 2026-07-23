---
doc_id: PLN-TR-001
title: Design of Codex Work Takeover and GSD Removal
type: plans
status: proposed
version: 1.0.0
date: 2026-07-17
owner: Platform Engineering Office
reviewers:
- Software Engineering Lead
- Technology Lead
classification: internal
review_cycle: With every change
sources:
- docs/plans/implementation-roadmap.md
- docs/plans/release-1-platform.md
references:
- docs/governance/document-control.md
---
# Design of Codex Work Takeover and GSD Removal

## 1. Adopted Decision

Codex manages ongoing work directly from approved project sources, with removal of the
GSD runtime, its settings, constraints, and intermediate outputs. The standalone
OpenCode settings and `model-swarm` are retained, and only the unique, verifiable
content from `.planning/` is transferred to governed project documents before deletion.

The user adopted this direction on 2026-07-17 and ordered execution without an
additional questions round.

## 2. State Revealed by the Audit

- The work tree on `main` is not clean and contains application, documentation, and
  plan changes that are uncommitted; all of it is project work that must be preserved.
- Local GSD install inside `.opencode/` is approximately 131 MB, and the install
  manifest lists 592 files for version 1.7.0.
- 448 tracked files exist under `.opencode/gsd-core/`, plus 71 commands, 71 skills,
  35 agents, a hook set, scripts, and MCP servers tied to it.
- `.planning/` is approximately 1.1 MB and shows 25 phases and fifteen of twenty
  plans marked as complete in W1.1, but this state is not accepted as execution
  evidence without matching it against actual code, tests, and commits.
- The current `AGENTS.md` is generated from GSD and mixes correct information with
  outdated repository descriptions and tool-specific runtime constraints.
- No GSD process was active at audit time; the standalone OpenCode graphical
  process is not part of the deletion.
- There are no active GSD references outside `.opencode/` and `.planning/` except
  in `AGENTS.md`.

## 3. Alternatives

### Chosen Alternative: Transfer Value then Selective Removal

Extract unique content from `.planning/` to a standalone delivery state, then delete
the GSD components and rewrite connection points, while keeping OpenCode and
`model-swarm`. This achieves full takeover without sacrificing standalone tools or
ongoing project work.

### Rejected Alternative: Delete the Runtime but Keep `.planning/`

Reduces the risk of information loss, but keeps the GSD-specific state model, phases,
and file names as a live reference, so it does not achieve the required independence.

### Rejected Alternative: Delete `.opencode/` and `.planning/` Entirely

Removes the tool quickly, but deletes `model-swarm`, the standalone OpenCode settings,
and may lose decisions and plans that were not yet transferred.

## 4. Takeover Boundaries

### What Will Remain

- All product code, documentation, contracts, infrastructure, tests, and uncommitted changes.
- `docs/plans/implementation-roadmap.md` as the approved execution roadmap.
- OpenCode, the `model-swarm` files, and the `@opencode-ai/plugin` package they need.
- Standalone local files that dependency inspection proves do not invoke GSD; any
  remaining GSD connection paragraphs are removed from them.
- The existing Git history; this work does not include rewriting history.

### What Will Be Transferred

- The actual current state of W1.1 after verifying code, tests, and commits.
- Unique decisions not present in approved documents.
- Open executable work, its acceptance criteria, and evidence references.
- All of this is gathered into one governed delivery document under `docs/plans/`
  instead of copying the `.planning/` tree or preserving its format.

### What Will Be Deleted or Rebuilt

- `.opencode/gsd-core/` and every command, skill, agent, hook, script, plugin,
  MCP, and install manifest owned by GSD.
- `.planning/` in full after transfer and verification succeed.
- GSD-generated sections in `AGENTS.md`, then replaced with concise, current
  project instructions grounded in current documents and code that do not impose
  any external workflow.
- GSD keys from `.opencode/opencode.json` and any remaining references in
  standalone files.
- Local outputs or dependencies with no consumer after removal, while keeping
  what `model-swarm` needs.

## 5. Post-Takeover Truth Layers

The truth layers after cleanup are as follows:

1. `docs/` for decisions, the roadmap, contracts, and governed plans.
2. Actual code, tests, and commits proving execution state.
3. One delivery-state document under `docs/plans/` for active work, evidence, and
   the next step.
4. `AGENTS.md` for local execution instructions only.
5. `.opencode/` for standalone OpenCode tools, without MCP, commands, or hooks
   specific to GSD.

Completion percentage is not derived from a generated state file. Any item's
status changes only when there is evidence from tests, code, an approved
document, or a specific Git commit.

## 6. Transfer Flow

1. Record a baseline snapshot of the Git tree, GSD paths, and planning outputs,
   without modifying working files.
2. Classify `.planning/` content into: duplicated in `docs/`, locked in Git,
   unique and worth transferring, or intermediate output that can be dropped.
3. Create the standalone delivery state and tie every completion claim to
   evidence; do not migrate unverified percentages and summaries as is.
4. Remove GSD files using the install manifest with an explicit allow-list for
   standalone files.
5. Clean up shared connection files, then delete `.planning/` after transfer
   completion is proven.
6. Replace `AGENTS.md` and verify that OpenCode and `model-swarm` remain valid.
7. Compare the post-cleanup Git snapshot with the baseline to confirm no
   out-of-scope change was lost.

## 7. Safety and Rollback

- Execution does not use `git reset --hard`, `git checkout --`, or history rewriting.
- No prior user change is committed inside cleanup commits; selective staging is used.
- Deletions are confined to paths proven to be owned by GSD or to `.planning/`
  outputs after transfer.
- When a shared file or ambiguous ownership appears, the file is preserved and
  only the GSD reference is removed until the absence of a standalone consumer
  is proven.
- Git can restore deleted tracked files; untracked files of value are moved before
  deletion to a governed document or to a tracked project path.

## 8. Verification and Completion Criteria

The takeover is complete when all the following conditions are met:

- Absence of GSD runtime, commands, skills, agents, hooks, MCP, and install manifests.
- Absence of `.planning/` after a delivery-state document exists that covers the
  unique, proven value.
- No operational references to GSD in project files; only this document as a
  historical decision record and the existing Git log are allowed.
- `model-swarm` and the standalone OpenCode configuration remain loadable without
  invoking any deleted file.
- `./scripts/validate-docs.sh` succeeds, and the strict MkDocs build runs if its
  dependencies are available.
- Targeted JSON/Node checks pass for the remaining OpenCode files.
- All uncommitted product changes before cleanup match those after, except for
  the declared takeover and cleanup paths.
- The final `git status` shows clearly: user changes preserved, GSD deletions and
  takeover additions separated and reviewable.

## 9. Out of Scope

- Changing Laravel or React behavior or operational contracts.
- Completing the remaining W1.1 plans within the same cleanup process.
- Deleting OpenCode or `model-swarm`.
- Removing GSD from the user's global settings outside this repository; this
  requires separate authorization because the current scope is
  `/Users/tariq/code/R3/cluster`.
- Rewriting Git history to remove GSD names from old commits.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-17 | Platform Engineering Office | Lock in work-takeover and GSD-removal design while protecting ongoing work |
