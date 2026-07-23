---
name: Third Health Cluster Platform
description: Approved unified design system for an Arabic-first internal operations platform.
colors:
  canvas: "#F6F7F9"
  surface: "#FFFFFF"
  ink: "#1A2735"
  muted: "#5A6875"
  border: "#E4E4E7"
  primary: "#293B85"
  primary-hover: "#253679"
  accent: "#3DAAE1"
  success: "#247A42"
  warning: "#9A5B00"
  danger: "#B42318"
  on-color: "#FFFFFF"
  dark-canvas: "#000E22"
  dark-surface: "#082036"
  dark-muted: "#9EB0C3"
  dark-border: "#223A55"
typography:
  display:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "2rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "normal"
  headline:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.3
    letterSpacing: "normal"
  title:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  body:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "normal"
  label:
    fontFamily: "IBM Plex Sans Arabic, Tahoma, Arial, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  sm: "12px"
  md: "16px"
  pill: "999px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-color}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "10px 16px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.on-color}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "10px 16px"
    height: "44px"
  field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "10px 12px"
    height: "44px"
  panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "24px"
  metric-tile:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "16px"
  status-success:
    backgroundColor: "{colors.success}"
    textColor: "{colors.on-color}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "4px 8px"
---

# Design System: Third Health Cluster Platform

> **OWNER-APPROVED BASELINE — 2026-07-18**
>
> The owner approved the `access-management-dashboard.html` direction as the visual reference for the Dashboard and App Shell. The structure, colors, and density are applied locally, while the demo data, CDN, and external assets stay outside the product. The approved palette is an institutional blue (Institutional Navy) with a sky accent (Sky Accent) on a neutral gray background, replacing the previous green palette, while keeping the same contrast gates.

## 1. Overview

**Creative North Star: "A Quiet Operations Room"**

The design serves employees working on internal operational requests, decisions, and indicators. The user must feel they are inside a quiet operations room: information is trustworthy, status is clear, and the next action is obvious without ornament or noise. We preserve the strength of the reference in Arabic and RTL, regular spacing, and institutional structure, and we correct weak contrast, indicator crowding, numeric inconsistencies, and incomplete mobile states.

The design is unified across a single React application. No module creates its own library, components, icons, or indicators. The Shell owns the general primitives, while modules register their content and routes without copying primitives or knowing the details of other modules.

Every asset the user needs at runtime is inside the bundle or the deployment image. The build pipeline permits approved package sources pinned in lockfiles per `docs/operations/air-gap-supply-chain.md`, but the browser and production container do NOT depend on a CDN, font, script, image, or public API. The internal product API is same-origin as part of the system and is NOT an external call.

**Key Characteristics:**

- Arabic first with full English and RTL/LTR support.
- Disciplined operational density, with at most four primary decision indicators above the fold.
- Familiar, state-consistent components — no visual surprises or invented affordances.
- High-contrast dark teal-blue primary color, and semantic states are NOT used for decoration.
- Unified Lucide icons bundled in, and Apache ECharts is the default charting library behind unified internal React components.
- No external internet access required at runtime, and no silent fallback to a public resource.

**The One System Rule.** A single source exists for colors, fonts, spacing, and corners, with a single component library inside `apps/web/src/ui`, while `apps/web/src/app/AppShell.css` and `apps/web/src/index.css` orchestrate the overall application surface. Creating a parallel primitive inside a business module is forbidden.

**The Runtime-Local Rule.** Every font, icon, image, script, and style required at runtime is served from the same origin. `connect-src 'self'` is the default limit, and any exception requires a governed decision and a security review before execution.

**The Direction Rule.** Use logical properties and test every primitive in `dir="rtl"` and `dir="ltr"`. Do NOT use fixed-direction arrows or `left/right` when the meaning depends on the script direction.

## 2. Colors

A calm institutional blue palette derived from the approved reference, with a high-contrast navy tone that meets WCAG 2.2 AA on a neutral gray background. Color does not carry meaning alone; it is paired with text, an icon, or a line pattern.

### Primary

