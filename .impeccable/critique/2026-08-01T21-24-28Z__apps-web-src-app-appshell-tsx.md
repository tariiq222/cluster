---
target: apps/web/src/app/AppShell.tsx
total_score: 20
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
timestamp: 2026-08-01T21-24-28Z
slug: apps-web-src-app-appshell-tsx
---
# App Shell Critique

Method: dual-agent (A: ses_040d2f489ffeW1zbxhCfu98pRj · B: ses_040d2f46dffewwS1juerww3LG4)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2/4 | Route state was desktop-only, scope disappeared below `lg`, and several global states remain quiet. |
| 2 | Match System / Real World | 3/4 | Navigation language is domain-appropriate; raw search resource types remain. |
| 3 | User Control and Freedom | 3/4 | Navigation and overlays have clear exits; scope switching still requires leaving the active workflow. |
| 4 | Consistency and Standards | 2/4 | Command navigation used a different, unfiltered destination model from the sidebar. |
| 5 | Error Prevention | 2/4 | Capability filtering is strong, but hidden scope and unfiltered command destinations weakened it. |
| 6 | Recognition Rather Than Recall | 2/4 | Expanded navigation is labeled, but mobile users had to remember their route and scope. |
| 7 | Flexibility and Efficiency | 2/4 | Command search and sidebar shortcuts exist, but coverage and discoverability are incomplete. |
| 8 | Aesthetic and Minimalist Design | 3/4 | Calm and disciplined; the mobile utility rack competed with operational context. |
| 9 | Error Recovery | 1/4 | Principal bootstrap failure can still result in a blank authenticated product. |
| 10 | Help and Documentation | 0/4 | No contextual help or support path is discoverable from the shell. |
| **Total** | | **20/40** | **Acceptable baseline; significant high-stakes UX gaps remained.** |

## Design Specificity Verdict

**LLM assessment:** Moderately authored, structurally interchangeable. Permission-filtered groups, effective scope, and deliberate RTL behavior are specific to governed healthcare administration. The generic initial mark, standard Lucide navigation, neutral utility bar, and stock account treatment remain close to a shadcn admin template. The strongest product concept, governed acting scope, was visually subordinate.

**Deterministic scan:** Both `npx impeccable detect apps/web/src/app/AppShell.tsx` and the bundled JSON detector returned zero findings before implementation. The requested detector was run again on the finished shell and also returned zero findings. This confirms mechanical cleanliness, not heuristic completeness.

**Visual overlays:** No overlay was injected. Browser evidence came from the existing authenticated Playwright shell fixture in a fresh context, not from a user-visible detector overlay.

## Overall Impression

The shell is calm, technically thoughtful, and unusually disciplined for a dense administrative product. Its largest weakness was that responsive fit came at the cost of orientation: current location and effective scope disappeared while lower-value appearance controls remained persistent. The highest-value correction was to make operational context survive every breakpoint and force command search to honor the same authorization-aware information architecture as the sidebar.

## What's Working

- Permission-aware navigation removes unavailable destinations entirely instead of teasing inaccessible features.
- RTL behavior is deliberate: sidebar side, tooltip direction, logical spacing, focus treatment, and current-page semantics are handled explicitly.
- The cool neutral palette, structural borders, compact controls, and restrained type hierarchy suit governed administrative work.

## Priority Issues

### [P1] Acting scope and current location disappeared on mobile

**Why it matters:** Administrators need continuous reassurance about where they are acting. Hiding route and scope at narrow widths creates wrong-scope anxiety during consequential work.

**Fix:** Keep a compact route title and effective-scope line in the header at every breakpoint; provide full scope text through title/account overflow when truncated.

**Status:** Resolved in this run.

**Suggested command:** `$impeccable adapt`

### [P1] Command search broke the permission-aware navigation contract

**Why it matters:** The command menu exposed fixed destinations that the sidebar intentionally hid, making authorization behavior appear arbitrary and potentially disclosing feature existence.

**Fix:** Drive command navigation from the same capability-filtered groups as the sidebar.

**Status:** Resolved in this run.

**Suggested command:** `$impeccable harden`

### [P1] Principal bootstrap failure can render a blank product

**Why it matters:** A temporary principal or scope request failure removes every task with no explanation, retry, or support path.

**Fix:** Render shell-shaped loading and localized denied/error states with retry and correlation details before router feature resolution.

**Status:** Remaining; broader than the shell-only critique target.

**Suggested command:** `$impeccable harden`

### [P2] Global utility state was too quiet

**Why it matters:** Theme options did not expose the current choice, while appearance controls consumed scarce mobile header space.

**Fix:** Use radio semantics for theme selection and move language/theme controls into account overflow below desktop width.

**Status:** Resolved in this run.

**Suggested command:** `$impeccable distill`

### [P2] Some bilingual and assistive details remain inconsistent

**Why it matters:** Hard-coded English mobile-sheet metadata and raw API search group labels interrupt Arabic-first parity.

**Fix:** Localize mobile sheet metadata through a wrapper and map search resource types to human labels while suppressing non-routable results.

**Status:** Remaining.

**Suggested command:** `$impeccable clarify`

## Persona Red Flags

**Alex, Power User:** Command navigation previously diverged from sidebar permissions and omitted several legitimate destinations. The canonical navigation model is now shared, but result routing and shortcut discovery remain incomplete.

**Sam, Keyboard and Screen-Reader User:** Skip navigation, labels, focus rings, headings, and `aria-current` are strong. Mobile route/scope context is now retained and theme selection uses radio semantics. Hard-coded English mobile-sheet metadata and missing principal error recovery remain.

**Casey, Distracted Mobile User:** The header previously exposed six utilities while hiding route and scope. It now preserves both operational facts and moves language/theme controls behind account overflow. Direct scope switching is still not available from the persistent shell.

## Minor Observations

- Account copy remains local to `AppShell.tsx` instead of the central copy source.
- The shortcut hint always displays `Cmd+K` even though Windows/Linux use Control+K.
- Notifications have no unread status.
- The shell repeatedly requests font weight 500 while only 400, 600, and 700 are loaded.
- Search result groups still expose raw API resource types and unsupported results can close without navigation.

## Questions to Consider

- If an approval could affect the wrong facility, should effective scope ever disappear or require leaving the workflow to change it?
- Should command search be a second navigation model, or only a faster interface over the canonical permission-aware model?
- What shell element would make Cluster recognizable without its product name or Arabic copy?
- Where should first-time administrators find task-specific help during consequential work?
