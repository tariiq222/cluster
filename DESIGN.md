---
name: Cluster
description: Governed health-cluster administration with quiet institutional confidence.
colors:
  command-ink: "oklch(0.208 0.042 265.755)"
  body-ink: "oklch(0.129 0.042 264.695)"
  calibrated-white: "oklch(1 0 0)"
  contrast-white: "oklch(0.984 0.003 247.858)"
  quiet-surface: "oklch(0.968 0.007 247.896)"
  quiet-ink: "oklch(0.554 0.046 257.417)"
  structural-line: "oklch(0.929 0.013 255.508)"
  focus-ring: "oklch(0.704 0.04 256.788)"
  controlled-red: "oklch(0.577 0.245 27.325)"
  signal-orange: "oklch(0.646 0.222 41.116)"
  signal-teal: "oklch(0.6 0.118 184.704)"
  signal-steel: "oklch(0.398 0.07 227.392)"
  signal-yellow: "oklch(0.828 0.189 84.429)"
  signal-amber: "oklch(0.769 0.188 70.08)"
typography:
  headline:
    fontFamily: "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: "2rem"
    letterSpacing: "-0.025em"
  title:
    fontFamily: "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: 500
    lineHeight: "1.375rem"
    letterSpacing: "normal"
  body:
    fontFamily: "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: "1.25rem"
    letterSpacing: "normal"
  label:
    fontFamily: "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: "1rem"
    letterSpacing: "normal"
  mono:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace"
    fontWeight: 400
    letterSpacing: "normal"
rounded:
  sm: "0.375rem"
  md: "0.5rem"
  lg: "0.625rem"
  xl: "0.875rem"
  pill: "2rem"
spacing:
  "1": "0.25rem"
  "1.5": "0.375rem"
  "2": "0.5rem"
  "2.5": "0.625rem"
  "3": "0.75rem"
  "4": "1rem"
  "6": "1.5rem"
components:
  button-primary:
    backgroundColor: "{colors.command-ink}"
    textColor: "{colors.contrast-white}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    height: "2rem"
    padding: "0 0.625rem"
  button-outline:
    backgroundColor: "{colors.calibrated-white}"
    textColor: "{colors.body-ink}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    height: "2rem"
    padding: "0 0.625rem"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.body-ink}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    height: "2rem"
    padding: "0 0.625rem"
  button-destructive:
    backgroundColor: "oklch(0.577 0.245 27.325 / 10%)"
    textColor: "{colors.controlled-red}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    height: "2rem"
    padding: "0 0.625rem"
  input-default:
    backgroundColor: "transparent"
    textColor: "{colors.body-ink}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    height: "2rem"
    padding: "0 0.625rem"
  badge-outline:
    backgroundColor: "transparent"
    textColor: "{colors.body-ink}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    height: "1.25rem"
    padding: "0 0.5rem"
  card-default:
    backgroundColor: "{colors.calibrated-white}"
    textColor: "{colors.body-ink}"
    typography: "{typography.body}"
    rounded: "{rounded.xl}"
    padding: "1rem"
  navigation-active:
    backgroundColor: "{colors.quiet-surface}"
    textColor: "{colors.command-ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    height: "2rem"
    padding: "0 0.5rem"
  table-row:
    textColor: "{colors.body-ink}"
    typography: "{typography.body}"
    padding: "0.5rem"
---

# Design System: Cluster

## Overview

**Creative North Star: "The Operations Briefing"**

Every Cluster screen is an operations briefing: it opens with the current state, states the effective scope, and names the permitted next action. The system carries itself with quiet institutional confidence — calm, authoritative, and precise, direct and exact without stiffness, dense without noise. Users review state, confirm scope, and complete the next action with certainty; ambiguity about state, ownership, or the next step is treated as a design failure.

The density is deliberately restrained. Predictable tables, disciplined hierarchy, compact controls, and fast navigation carry complex administration without decoration or novelty. Work is reviewed, scoped, and completed in that order, and governance — permissions, approvals, versions, auditability — explains what can happen next instead of hiding in friction. Every surface earns its place in the briefing.

The experience is Arabic-first RTL with an equivalent English LTR: one interface family for human-readable copy — with a narrow mono exception for machine identifiers — one layout grammar, full parity in meaning, capability, and interaction quality. What this system rejects is generic-template assembly — it must never read as a recognizable shadcn demo assembled without Cluster-specific hierarchy, workflow judgment, or operational context.