- **Institutional Navy** (`primary` `#293B85`): for primary action, current selection, and important links only. White on it achieves `10.20:1` contrast, and on canvas it achieves `9.52:1`.
- **Deep Navy** (`primary-hover` `#253679`): for hover and active states. White on it achieves `11.16:1`.

### Secondary

- **Sky Accent** (`accent` `#3DAAE1`): for section dividers, dots, and secondary indicators only. It is NOT used for plain text or primary buttons because its contrast on white (`2.61:1`) is insufficient for that role, and it is restricted to non-text graphic elements that meet `3:1`.

### Tertiary

- **Success Green** (`success`): completed success or a sound state only; NOT a general chart series.
- **Warning Ochre** (`warning`): a warning that needs attention and does NOT mean failure.
- **Danger Red** (`danger`): an error or destructive action only.

### Neutral

- **Neutral Canvas** (`canvas` `#F6F7F9`): the quiet neutral gray application background.
- **Clear Surface** (`surface` `#FFFFFF`): panels, lists, and fields.
- **Navy Ink** (`ink` `#1A2735`): text and headings; its contrast on canvas is `14.14:1`.
- **Readable Muted** (`muted` `#5A6875`): secondary text; its contrast on canvas is `5.34:1`.
- **Structural Border** (`border` `#E4E4E7`): quiet dividers and borders, not a substitute for spacing.
- **Night Canvas / Surface** (`dark-canvas` `#000E22`, `dark-surface` `#082036`): the two layers of the navy dark mode. Do NOT leave white gaps between the content and the footer.
- **Night Muted** (`dark-muted` `#9EB0C3`): secondary text that meets `8.70:1` on dark canvas.

**The Ten Percent Rule.** The primary color does not exceed about 10% of the screen, and is limited to action, selection, and status.

**The Redundant Signal Rule.** Every state or chart series is distinguished by color and text, and uses an extra dash or marker when comparing. Relying on color alone is forbidden.

**The Contrast Gate.** Plain text meets at least `4.5:1`, and large text and important graphic elements meet at least `3:1`. No new token is accepted before measuring its contrast in the light and dark themes.

## 3. Typography

**Display Font:** IBM Plex Sans Arabic (with Tahoma and Arial fallbacks)

**Body Font:** IBM Plex Sans Arabic (with Tahoma and Arial fallbacks)

**Label/Number Font:** IBM Plex Sans Arabic with `font-variant-numeric: tabular-nums`

**Character:** A single calm, clear family that prevents variation in the voice of the interface. Weight and size build hierarchy, and a display or mono font is NOT added purely for decoration.

IBM Plex Sans Arabic files are distributed locally inside the bundle; the suggested package version is `@fontsource/ibm-plex-sans-arabic` under the `OFL-1.1` license. The license text must be kept with the distribution. Font files MUST NOT be modified under a reserved name, and the font MUST NOT be resold standalone.

### Hierarchy

- **Display** (700, `2rem`, `1.25`): one page title only.
- **Headline** (700, `1.5rem`, `1.3`): major region headings.
- **Title** (600, `1.125rem`, `1.4`): a panel or group title.
- **Body** (400, `1rem`, `1.6`): operational and help text, with a reading width of `65-75ch` for long text.
- **Label** (600, `0.875rem`, `1.4`): buttons, labels, and statuses.
- **Meta** (400, `0.8125rem`, `1.4`): time, source, and period; NOT used for critical information.

**The Fixed Product Scale Rule.** The interface uses fixed `rem` sizes, NOT `clamp()`, for operational headings. Responsiveness reflows the structure rather than shrinking text until it loses clarity.

**The One Numeral Policy.** Numbers are displayed per the current locale across every indicator, axis, and ratio. Mixing Arabic and Western digits inside the same dashboard is forbidden.

## 4. Elevation

The system is flat by default. Spacing, surface-layer separation, and quiet borders build the structure. Shadows are NOT decoration and are NOT applied to every card; they appear only when an element is temporarily raised, such as a menu or dialog.

### Shadow Vocabulary

- **Resting Surface** (`none`): panels and cards in the resting state.
- **Floating Control** (`0 6px 22px rgb(11 47 58 / 12%)`): a menu, popover, or focused login card that the owner requested to highlight over the canvas.
- **Dialog** (`0 16px 48px rgb(7 31 43 / 24%)`): a dialog over a clear backdrop.

