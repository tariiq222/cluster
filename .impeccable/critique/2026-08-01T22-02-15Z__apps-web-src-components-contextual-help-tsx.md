---
target: apps/web/src/components/contextual-help.tsx
total_score: 20
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
timestamp: 2026-08-01T22-02-15Z
slug: apps-web-src-components-contextual-help-tsx
---
Method: dual-agent (A: ses_040b37ffaffeGxPRB7XPgZiqn0 · B: ses_040b37fb6ffeyEgDtcBx6Irwv9)

## Design Health Score

Baseline before the fixes applied in this run.

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Route and scope were visible, but current workflow state and permitted actions were absent. |
| 2 | Match System / Real World | 3 | Operational language was strong; scope and correlation ID still assumed system knowledge. |
| 3 | User Control and Freedom | 1 | Mobile users could not open Help because closing the sidebar unmounted the Help state. |
| 4 | Consistency and Standards | 2 | Several valid routes received generic home guidance. |
| 5 | Error Prevention | 2 | Advice was not bound to actual state or server-provided allowed actions. |
| 6 | Recognition Rather Than Recall | 2 | The support handoff required recalling a correlation ID shown elsewhere. |
| 7 | Flexibility and Efficiency | 2 | Help was persistent but had no search, shortcut, deep links, or task accelerators. |
| 8 | Aesthetic and Minimalist Design | 3 | Calm and compact, though support had more visual emphasis than scope and next action. |
| 9 | Error Recovery | 2 | Recovery copy named support but supplied neither the current ID nor a direct support channel. |
| 10 | Help and Documentation | 1 | Mobile access failed and route coverage was incomplete. |
| **Total** | | **20/40** | **Acceptable baseline; material usability defects found** |

## Design Specificity Verdict

**LLM assessment:** Partially product-authored, structurally generic. The bilingual copy reflects Cluster's governance model through scope, ownership, permissions, versions, and impact, but the component knows only a pathname family. It cannot present actual current state, permitted action, or contextual recovery, so its title-description-list-support composition remains interchangeable with other admin products.

**Deterministic scan:** `npx impeccable detect apps/web/src/components/contextual-help.tsx` exited 0 with no source findings. Browser injection over the full shell logged 13 heuristic findings: 5 `layout-transition`, 7 `nested-cards`, and 1 `low-contrast`. Most belonged to the shell/dashboard rather than the target; the support section's `nested-cards` finding was a false positive because it was a semantic section, not a nested Card.

**Visual evidence:** Headless browser inspection found the decisive defect the source detector missed: at 390×844, clicking Help closed the mobile sidebar and left zero Help dialogs. Desktop Arabic and English sheets rendered on the correct physical sides without overflow.

## Overall Impression

The component has strong bilingual parity, semantic structure, and calm token discipline. Its largest opportunity is to become genuinely contextual: preserve mobile reachability, map every stable route correctly, and eventually source state, scope, permitted actions, and recovery data from the active screen rather than static pathname copy.

## What's Working

- Arabic RTL and English LTR content have equivalent meaning, explicit direction, and correct sheet placement.
- The semantic foundation is sound: labelled trigger and close controls, dialog title/description, headings, lists, and decorative icons hidden from assistive technology.
- Compact typography, semantic colors, logical spacing, and restrained surfaces match Cluster's quiet institutional design language.

## Priority Issues

### [P0] Mobile Help was unreachable — Resolved

**Why it matters:** The trigger closed the mobile sidebar, unmounting its own local Help state before the second sheet could render.

**Fix:** Lifted Help state and sheet rendering into the persistent app shell while leaving only the trigger inside the sidebar. Closing now restores focus to the shell sidebar trigger.

**Suggested command:** `$impeccable harden`

### [P1] Valid routes received the wrong topic — Resolved for active gaps

**Why it matters:** Notifications and feature-gated work routes fell back to home guidance, undermining trust precisely when users requested help.

**Fix:** Added bilingual Notifications and Workflow topics and mapped `/notifications`, `/work-records`, `/inbox`, `/workflow`, and `/work-definitions` explicitly.

**Suggested command:** `$impeccable clarify`

### [P1] Context is limited to route family — Open

**Why it matters:** Help cannot explain current record state, active tab, capabilities, allowed actions, or task-specific recovery. High-stakes flows therefore receive broad static advice.

**Fix:** Introduce a screen-owned help contract carrying current state, effective scope, permitted next action, and recovery guidance; prefer server-provided allowed actions where available.

**Suggested command:** `$impeccable shape`

### [P2] Technical support remains a dead-end — Open

**Why it matters:** Users are told to include a correlation ID but the drawer neither receives nor displays it, and no configured support channel is actionable.

**Fix:** Surface the current correlation ID as LTR machine data with a copy action, then expose the approved support channel after local recovery steps.

**Suggested command:** `$impeccable harden`

### [P2] Mobile targets and support hierarchy needed refinement — Resolved

**Why it matters:** The 32px close target was less forgiving for motor-impaired users, and the boxed support treatment competed with the primary next-action guidance.

**Fix:** Increased mobile trigger/close targets to 44px and reduced support to a quiet structural divider.

**Suggested command:** `$impeccable polish`

## Persona Red Flags

**Alex (Power User):** Static two-step advice still has no shortcut, search, deep links, or active-tab awareness. It does not accelerate repeat administrative work.

**Sam (Accessibility-Dependent User):** The initial mobile interaction was blocking. The applied fix now keeps Help mounted, provides 44px mobile controls, and restores focus to the sidebar trigger. Remaining risk is the lack of a screen-reader announcement for dynamically changing workflow state because no live state is supplied.

**Jordan (First-Time Administrator):** Route-specific guidance is now trustworthy for Notifications and work routes, but “scope,” “correlation ID,” and “approved IT service desk” still assume prior organizational knowledge and do not provide an actionable handoff.

## Minor Observations

- Existing route-help tests covered only Arabic task help; this run added mobile persistence and Notifications route regressions.
- Scope remains passive metadata and disappears when unavailable rather than explaining global, loading, or unavailable context.
- The detector's nested-card flag on the old support section was a heuristic false positive, but simplifying that section improved hierarchy anyway.

## Questions to Consider

- Should each screen provide Help with its actual permitted actions instead of letting the shell infer guidance from the pathname?
- Can the current correlation ID and approved support channel be supplied without leaking sensitive operational data?
- Which high-stakes flow should receive state-aware Help first: imports, access changes, or platform maintenance?