**Key Characteristics:**
- **Quiet institutional confidence.** Calm, authoritative, precise; focused without visual noise.
- **State, scope, next action.** Every screen makes the current state, effective scope, and permitted next action understandable before asking the user to act.
- **Restrained product density.** Dense, predictable information architecture with fast navigation instead of decoration.
- **Structural rings, rare lift.** Flat surfaces separated by one-pixel rings; shadows only for floating navigation and overlays.
- **One interface family, one data exception.** IBM Plex Sans Arabic for all UI copy; the default mono stack only for technical identifiers, rendered LTR in either page direction.
- **Arabic-first, bilingual by design.** RTL and LTR experiences stay equivalent in meaning, capability, and interaction quality.
- **No generic-template assembly.** Rejects any recognizable shadcn demo without Cluster-specific hierarchy, workflow judgment, or operational context.

## Colors

A cool near-black ink on a calibrated white field, separated by quiet blue-grey surfaces and one-pixel structural lines; the only saturated voice is a single controlled red, reserved for destruction.

The frontmatter records the canonical light palette. The `.dark` block in theme.css remaps the same semantic roles — even Command Ink flips to a light tone — so this document does not duplicate a second hand-authored dark palette. In application source, literal colors exist only in theme.css; everything else references semantic tokens.

### Primary

- **Command Ink** (oklch(0.208 0.042 265.755)): the filled primary-action color (primary buttons, primary badges) and the selected/active-state foreground (accent-foreground) — not the default body text, which is Body Ink. It is the only brand accent, and it is deliberately rare.
- **Calibrated White** (oklch(1 0 0)): the default background, card, and popover surface — the field on which all governed work is laid out.
- **Contrast White** (oklch(0.984 0.003 247.858)): text on Command Ink surfaces (primary buttons, primary badges); also the light foreground of the dark theme.

### Neutral

- **Quiet Surface** (oklch(0.968 0.007 247.896)): secondary and muted backgrounds, hover fills, accents, and the active sidebar row; a near-white with a faint cool cast.
- **Quiet Ink** (oklch(0.554 0.046 257.417)): muted foreground — metadata, labels, descriptions, placeholders, captions.
- **Structural Line** (oklch(0.929 0.013 255.508)): borders and input strokes; the one-pixel ring of the system.
- **Focus Ring** (oklch(0.704 0.04 256.788)): keyboard focus rings and outlines.
- **Body Ink** (oklch(0.129 0.042 264.695)): the default `foreground`, `card-foreground`, and `popover-foreground` — body text, table text, card text, input text, and outline/ghost control text.

### Semantic

- **Controlled Red** (oklch(0.577 0.245 27.325)): destructive actions, destructive alerts, and invalid fields. Always rendered as a tinted background with red text, or as an outline signal — never as a large surface.

There is deliberately no success or warning token: positive and cautionary states are expressed with an outline badge plus a lucide icon, not with a dedicated color.

### Data Visualization

- **Signal Orange** (oklch(0.646 0.222 41.116)): chart-1.
- **Signal Teal** (oklch(0.6 0.118 184.704)): chart-2.
- **Signal Steel** (oklch(0.398 0.07 227.392)): chart-3.
- **Signal Yellow** (oklch(0.828 0.189 84.429)): chart-4.
- **Signal Amber** (oklch(0.769 0.188 70.08)): chart-5.

These five hues form the chart palette (chart-1…chart-5). They carry no fixed business meaning: hue-to-series assignment is per chart, every chart carries a legend, and any meaning-bearing signal pairs the hue with an icon or text so it never depends on color alone.

### Named Rules

**The Semantic Token Rule.** No literal color value appears anywhere in application source except theme.css. Every fill, text, and border is a semantic token, so the entire system recolors in one place.

**The Restrained Accent Rule.** Command Ink is confined to filled primary actions and the selected/active-state foreground — never default body text, which is Body Ink; signal hues never leave charts. Its rarity is the point: one decisive accent on a calm field.

**The Automatic Remap Rule.** Never write manual `dark:` color overrides. The `.dark` block in theme.css remaps the same semantic roles, so screens declare tokens once and inherit both themes.

## Typography

**Interface Font:** 'IBM Plex Sans Arabic' (with Tahoma, Arial, sans-serif fallback) — the single family for every human-readable role.