Motion transitions state in `150-250ms` using ease-out. Animating `width`, `height`, `margin`, or `padding` is forbidden when transform or opacity can be used. Every motion has an instant fallback under `prefers-reduced-motion`.

**The Flat-By-Default Rule.** If an element's level does not functionally change, it does NOT deserve a shadow.

**The No Glass Rule.** No decorative backdrop blur or glassmorphism in the shell or cards. Transparency is NOT a substitute for clear hierarchy.

## 5. Components

The general primitives live inside `apps/web/src/ui` only. Every component has a small API, supports Arabic and English, and documents the following states where applicable: default, hover, focus, active, selected, disabled, loading, empty, error, stale, and restricted.

### Buttons

- **Shape:** consistent modern corner radius for controls (`12px`).
- **Primary:** `primary` with `on-color`, `44px` height, and `10px 16px` inner padding.
- **Hover / Focus:** `primary-hover` for hover, and a unified `3px` focus ring that does NOT rely on color alone.
- **Secondary:** surface with ink text and a border. A third ghost button is NOT created without a proven need.
- **Icon Button:** `44x44px`, with an accessible name and tooltip when the visible text is absent.
- **Loading:** keeps its width, disables repeat submission, and announces status to screen readers.

### Chips

- Used for filters or short statuses only, NOT as decoration.
- Minimum interactive target `44px` when clickable.
- Every status carries clear text and does NOT use a colored dot alone.

### Cards / Containers

- **Corner Style:** `16px` for cards and surfaces, and `12px` for inner elements.
- **Background:** surface on canvas, or dark-surface on dark-canvas.
- **Shadow Strategy:** no shadow at rest.
- **Border:** `1px` of border when separation is needed.
- **Internal Padding:** `16px` for the small indicator and `24px` for the analysis panel.
- No cards inside cards, and no identical card grid when the items are NOT of the same type.

### Inputs / Fields

- At least `44px` height, visible label, and help/error linked via `aria-describedby`.
- Fields use a subtle focus glow without a solid blue outline at the owner's request, while buttons and links retain a clearer outline ring; both are defined via unified tokens.
- Unified focus, and the error appears near the field with a summary on long forms.
- Placeholder does NOT replace the label and must meet the required contrast.

### Navigation

- One Shell for top and side navigation. The current location is shown with text and the `aria-current` state.
- The collapsible side menu is a real dialog: visible close button, focus trap, inert background, Escape, and focus restoration.
- Collapsed or off-screen items are removed from the tab order.
- A single destination does NOT exceed five top-level items before grouping or progressive disclosure.

### Icons

- The only library is `lucide-react`, added to the lockfile and bundled in Vite; UMD, CDN, and `data-lucide` runtime replacement are forbidden.
- Import the required icons with explicit names to support tree-shaking. Approved sizes are `16`, `20`, `24px`, with `strokeWidth={1.75}`.
- Do NOT mix Lucide with emoji, icon fonts, or a second SVG library.
- Decorative icons use `aria-hidden="true"`. Icons that perform an action live inside a labeled button.
- Directional icons mirror per RTL/LTR when their meaning changes, while neutral icons do NOT mirror.
- `lucide-react` is licensed under `ISC`; the copyright and license text MUST be kept in the distribution notices file.

### Dashboard Indicators

- The only indicator primitives are `MetricTile`, `StatusBadge`, `ProgressBar`, `ChartLegend`, `ChartTooltip`, and `DataFreshness`.
- A page does NOT show more than four primary `MetricTile`s above the fold. Move secondary indicators to a later group or progressive disclosure.
- Every indicator specifies: name, unit, period, update time, source, and the meaning of zero/empty/unavailable.
- Zero is a real value; empty is a missing record; unavailable is missing permission or source. They MUST NOT look or read alike.
- Ratios show numerator and denominator when they affect the decision. Showing `46.2%` next to `5/6` is forbidden if they are NOT the same measurement.

### Charts

