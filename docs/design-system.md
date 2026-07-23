---
doc_id: DS-001
title: Third Health Cluster Platform Design System
type: engineering
status: accepted
version: 1.0.0
date: 2026-08-01
owner: Platform Engineering Office
reviewers:
  - Software Engineering Lead
  - Product Lead
classification: internal
review_cycle: With every major design change
sources:
  - docs/design-system.md
  - docs/README.md
  - docs/engineering/README.md
references:
  - docs/README.md
  - docs/engineering/README.md
---

> **PARTIALLY IMPLEMENTED.** The active design surface includes shared and feature stylesheets omitted by the earlier document. Generic warning and dark-surface tokens and a global reduced-motion rule are not implemented; the specification below now describes the active CSS.

# Third Health Cluster Platform Design System

> **Reference source:** `DESIGN.md`
>
> **Current implementation surfaces:** `apps/web/src/index.css`, `apps/web/src/styles/tokens.css`, `apps/web/src/styles/base.css`, `apps/web/src/styles/screens.css`, `apps/web/src/app/AppShell.css`, `apps/web/src/ui/ui.css`, feature stylesheets under `apps/web/src/features/**/*.css`, and `apps/web/src/main.tsx`

## 1) Purpose

This design system is more than a color palette. It is a complete operating language for a dense, calm, quickly scannable interface designed Arabic-first while supporting both directions. The approved visual reference is the local `access-management-dashboard.html` page, adapted into the React and TypeScript application in this repository.

### Principles

- **Calm operations room:** Structure is clear before decoration.
- **One system:** Shared tokens and components replace module-local primitives.
- **Equal RTL/LTR support:** Every primitive must work in both directions.
- **Fully local at runtime:** No CDN, external fonts, or public assets.
- **Clear signals:** Color never carries meaning alone; text, an icon, or a pattern completes it.

## 2) Color Palette

### Primary

| Token | Value | Use |
|---|---:|---|
| `--color-primary` | `#293B85` | Primary action, current selection, and important links |
| `--color-primary-hover` | `#253679` | Hover and active states |
| `--color-accent` | `#3DAAE1` | Dividers, secondary indicators, and highlights |
| `--color-ink` | `#1A2735` | Text and headings |
| `--color-muted` | `#5A6875` | Secondary text |

### Neutral

| Token | Value |
|---|---:|
| `--color-canvas` | `#F6F7F9` |
| `--color-surface` | `#FFFFFF` |
| `--color-border` | `#E4E4E7` |
| `--color-border-strong` | `#CED6DF` |
| `--color-selected` | `#E8ECF7` |
| `--color-primary-soft` | `#EEF0F9` |

### Semantic

| Token | Value |
|---|---:|
| `--color-success` | `#247A42` |
| `--color-danger` | `#B42318` |

The system does not currently expose generic `--color-warning` or `--color-dark-*` tokens in `apps/web/src/styles/tokens.css`. Dark login-theme values remain local to their implementation and are not public design-system tokens.

## 3) Typography and Hierarchy

- **Font family:** `IBM Plex Sans Arabic`, then `Tahoma`, then `Arial`.
- **Numbers:** Use `tabular-nums` where needed.
- **Headline:** 24px / 700.
- **Title:** 20px / 600.
- **Body:** 16px / 400.
- **Label:** 13px / 600.
- **Meta:** 12px or less when the text is non-critical.

There is no general 32px Display style in the active base stylesheet.

## 4) Spacing, Radii, and Shadows

- **Radii:** 12px for controls, 16px for surfaces, and 999px for pills.
- **Spacing:** 4 / 8 / 12 / 16 / 24 / 32 / 48.
- **Surfaces:** Flat by default.
- **Shadows:**
  - `--shadow-float` for popovers.
  - `--shadow-dialog` for dialogs and drawers.
- **Motion:** Active tokens use 150–180ms ease-out timing. The cited stylesheets do not currently define a global `prefers-reduced-motion` rule.

## 5) General Application Structure

### App Shell

- The Sidebar appears on the left or right according to page direction, with correct RTL/LTR behavior.
- The Sidebar uses a dark institutional-blue gradient.
- The Topbar is light, sticky, simple, and free of glassmorphism.
- The content stage sits on the gray canvas.
- The Footer is neutral and short.

### Mobile

- Navigation becomes a real drawer.
- A visible close button is provided.
- Escape and backdrop click close the drawer.
- Focus returns to the previous element.

## 6) Shared Components

### `Button`

- Current variants: `primary`, `secondary`, and `quiet`.
- Height: 44px.
- Use `primary` only for primary actions.
- Use `secondary` for secondary actions.
- Use `quiet` for lower-emphasis operations.

### `Field`

- The label is always visible.
- Help and error text are linked semantically.
- Control height: 44px.
- Do not use a placeholder instead of a label.

### `Select`

- Search appears automatically when the configured threshold is exceeded.
- The trigger is a real button.
- Keyboard navigation and closing on outside click are supported.

### `Drawer`

- Uses a shared side-surface pattern.
- Includes focus management.
- Supports controlled dismissal.

### `Page` / `Panel`

- `Page` provides the general screen wrapper.
- `PageHeader` provides top-level headings.
- `Panel` provides operational surfaces.
- `PanelGrid` provides a two-column grid that collapses on small screens.

### `Feedback`

- `EmptyState` represents an empty state.
- `InlineError` represents a recoverable error.
- `SkeletonList` represents loading.
- `StatusBadge` represents repeated statuses.

## 7) Screen Patterns

### Dashboard

- Show no more than four primary indicators above the fold.
- Include status, source, period, and freshness when needed.

### Tables

- Use a clear header.
- Use quiet row dividers.
- Allow horizontal scrolling only when necessary.

### Trees / Boards

- Make selection distinct without visual noise.
- Use subtle hover states and tree lines.
- Do not nest cards inside cards.

### Forms

- Place labels above fields.
- Place errors near their fields.
- Keep layouts stable on mobile.

## 8) Accessibility

- Meet AA contrast for primary text.
- Provide a clear visible focus state.
- Provide accessible names for icon-only buttons.
- Do not make any state depend on color alone.

## 9) Assets and Network

- Bundle fonts and icons locally.
- `lucide-react` is the only icon source.
- Do not use a CDN, `fonts.googleapis.com`, or `unpkg`.
- Use same-origin internal APIs only.

## 10) Files to Modify for Design Changes

- `apps/web/src/index.css`
- `apps/web/src/styles/tokens.css`
- `apps/web/src/styles/base.css`
- `apps/web/src/styles/screens.css`
- `apps/web/src/app/AppShell.css`
- `apps/web/src/ui/ui.css`
- Relevant feature stylesheets under `apps/web/src/features/**/*.css`
- `apps/web/src/main.tsx`
- `DESIGN.md`

## 11) Operational Notes

- Every new change must be checked in both RTL and LTR.
- Verify login, dashboard, table, and drawer pages after every major design change.
- Add every new primitive to `apps/web/src/ui` before using it in a feature.