**Mono/Data Font:** `ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace` (Tailwind's default mono stack) — a utility role for machine identifiers, not a second expressive voice.

**Character:** One family, on purpose. IBM Plex Sans Arabic carries both scripts without a second pairing, so Arabic-first RTL and English LTR read as the same system. Calm and precise: no display face, no fluid sizing, no novelty weights — hierarchy comes from a fixed scale of size and weight.

### Hierarchy

- **Headline** (600, 1.5rem, 2rem line-height, -0.025em letter-spacing): page and section headings; the loudest voice in the system and still quiet.
- **Title** (500, 1rem, 1.375rem line-height): card titles, list-item titles, section-level names.
- **Body** (400, 0.875rem, 1.25rem line-height): the default product size — tables, forms, buttons, descriptions, content. Prose lines are capped at 65–75ch.
- **Label** (500, 0.75rem, 1rem line-height): captions, metadata, badges, sidebar group labels, table captions.
- **Mono Data** (400, no independent fixed size, inherits the surrounding label/body size): IDs, codes, usernames, policy/capability codes, log sources, employee numbers, and correlation IDs; technical strings render `dir="ltr"` inside either page direction.

### Named Rules

**The One Interface Family Rule.** IBM Plex Sans Arabic is the only family for human-readable UI copy. The default mono stack is permitted only for machine identifiers and must not be used for headings, buttons, prose, or personality.

**The Fixed Scale Rule.** The product scale is fixed — headline, title, body, label — with no clamp() fluid type and no display sizes. Mono Data inherits body and label sizes and does not create a fifth scale step. Density and predictability are features.

## Elevation

Structural rings, rare lift. Cards and resting surfaces are separated from the background by tonal contrast and a one-pixel ring (ring-1, ring-foreground/10), never by shadows: depth is drawn with lines, not light. Shadows are reserved for the few elements that truly float — the active tab, the floating/inset sidebar variant — and for overlays. There is no decorative glassmorphism; the only blur in the system is the functional scrim behind dialogs and sheets.

### Shadow Vocabulary

- **Structural Ring** (`0 0 0 1px color-mix(in oklch, var(--foreground) 10%, transparent)`): the default separation for cards, dialogs, and containers. Not a shadow — a line.
- **Rare Floating Lift** (`0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)` — Tailwind `shadow-sm`): only for truly floating or inset navigation and the active tab; never on resting content.

### Named Rules

**The Structural Ring Rule.** Depth is carried by one-pixel rings and tonal contrast, never by default shadows. A card without a ring is a mistake; a card with a drop shadow is a bigger one.

**The Flat-By-Default Rule.** Surfaces are flat at rest. A shadow is a state, not a style: it appears only when something genuinely floats above the work — floating or inset navigation, overlays. Decorative glassmorphism is prohibited.

## Components

Compact controls on a fixed 2rem control height, gently curved lg radius (0.625rem), semantic tokens only, and 100–200ms state transitions with a global reduced-motion override.

### Buttons

- **Shape:** gently curved edges (0.625rem lg radius); default controls are 2rem tall with 0.625rem inline padding and a 0.375rem icon gap.
- **Primary:** Command Ink background with Contrast White text; hover softens the fill to an 80% alpha tint of the token. The decisive action on the screen — one per view where possible.
- **Outline:** Calibrated White background, Structural Line border, Body Ink text; hover fills with Quiet Surface. The standard secondary action.
- **Ghost:** transparent background; hover fills with Quiet Surface. Toolbar actions and dialog close buttons.
- **Destructive:** Controlled Red at a 10% tint with Controlled Red text; hover deepens to 20%. Reserved for irreversible actions, never the default.
- **States:** focus-visible shifts to the ring token (border-ring with a 3px ring at 50%); active presses down one pixel; disabled is 50% opacity with no pointer events; invalid targets surface the destructive treatment.

### Chips / Badges

- **Style:** pill radius (2rem), 1.25rem tall, 0.5rem inline padding, label type (0.75rem, 500 weight). Outline variant: Structural Line border with Body Ink text. Positive and cautionary statuses use the outline badge with a lucide icon — there are no success or warning color tokens by design.

### Cards / Containers

- **Corner Style:** xl radius (0.875rem).
- **Background:** the card token (Calibrated White; remapped in dark mode).
- **Shadow Strategy:** none — a one-pixel structural ring (ring-foreground/10) per the Elevation section.
- **Border:** the structural ring doubles as the border; no separate stroke.
- **Internal Padding:** 1rem on all sides with a 1rem internal section gap. Cards never nest.

### Inputs / Fields

- **Style:** 2rem tall, lg radius (0.625rem), transparent fill with a Structural Line stroke; placeholder in Quiet Ink.
- **Focus:** the stroke shifts to the ring token with a 3px ring at 50%.
- **Error / Disabled:** `aria-invalid` swaps stroke and ring to the destructive token; disabled is 50% opacity with an input-tinted fill and no pointer events.

### Tables

- Body type (0.875rem, 400 weight); rows are separated by a one-pixel Structural Line and hover to 50% Quiet Surface; selected rows fill Quiet Surface. Header cells are 2.5rem tall, medium weight, start-aligned. Pagination is cursor-based only — never page numbers, never "page X of Y".

### Navigation

- Sidebar menu rows are 2rem tall with 0.5rem padding and md radius (0.5rem); hover and active states fill with the accent token (Quiet Surface), and the active row adds medium weight. The sidebar collapses to icon mode and becomes a sheet on mobile; structure transitions run at 200ms.

### Dialogs / Sheets

- Dialog content is xl radius (0.875rem) on the popover surface with 1rem padding, a structural ring, and a 100ms fade + 95% zoom; the overlay is a 10% scrim with a functional blur. Sheets handle quick edits in list→detail flows. A modal is never the first thought — prefer inline editing, sheets, or navigation, and use a dialog only when focus is genuinely required.

### Resource States (the seven)

Every data screen renders all seven states: `loading` (skeleton rows shaped like the incoming content — never a generic spinner), `ready`, `empty` (icon, one-line explanation, permitted primary action), `forbidden` and `not-found` (the exact same non-disclosing copy — the difference is a leak), `conflict` (409 alert with a readable cause and retry), `stale` (412 "record changed" alert with refresh), and `error` (alert, retry, and a correlation ID for support). State is derived once in the shared transport layer; screens never reclassify errors.

### Motion (folded in)

State transitions are compact: 100ms for overlays, 150ms for default state feedback (buttons, inputs, badges, rows), 200ms for sidebar structure. The global reduced-motion override forces every animation and transition duration to 0.01ms under `prefers-reduced-motion: reduce`.

**The No-Modal-Reflex Rule.** A modal is never the first thought. Prefer inline editing, sheets, or navigation; use a dialog only when focus is genuinely required.

**The Generated-Primitives Rule.** `src/components/ui/` is generated by shadcn and is never hand-edited. Customization happens by wrapping primitives in `src/components/`.

## Do's and Don'ts

### Do:

- **Do** use semantic tokens exclusively (bg-primary, text-muted-foreground, border-border); literal colors live only in theme.css.
- **Do** use logical RTL properties (ms-, me-, ps-, pe-, start-, end-) so the interface flips cleanly between Arabic and English.
- **Do** keep Arabic-first RTL and English LTR in full parity: same meaning, same capability, same interaction quality on every screen.
- **Do** render all seven resource states on every data screen, with loading as skeletons shaped like the incoming content.
- **Do** keep cards flat with a one-pixel structural ring and 1rem padding; never a resting shadow.
- **Do** keep state transitions compact (100–200ms) and honor the global reduced-motion override.
- **Do** express positive and cautionary statuses with an outline badge plus a lucide icon — there are no success or warning tokens.
- **Do** make meaning color-independent: any color signal carries an icon or text alongside it.
- **Do** customize generated primitives by wrapping them in src/components/ — never by editing the source.

### Don't:

- **Don't** resemble a generic template UI: a recognizable shadcn demo assembled without Cluster-specific hierarchy, workflow judgment, or operational context.
- **Don't** nest cards — one card layer per surface; a card inside a card is always wrong.
- **Don't** use colored side-stripe accents: no border-left or border-right greater than 1px as decoration.
- **Don't** use gradient text (background-clip: text); emphasis comes from weight, size, and solid color.
- **Don't** use decorative glassmorphism; blur appears only as the functional scrim behind dialogs and sheets.
- **Don't** write raw literal colors (#hex, rgb(), hsl(), oklch()) anywhere outside theme.css.
- **Don't** write manual `dark:` color overrides — the `.dark` remap in theme.css does that work automatically.
- **Don't** edit generated UI primitives directly (src/components/ui/, src/api/generated/) — regenerate or wrap.