- The default and only charting library is Apache ECharts, used behind a unified internal React component such as `DashboardChart` so library options do NOT leak into business modules.
- ECharts is pinned in the lockfile and bundled into Vite; loading it from a CDN or calling it through an external API is forbidden.
- Use selective imports from `echarts/core` with only the required chart types and components, and `SVGRenderer` is the default renderer. Importing the full `echarts` package without measuring the bundle and documenting a justification is forbidden.
- Enable `AriaComponent`, `aria.show`, and decal patterns where needed, but the generated ARIA does NOT replace an equivalent table or text summary.
- Every series has a unique color + dash + marker, and the legend uses the same symbol.
- A title, description, period, unit, freshness, empty/loading/error/stale, and an equivalent table or text summary are available to the screen reader and keyboard.
- The tooltip is NOT the only path to a value, and works with focus and touch in addition to hover.
- Mobile does NOT shrink the chart until the axes become unreadable; it uses a custom summary or a plot with a `220px` height cap and controlled internal scrolling.
- Apache ECharts is licensed under `Apache-2.0`; the LICENSE and any distributed NOTICE MUST be kept, and any local source modification MUST be documented.

### Runtime Assets and Network

- Fonts, icons, logos, images, CSS, and JavaScript are local files embedded in the build or the deployment image.
- Apache ECharts, Lucide, and the fonts are local build packages inside the bundle and do NOT make network requests, telemetry, or update checks at runtime.
- `fonts.googleapis.com`, `fonts.gstatic.com`, `unpkg.com`, and any CDN or public script are forbidden.
- The internal API uses same-origin paths like `/api/...` behind Caddy/nginx. A component or library MUST NOT connect to an external address directly.
- The target CSP policy starts with `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'`.
- Any new package passes review: need, bundle size, license, lockfile, vulnerabilities, network calls, telemetry, and the removal alternative.

**The Complete State Rule.** A primitive without appropriate loading/error/disabled/focus states is incomplete and is NOT used in a feature.

**The No Silent Network Rule.** Fallback to a CDN, telemetry, update check, or remote asset is forbidden. A missing local asset surfaces in the build or test and does NOT hide behind a public connection.

## 6. Do's and Don'ts

### Do:

- **Do** use the tokens in this file and the `apps/web/src/ui` components for every module.
- **Do** use `lucide-react` only for icons, with explicit imports and retaining the ISC license notice.
- **Do** use Apache ECharts only via `DashboardChart`, with `SVGRenderer` and selective imports and retaining the Apache-2.0 requirements.
- **Do** bundle IBM Plex Sans Arabic and retain the OFL-1.1 license.
- **Do** test every screen in Arabic RTL and English LTR across desktop, tablet, mobile, and 200% zoom.
- **Do** make primary touch targets at least `44x44px`, and inline links at least `24x24px`.
- **Do** provide a table or text summary for every chart, and distinguish series by color, pattern, and marker.
- **Do** display freshness, period, unit, and the meaning of zero for every decision indicator.
- **Do** use a same-origin API, apply CSP, and fail the test when a public URL appears in the runtime bundle or HTML.
- **Do** review the license, vulnerabilities, and network behavior of any package before adding it, and report any restriction to the owner before adoption.

### Don't:

- **Don't** depend on a CDN, Google Fonts, unpkg, a remote image, or a public API at runtime.
- **Don't** let a UI, icon, or chart library send telemetry, check for updates, or fetch externally.
- **Don't** add a second charting library or use ECharts directly inside a business module, bypassing the unified component.
- **Don't** create a different component library, tokens, or icon set inside a business module.
- **Don't** use an icon font or emoji alongside Lucide as part of the product language.
- **Don't** show more than four equally-weighted primary indicators above the fold.
- **Don't** mix zero with empty or unavailable, or show contradictory numbers across KPIs, charts, and lists.
- **Don't** rely on color alone or hover alone to convey a value or status.
- **Don't** use `border-left` or `border-right` thicker than `1px` as an accent bar on a card or alert.
- **Don't** use gradient text, glassmorphism, nested cards, or decoration that does not serve the user's decision.
- **Don't** leave a closed drawer in the tab order, or an open drawer without a focus trap and close button.
- **Don't** add a package or asset before documenting its license and the required distribution restrictions.