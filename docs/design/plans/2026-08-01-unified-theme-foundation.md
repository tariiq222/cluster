# Unified Theme Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hand-written CSS design system in `apps/web` with the shadcn-admin theme (Tailwind v4 + shadcn/ui + Radix), rebuild the app shell on it, and install the automated gates that keep every future screen conformant.

**Architecture:** Tailwind v4 is configured CSS-first — no `tailwind.config.js`. A single `src/styles/theme.css` holds the template's tokens verbatim and is the only file permitted to contain literal color values. shadcn/ui components are generated into `src/components/ui/` by the shadcn CLI and are never hand-edited. The existing React Router tree, TanStack Query setup, Orval clients, and `src/api/*` transport are kept as-is — this plan changes presentation only. Legacy CSS and `src/ui/index.tsx` are deleted atomically with the screen that stops importing them, never before.

**Tech Stack:** Tailwind v4.2 · `@tailwindcss/vite` · shadcn/ui (new-york) · Radix UI · `@tanstack/react-table` · `sonner` · `react-hook-form` + `zod` · `lucide-react` · React 19 · Vite 8 · TypeScript 6

## Global Constraints

- **Binding design contract:** `docs/design/DESIGN-RULES.md`. Every task inherits it. `docs/design/PAGES.md` specifies each page's purpose, pattern, output, and endpoints.
- **Zero literal colors.** No `#hex`, `rgb()`, `hsl()`, or `oklch()` anywhere except `apps/web/src/styles/theme.css`. Token values are copied from the template unchanged.
- **Logical properties only.** `ms-/me-/ps-/pe-/start-/end-/text-start/text-end/border-s/border-e/rounded-s/rounded-e`. Never `ml-/mr-/pl-/pr-/left-/right-/text-left/text-right/border-l/border-r`.
- **Arabic-first RTL.** `dir="rtl"` on `<html>` for `ar`; font stays `IBM Plex Sans Arabic` (deliberate deviation from the template's Inter/Manrope).
- **Never hand-edit** `apps/web/src/api/generated/` or `apps/web/src/components/ui/`.
- **No raw HTTP headers in screens.** `Idempotency-Key`, `If-Match`, `X-CSRF-Token`, `X-Correlation-ID` come from `requestInit` in `src/api/http.ts` via `src/api/hooks.ts`.
- **Feature-gated destinations are absent, not disabled.** When `work_management` is off the API returns a non-disclosing 404; the UI must show no link, no greyed link, and no "unavailable" message.
- **403 and 404 render the identical string.**
- **Commands run from the repo root** unless a step says otherwise. Web commands use `npm --prefix apps/web`.
- Do not run `git push`. Do not create branches unless a step says so.

---

## File Structure

| Path | Responsibility |
|---|---|
| `apps/web/src/styles/theme.css` | **Create.** Template tokens verbatim. Only file allowed literal colors. |
| `apps/web/src/styles/index.css` | **Create.** `@import "tailwindcss"`, theme import, fonts, base layer, RTL utilities. |
| `apps/web/src/styles/{tokens,base,shell,screens}.css` | **Delete in Task 12** — only after the last importer is gone. |
| `apps/web/src/lib/utils.ts` | **Create.** `cn()` helper (clsx + tailwind-merge). |
| `apps/web/src/components/ui/*` | **Create via shadcn CLI.** Never hand-edited. |
| `apps/web/src/components/states.tsx` | **Create.** The seven resource states as shared components. |
| `apps/web/src/components/data-table.tsx` | **Create.** Cursor-paginated TanStack Table wrapper. |
| `apps/web/src/components/theme-provider.tsx` | **Create.** Light/dark/system, persisted. |
| `apps/web/src/components/command-menu.tsx` | **Create.** `⌘K` search palette. |
| `apps/web/src/app/AppShell.tsx` | **Rewrite** on shadcn `Sidebar`. |
| `apps/web/src/ui/index.tsx` | **Delete in Task 12.** |
| `apps/web/components.json` | **Create.** shadcn CLI config. |
| `apps/web/vite.config.ts` | **Modify.** Add `@tailwindcss/vite` + `@` alias. |
| `apps/web/tsconfig.app.json` | **Modify.** Add `paths` for `@/*`. |
| `apps/web/.oxlintrc.json` | **Modify.** RTL + literal-color bans. |
| `apps/web/vitest.config.ts` | **Modify.** Fix the dead `coverage.include`. |
| `scripts/verify-page-coverage.py` | **Create.** Completeness gate. |
| `Makefile` | **Modify.** Add `verify-design`. |

---

## Task 1: Establish the verification baseline

Nothing in this plan is trustworthy without knowing what already passes. The `coverage` gate is currently vacuous: `vitest.config.ts` sets `coverage.include: ['src/api.ts']` and that file does not exist, so v8 reports `0/0` and the 100% thresholds pass without measuring anything.

**Files:**
- Modify: `apps/web/vitest.config.ts`
- Overwrite: `docs/design/plans/baseline.md` (a seed file already exists; replace it with measured values)

**Interfaces:**
- Produces: `docs/design/plans/baseline.md` — the recorded pass/fail state every later task compares against.

- [ ] **Step 1: Record the current state of every gate**

```bash
cd /Users/tariq/code/R3/cluster
{
  echo "# Baseline — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo; echo '## tsc'; npm --prefix apps/web exec tsc -b 2>&1 | tail -5; echo "exit=$?"
  echo; echo '## lint'; npm --prefix apps/web run lint 2>&1 | tail -20
  echo; echo '## unit'; npm --prefix apps/web run test:unit 2>&1 | tail -10
  echo; echo '## e2e list'; npm --prefix apps/web run test:e2e:list 2>&1 | tail -3
} > docs/design/plans/baseline.md
cat docs/design/plans/baseline.md
```

Expected: `tsc` exit 0 · lint reports warnings but 0 errors · unit `9 passed (9)` / `3 passed (3)` · e2e list `Total: 53 tests in 15 files`.

If any of these differ, **stop and report** — the repository is not in the state this plan assumes.

- [ ] **Step 2: Prove the coverage gate is dead**

```bash
npm --prefix apps/web run coverage 2>&1 | tail -12
```

Expected: a table reading `All files | 0 | 0 | 0 | 0` and `Statements : Unknown% ( 0/0 )`. This confirms the gate measures nothing.

- [ ] **Step 3: Point coverage at code that exists**

In `apps/web/vitest.config.ts`, replace the `coverage` block:

```ts
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      reportsDirectory: './coverage',
      include: ['src/api/http.ts', 'src/api/session.ts', 'src/i18n.ts'],
      thresholds: {
        branches: 60,
        functions: 60,
        lines: 60,
        statements: 60,
      },
    },
```

Also delete the stale `exclude` entries — `src/features/r1/**`, `src/features/workflow/**`, `src/features/requests/**`, `src/features/procedure-authoring/**`, `src/features/procedure-office-review/**`, `src/features/procedure-guide/**`, `src/features/docs/**` — none of those directories exist. Keep only `'**/node_modules/**'`.

- [ ] **Step 4: Run coverage and read the real number**

```bash
npm --prefix apps/web run coverage 2>&1 | tail -12
```

Expected: real percentages for the three files. If any is under the 60 threshold the command exits non-zero — that is the honest signal. Record the actual numbers in `baseline.md` under a `## coverage` heading, then **lower the four thresholds to the measured values rounded down to the nearest 5** so the gate passes today and ratchets upward later. Do not raise thresholds to values the code does not meet.

- [ ] **Step 5: Commit**

```bash
git add apps/web/vitest.config.ts docs/design/plans/baseline.md
git commit -m "test(web): make the coverage gate measure real files

coverage.include pointed at src/api.ts, which does not exist, so v8
reported 0/0 and the 100% thresholds passed without measuring anything.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 2: Install Tailwind v4 and the theme tokens

**Files:**
- Modify: `apps/web/package.json`, `apps/web/vite.config.ts`, `apps/web/tsconfig.app.json`, `apps/web/src/main.tsx`
- Create: `apps/web/src/styles/theme.css`, `apps/web/src/styles/index.css`, `apps/web/src/lib/utils.ts`

**Interfaces:**
- Produces: `cn(...inputs: ClassValue[]): string` from `@/lib/utils` — every later task uses it. The `@/*` path alias resolves to `apps/web/src/*`.

- [ ] **Step 1: Install dependencies**

```bash
npm --prefix apps/web install tailwindcss@^4.2.2 @tailwindcss/vite@^4.2.2 tw-animate-css clsx tailwind-merge class-variance-authority @radix-ui/react-direction
npm --prefix apps/web install -D @types/node
```

- [ ] **Step 2: Fetch the template tokens verbatim**

```bash
curl -fsSL https://raw.githubusercontent.com/satnaing/shadcn-admin/main/src/styles/theme.css \
  -o apps/web/src/styles/theme.css
head -20 apps/web/src/styles/theme.css
```

Expected: `:root` with `--radius: 0.625rem`, `--background: oklch(1 0 0)`, `--primary: oklch(0.208 0.042 265.755)`, a `.dark` block, and an `@theme inline` block.

**Do not modify any value in this file.** If the fetch fails, stop and report — hand-authoring approximations of the tokens defeats the purpose of the plan.

- [ ] **Step 3: Remove the template's Latin font declarations from `@theme inline`**

The template declares `--font-inter` and `--font-manrope`. Delete those two lines only; leave every color and radius untouched. Then append to `apps/web/src/styles/theme.css`:

```css
@theme inline {
  --font-sans: 'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif;
}
```

- [ ] **Step 4: Create the stylesheet entry point**

Create `apps/web/src/styles/index.css`:

```css
@import 'tailwindcss';
@import 'tw-animate-css';
@import './theme.css';

@custom-variant dark (&:is(.dark *));

@layer base {
  * {
    @apply border-border outline-ring/50;
  }
  html {
    font-family: var(--font-sans);
  }
  body {
    @apply bg-background text-foreground;
  }
}
```

- [ ] **Step 5: Add the Vite plugin and the `@` alias**

In `apps/web/vite.config.ts`, add at the top:

```ts
import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
```

Change the plugins array and add `resolve` inside the returned object:

```ts
    plugins: [frontmanPlugin({ host: 'api.frontman.sh' }), react(), tailwindcss()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
```

Add the same alias to `apps/web/vitest.config.ts` so tests resolve `@/`:

```ts
import { fileURLToPath, URL } from 'node:url'
// inside defineConfig({ ... }):
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
```

- [ ] **Step 6: Teach TypeScript the alias**

In `apps/web/tsconfig.app.json`, inside `compilerOptions`:

```json
    "baseUrl": ".",
    "paths": { "@/*": ["./src/*"] },
```

- [ ] **Step 7: Add the `cn` helper**

Create `apps/web/src/lib/utils.ts`:

```ts
import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs))
}
```

- [ ] **Step 8: Import the new stylesheet alongside the old ones**

In `apps/web/src/main.tsx`, add `import './styles/index.css'` **immediately after** the fontsource imports and **before** `./styles/tokens.css`. Keep all four legacy imports for now — Task 12 removes them. Tailwind's reset must load first so legacy rules still win during the migration.

- [ ] **Step 9: Verify the build and prove a token resolves**

```bash
npm --prefix apps/web exec tsc -b && npm --prefix apps/web run build 2>&1 | tail -5
```

Expected: build succeeds. Then:

```bash
grep -c 'oklch' apps/web/dist/assets/*.css
```

Expected: a non-zero count, proving the tokens reached the bundle.

- [ ] **Step 10: Commit**

```bash
git add apps/web/package.json apps/web/package-lock.json apps/web/vite.config.ts \
        apps/web/vitest.config.ts apps/web/tsconfig.app.json apps/web/src/main.tsx \
        apps/web/src/styles/theme.css apps/web/src/styles/index.css apps/web/src/lib/utils.ts
git commit -m "feat(web): install Tailwind v4 and the shadcn-admin theme tokens

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 3: Generate the shadcn/ui component set

**Files:**
- Create: `apps/web/components.json`, `apps/web/src/components/ui/*`

**Interfaces:**
- Produces: `Button`, `Card`, `Table`, `Sheet`, `Dialog`, `AlertDialog`, `DropdownMenu`, `Input`, `Label`, `Select`, `Tabs`, `Badge`, `Skeleton`, `Alert`, `Separator`, `Sidebar`, `Tooltip`, `Command`, `Popover`, `Switch`, `Checkbox`, `Form`, `Sonner`, `ScrollArea`, `Collapsible`, `Avatar` — all from `@/components/ui/<kebab-name>`.

- [ ] **Step 1: Initialise the shadcn CLI**

```bash
cd apps/web && npx shadcn@latest init --defaults --base-color neutral --yes
```

This writes `components.json`. Verify it points at the right paths:

```bash
cat apps/web/components.json
```

Expected: `"tailwind": { "css": "src/styles/index.css", ... }` and `"aliases": { "components": "@/components", "utils": "@/lib/utils" }`. If `css` points elsewhere, edit it to `src/styles/index.css`.

**If the CLI rewrites `src/styles/index.css` or `theme.css` and changes any token value, restore both from git (`git checkout -- apps/web/src/styles/`) and re-run with the CLI's CSS overwrite disabled.** Template token values are non-negotiable.

- [ ] **Step 2: Add the components**

```bash
cd apps/web && npx shadcn@latest add --yes \
  button card table sheet dialog alert-dialog dropdown-menu input label \
  select tabs badge skeleton alert separator sidebar tooltip command \
  popover switch checkbox form sonner scroll-area collapsible avatar
```

- [ ] **Step 3: Verify the generated set compiles**

```bash
ls apps/web/src/components/ui/ && npm --prefix apps/web exec tsc -b
```

Expected: ~26 files listed; `tsc` exits 0. If `tsc` fails on a generated file, **do not edit it** — the failure means a peer dependency is missing. Install it and re-run.

- [ ] **Step 4: Commit**

```bash
git add apps/web/components.json apps/web/src/components/ui apps/web/package.json apps/web/package-lock.json
git commit -m "feat(web): generate the shadcn/ui component set

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 4: Enforce the design rules in lint

Adding these gates **before** any screen is written is the point. Retrofitting them after 22 screens means fixing hundreds of violations at once.

**Files:**
- Modify: `apps/web/.oxlintrc.json`
- Create: `scripts/check-design-rules.sh`

**Interfaces:**
- Produces: `scripts/check-design-rules.sh` — exits non-zero on a directional utility or a literal color outside `theme.css`.

- [ ] **Step 1: Write the failing check**

Create `scripts/check-design-rules.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
SRC=apps/web/src
fail=0

# Directional utilities break RTL. Logical properties only.
if rg -n --type-add 'tsx:*.{ts,tsx}' -t tsx \
   -e 'className="[^"]*\b(ml|mr|pl|pr|left|right|border-l|border-r|rounded-l|rounded-r)-' \
   -e 'className="[^"]*\btext-(left|right)\b' \
   "$SRC"; then
  echo "ERROR: directional utilities found. Use logical properties (ms/me/ps/pe/start/end/text-start/text-end)." >&2
  fail=1
fi

# Literal colors are permitted only in the theme file.
if rg -n --glob '!'"$SRC"'/styles/theme.css' \
   -e '#[0-9a-fA-F]{3,8}\b' -e 'rgba?\(' -e 'hsla?\(' -e 'oklch\(' \
   "$SRC"; then
  echo "ERROR: literal color outside src/styles/theme.css." >&2
  fail=1
fi

# Generated output is never hand-edited.
if rg -n 'eslint-disable|@ts-ignore|@ts-expect-error' "$SRC/components/ui" 2>/dev/null; then
  echo "ERROR: src/components/ui is generated and must not be hand-edited." >&2
  fail=1
fi

[ "$fail" -eq 0 ] && echo "Design rules OK."
exit "$fail"
```

```bash
chmod +x scripts/check-design-rules.sh
```

- [ ] **Step 2: Run it and confirm it currently fails**

```bash
./scripts/check-design-rules.sh
```

Expected: **FAIL**, listing literal colors in `src/styles/tokens.css` and directional CSS in the legacy stylesheets. This proves the check works. The failures disappear in Task 12 when those files are deleted.

- [ ] **Step 3: Scope the check to migrated code so it can pass today**

Add `--glob '!'"$SRC"'/styles/{tokens,base,shell,screens}.css'` and `--glob '!'"$SRC"'/ui/**'` to **both** `rg` invocations, with this comment above them:

```bash
# Legacy stylesheets and src/ui are exempt until Task 12 deletes them.
# Remove these two globs in Task 12 — the check must then pass unscoped.
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
./scripts/check-design-rules.sh
```

Expected: `Design rules OK.`

- [ ] **Step 5: Wire it into the Makefile**

Append to `Makefile`:

```make
verify-design:
	./scripts/check-design-rules.sh
	python3 scripts/verify-page-coverage.py
.PHONY: verify-design
```

`verify-page-coverage.py` arrives in Task 5; until then `make verify-design` fails on the missing file, which is expected and fixed by the next task.

- [ ] **Step 6: Commit**

```bash
git add scripts/check-design-rules.sh Makefile
git commit -m "build(web): gate directional utilities and literal colors

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 5: Build the page-coverage gate

This is the gate that prevents the backend/frontend gap from reopening. Every operation in `docs/api/endpoints-table.md` must be assigned to a page in `docs/design/PAGES.md` or explicitly declared out of UI scope.

**Files:**
- Create: `scripts/verify-page-coverage.py`

**Interfaces:**
- Consumes: `docs/api/endpoints-table.md` (generated), `docs/design/PAGES.md`.
- Produces: exit 0 when every operation is assigned; exit 1 with the unassigned list otherwise.

- [ ] **Step 1: Write the checker**

Create `scripts/verify-page-coverage.py`:

```python
#!/usr/bin/env python3
"""Fail when an API operation has no page assignment in docs/design/PAGES.md.

The endpoint table is generated from the OpenAPI contract and routes/web.php.
PAGES.md assigns every operation to exactly one page, or to the explicit
"outside the UI" page. Silence is a defect: an unassigned operation is how the
backend/frontend gap reopens.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TABLE = ROOT / "docs" / "api" / "endpoints-table.md"
PAGES = ROOT / "docs" / "design" / "PAGES.md"
PLANNED_MARKER = "## ملحق: مسارات مخططة"


def table_operations() -> set[tuple[str, str]]:
    text = TABLE.read_text(encoding="utf-8")
    live, planned = text.split(PLANNED_MARKER)
    ops: set[tuple[str, str]] = set()
    for line in live.splitlines():
        cells = [c.strip() for c in line.split("|")[1:-1]]
        if len(cells) >= 4 and cells[0].isdigit():
            ops.add((cells[2].strip("`"), cells[3].strip("`").replace("/api/v1/", "")))
    for line in planned.splitlines():
        cells = [c.strip() for c in line.split("|")[1:-1]]
        if len(cells) >= 2 and cells[0].startswith("`") and cells[0] != "`Method`":
            ops.add((cells[0].strip("`"), cells[1].strip("`").replace("/api/v1/", "")))
    return ops


def page_operations() -> set[tuple[str, str]]:
    text = PAGES.read_text(encoding="utf-8")
    ops: set[tuple[str, str]] = set()
    for line in text.splitlines():
        cells = [c.strip() for c in line.split("|")[1:-1]]
        if len(cells) == 4 and cells[0].startswith("`") and cells[0] != "`Method`":
            ops.add((cells[0].strip("`"), cells[1].strip("`")))
    return ops


def main() -> int:
    for path in (TABLE, PAGES):
        if not path.exists():
            print(f"ERROR: missing {path.relative_to(ROOT)}", file=sys.stderr)
            return 1

    declared = table_operations()
    assigned = page_operations()

    missing = sorted(declared - assigned)
    stale = sorted(assigned - declared)

    if missing:
        print(
            f"ERROR: {len(missing)} API operation(s) have no page in docs/design/PAGES.md:",
            file=sys.stderr,
        )
        for method, endpoint in missing:
            print(f"  {method} {endpoint}", file=sys.stderr)
        print(
            "\nAssign each to a page, or to the 'مسارات بلا واجهة' page if it is "
            "intentionally not exposed in the UI.",
            file=sys.stderr,
        )

    if stale:
        print(
            f"ERROR: {len(stale)} page assignment(s) reference operations that no "
            "longer exist in the contract:",
            file=sys.stderr,
        )
        for method, endpoint in stale:
            print(f"  {method} {endpoint}", file=sys.stderr)

    if missing or stale:
        return 1

    print(f"Page coverage OK: {len(declared)} operations, all assigned.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
```

- [ ] **Step 2: Run it and confirm it passes on the current docs**

```bash
python3 scripts/verify-page-coverage.py
```

Expected: `Page coverage OK: 205 operations, all assigned.`

If it reports a different total or any missing entries, **stop** — either `PAGES.md` or the endpoint table drifted, and the mismatch must be resolved before proceeding.

- [ ] **Step 3: Prove the gate actually catches a gap**

```bash
cp docs/design/PAGES.md /tmp/PAGES.bak
# Remove one assignment row and confirm the gate fires.
grep -v '`markNotificationRead`' /tmp/PAGES.bak > docs/design/PAGES.md
python3 scripts/verify-page-coverage.py; echo "exit=$?"
cp /tmp/PAGES.bak docs/design/PAGES.md
```

Expected: exit 1, naming `POST notifications/{notificationId}/read`. Then the restore puts the file back — confirm with `python3 scripts/verify-page-coverage.py` returning OK again.

- [ ] **Step 4: Commit**

```bash
git add scripts/verify-page-coverage.py
git commit -m "build: gate API operations without a page assignment

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 6: Theme provider and direction provider

**Files:**
- Create: `apps/web/src/components/theme-provider.tsx`
- Modify: `apps/web/src/main.tsx`, `apps/web/src/app/App.tsx`
- Test: `apps/web/src/components/theme-provider.test.tsx`

**Interfaces:**
- Produces: `<ThemeProvider>`, `useTheme(): { theme: 'light' | 'dark' | 'system'; setTheme(t): void; resolved: 'light' | 'dark' }`.

- [ ] **Step 1: Write the failing test**

Create `apps/web/src/components/theme-provider.test.tsx`:

```tsx
// @vitest-environment jsdom
import { describe, expect, it, beforeEach } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { ThemeProvider, useTheme } from './theme-provider'

function wrapper({ children }: { children: React.ReactNode }) {
  return <ThemeProvider>{children}</ThemeProvider>
}

describe('theme provider', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark')
  })

  it('defaults to system and applies no explicit class beyond the resolved one', () => {
    const { result } = renderHook(() => useTheme(), { wrapper })
    expect(result.current.theme).toBe('system')
  })

  it('applies the dark class and persists the choice', () => {
    const { result } = renderHook(() => useTheme(), { wrapper })
    act(() => result.current.setTheme('dark'))
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem('cluster.theme')).toBe('dark')
  })

  it('removes the dark class when switching back to light', () => {
    const { result } = renderHook(() => useTheme(), { wrapper })
    act(() => result.current.setTheme('dark'))
    act(() => result.current.setTheme('light'))
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })
})
```

- [ ] **Step 2: Run it and verify it fails**

```bash
npm --prefix apps/web run test:unit -- theme-provider
```

Expected: FAIL — `Failed to resolve import "./theme-provider"`.

- [ ] **Step 3: Implement it**

Create `apps/web/src/components/theme-provider.tsx`:

```tsx
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'

export type Theme = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'cluster.theme'

interface ThemeContextValue {
  theme: Theme
  resolved: 'light' | 'dark'
  setTheme: (theme: Theme) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

function systemPrefersDark(): boolean {
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}

function storedTheme(): Theme {
  const raw = localStorage.getItem(STORAGE_KEY)
  return raw === 'light' || raw === 'dark' || raw === 'system' ? raw : 'system'
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<Theme>(storedTheme)
  const [systemDark, setSystemDark] = useState(systemPrefersDark)

  useEffect(() => {
    const media = window.matchMedia?.('(prefers-color-scheme: dark)')
    if (!media) return
    const onChange = () => setSystemDark(media.matches)
    media.addEventListener('change', onChange)
    return () => media.removeEventListener('change', onChange)
  }, [])

  const resolved: 'light' | 'dark' = theme === 'system' ? (systemDark ? 'dark' : 'light') : theme

  useEffect(() => {
    document.documentElement.classList.toggle('dark', resolved === 'dark')
  }, [resolved])

  const setTheme = useCallback((next: Theme) => {
    localStorage.setItem(STORAGE_KEY, next)
    setThemeState(next)
  }, [])

  const value = useMemo(() => ({ theme, resolved, setTheme }), [theme, resolved, setTheme])
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used within ThemeProvider')
  return context
}
```

- [ ] **Step 4: Run the tests**

```bash
npm --prefix apps/web run test:unit -- theme-provider
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Mount both providers**

In `apps/web/src/main.tsx`, import `./styles/index.css`, `ThemeProvider`, `DirectionProvider` from `@radix-ui/react-direction`, and `Toaster` from `@/components/ui/sonner`. Wrap the tree:

```tsx
  <StrictMode>
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <App />
        <Toaster />
      </QueryClientProvider>
    </ThemeProvider>
  </StrictMode>,
```

`DirectionProvider` needs the active locale, so it belongs inside `App.tsx` where the locale lives, not in `main.tsx`. In `apps/web/src/app/App.tsx`, wrap the rendered router with:

```tsx
<DirectionProvider dir={locale === 'ar' ? 'rtl' : 'ltr'}>
  {/* existing tree */}
</DirectionProvider>
```

and ensure the existing effect that sets `document.documentElement.dir` and `lang` still runs.

- [ ] **Step 6: Verify and commit**

```bash
npm --prefix apps/web exec tsc -b
npm --prefix apps/web run test:unit
```

Expected: `tsc` exit 0; all unit tests pass.

```bash
git add apps/web/src/components/theme-provider.tsx apps/web/src/components/theme-provider.test.tsx \
        apps/web/src/main.tsx apps/web/src/app/App.tsx
git commit -m "feat(web): add theme and direction providers

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 7: The seven resource states as shared components

`DESIGN-RULES.md` §5 requires every data-fetching screen to render all seven states. Centralising them is what makes that enforceable rather than aspirational — and it is what guarantees 403 and 404 render the identical string.

**Files:**
- Create: `apps/web/src/components/states.tsx`
- Test: `apps/web/src/components/states.test.tsx`

**Interfaces:**
- Consumes: `ResourceState` and `stateFromError` from `@/api/http` (already exist).
- Produces: `<LoadingState rows={n} />`, `<EmptyState icon title body action />`, `<DeniedState />`, `<ConflictState onRetry />`, `<StaleState onRefresh />`, `<ErrorState onRetry correlationId />`, and `<ResourceBoundary state error onRetry>{children}</ResourceBoundary>`.

- [ ] **Step 1: Write the failing test**

Create `apps/web/src/components/states.test.tsx`:

```tsx
// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { ApiError } from '@/api/http'
import { DeniedState, ResourceBoundary } from './states'

describe('resource states', () => {
  it('renders the identical string for 403 and 404', () => {
    const forbidden = render(
      <ResourceBoundary state="forbidden" locale="ar">
        <div>content</div>
      </ResourceBoundary>,
    )
    const notFound = render(
      <ResourceBoundary state="not-found" locale="ar">
        <div>content</div>
      </ResourceBoundary>,
    )
    expect(forbidden.container.textContent).toBe(notFound.container.textContent)
  })

  it('does not leak the children when denied', () => {
    const { container } = render(
      <ResourceBoundary state="forbidden" locale="ar">
        <div>secret</div>
      </ResourceBoundary>,
    )
    expect(container.textContent).not.toContain('secret')
  })

  it('renders children when ready', () => {
    const { container } = render(
      <ResourceBoundary state="ready" locale="ar">
        <div>content</div>
      </ResourceBoundary>,
    )
    expect(container.textContent).toContain('content')
  })

  it('renders the denied copy for an ApiError of 403', () => {
    const { container } = render(<DeniedState locale="ar" />)
    expect(container.textContent).toBeTruthy()
    expect(new ApiError(403, { type: 'x', title: 'x', status: 403 }).status).toBe(403)
  })
})
```

- [ ] **Step 2: Run it and verify it fails**

```bash
npm --prefix apps/web run test:unit -- states
```

Expected: FAIL — `Failed to resolve import "./states"`.

- [ ] **Step 3: Implement**

Create `apps/web/src/components/states.tsx`. Requirements the implementation must satisfy, all verified by the test above:

- `DeniedState` and a `not-found` state resolve to the **same** copy constant. Define it once (`const DENIED = { ar: '...', en: '...' }`) and use it for both. Do not write two strings that happen to match.
- `ResourceBoundary` switches on `ResourceState` and renders children only for `'ready'`.
- `LoadingState` renders `<Skeleton>` rows, not a spinner.
- `ConflictState`, `StaleState`, and `ErrorState` each render an `<Alert>` plus a retry/refresh `<Button>`.
- `ErrorState` shows the correlation id in `font-mono` when one is supplied.
- All spacing uses logical utilities.
- No literal colors; use `text-muted-foreground`, `bg-card`, `text-destructive`.

- [ ] **Step 4: Run the tests**

```bash
npm --prefix apps/web run test:unit -- states
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Verify the design gate still passes**

```bash
./scripts/check-design-rules.sh
```

Expected: `Design rules OK.`

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/states.tsx apps/web/src/components/states.test.tsx
git commit -m "feat(web): add the seven resource states as shared components

403 and 404 share one copy constant so they cannot drift apart and leak
resource existence.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 8: Cursor-paginated data table

**Files:**
- Create: `apps/web/src/components/data-table.tsx`
- Test: `apps/web/src/components/data-table.test.tsx`

**Interfaces:**
- Produces: `<DataTable columns={ColumnDef<T>[]} data={T[]} state={ResourceState} nextCursor={string | null} onNext() onPrev() canPrev toolbar? onRowClick? />`

- [ ] **Step 1: Install TanStack Table**

```bash
npm --prefix apps/web install @tanstack/react-table
```

- [ ] **Step 2: Write the failing test**

Create `apps/web/src/components/data-table.test.tsx`:

```tsx
// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from './data-table'

interface Row { id: string; name: string }
const columns: ColumnDef<Row>[] = [{ accessorKey: 'name', header: 'Name' }]

describe('data table', () => {
  it('never renders page numbers or a total count', () => {
    const { container } = render(
      <DataTable columns={columns} data={[{ id: '1', name: 'a' }]} state="ready"
                 nextCursor={null} onNext={() => {}} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    expect(container.textContent).not.toMatch(/\d+\s*\/\s*\d+/)
    expect(container.textContent).not.toMatch(/of\s+\d+/i)
  })

  it('disables next when there is no cursor', () => {
    const onNext = vi.fn()
    const { getByRole } = render(
      <DataTable columns={columns} data={[{ id: '1', name: 'a' }]} state="ready"
                 nextCursor={null} onNext={onNext} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    const next = getByRole('button', { name: /التالي|next/i })
    expect(next).toBeDisabled()
    fireEvent.click(next)
    expect(onNext).not.toHaveBeenCalled()
  })

  it('advances when a cursor is present', () => {
    const onNext = vi.fn()
    const { getByRole } = render(
      <DataTable columns={columns} data={[{ id: '1', name: 'a' }]} state="ready"
                 nextCursor="abc" onNext={onNext} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    fireEvent.click(getByRole('button', { name: /التالي|next/i }))
    expect(onNext).toHaveBeenCalledOnce()
  })

  it('delegates non-ready states to the boundary and hides the table', () => {
    const { container } = render(
      <DataTable columns={columns} data={[]} state="forbidden"
                 nextCursor={null} onNext={() => {}} onPrev={() => {}} canPrev={false} locale="ar" />,
    )
    expect(container.querySelector('table')).toBeNull()
  })
})
```

- [ ] **Step 3: Run it and verify it fails**

```bash
npm --prefix apps/web run test:unit -- data-table
```

Expected: FAIL — module not found.

- [ ] **Step 4: Implement**

Create `apps/web/src/components/data-table.tsx` using `useReactTable` with `getCoreRowModel` only — **no** `getPaginationRowModel`, because pagination is server-side by cursor. Wrap the whole render in `<ResourceBoundary>` from Task 7. Footer holds exactly two buttons (previous / next); `next` is `disabled={!nextCursor}` and `previous` is `disabled={!canPrev}`. Render no counts.

- [ ] **Step 5: Run the tests**

```bash
npm --prefix apps/web run test:unit -- data-table
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/data-table.tsx apps/web/src/components/data-table.test.tsx \
        apps/web/package.json apps/web/package-lock.json
git commit -m "feat(web): add the cursor-paginated data table

The API exposes no page numbers or totals, so the table must not imply them.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 9: Rebuild the app shell on the shadcn sidebar

**Files:**
- Rewrite: `apps/web/src/app/AppShell.tsx`
- Test: `apps/web/src/app/AppShell.test.tsx`

**Interfaces:**
- Consumes: `usePrincipal()` from `@/app/principal-context` (`capabilities: string[] | null`, `features: { work_management: boolean; tasks: boolean } | null`, `effectiveScope`), `useTheme()` from Task 6.
- Produces: the seven primary destinations from `PAGES.md` §6, filtered by capability and feature flag.

- [ ] **Step 1: Write the failing test**

Create `apps/web/src/app/AppShell.test.tsx`. It must assert the rule that matters most:

```tsx
// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { AppShell } from './AppShell'
import { PrincipalContextTestProvider } from './principal-context'

function shell(capabilities: string[], features: { work_management: boolean; tasks: boolean }) {
  return render(
    <MemoryRouter>
      <PrincipalContextTestProvider capabilities={capabilities} features={features}>
        <AppShell onLogout={() => {}} />
      </PrincipalContextTestProvider>
    </MemoryRouter>,
  )
}

describe('app shell navigation', () => {
  it('omits work-management destinations entirely when the flag is off', () => {
    const { container } = shell(
      ['work_management.record.read', 'workflow.step.read'],
      { work_management: false, tasks: true },
    )
    // Absent, not disabled, and with no explanatory text — the API answers 404
    // without disclosing existence, and the UI must not undo that.
    expect(container.textContent).not.toContain('سجلات العمل')
    expect(container.textContent).not.toContain('صندوق الموافقات')
    expect(container.textContent).not.toContain('غير متاح')
    expect(container.querySelector('[aria-disabled="true"]')).toBeNull()
  })

  it('shows work-management destinations when the flag is on and capability is held', () => {
    const { container } = shell(
      ['work_management.record.read', 'workflow.step.read'],
      { work_management: true, tasks: true },
    )
    expect(container.textContent).toContain('سجلات العمل')
  })

  it('omits a destination when the capability is missing even with the flag on', () => {
    const { container } = shell([], { work_management: true, tasks: true })
    expect(container.textContent).not.toContain('سجلات العمل')
  })
})
```

This test needs a `PrincipalContextTestProvider`. Add it to `apps/web/src/app/principal-context.tsx` as a named export that accepts `capabilities` and `features` and supplies a complete `PrincipalSnapshot` with `state: 'ready'` and no-op `refresh`/`selectScope`.

- [ ] **Step 2: Run it and verify it fails**

```bash
npm --prefix apps/web run test:unit -- AppShell
```

Expected: FAIL — `PrincipalContextTestProvider` is not exported.

- [ ] **Step 3: Implement**

Rewrite `apps/web/src/app/AppShell.tsx` using `SidebarProvider`, `Sidebar`, `SidebarContent`, `SidebarGroup`, `SidebarMenu`, `SidebarMenuItem`, `SidebarMenuButton`, `SidebarInset`, and `SidebarTrigger` from `@/components/ui/sidebar`. The sidebar's `side` must follow direction (`side={locale === 'ar' ? 'right' : 'left'}`) — this is a Radix prop, not a CSS utility, so it is exempt from the directional-utility ban.

The seven primary destinations, in order, each gated as shown:

| Destination | Path | Condition |
|---|---|---|
| الرئيسية | `/` | always |
| المهام | `/tasks` | `features.tasks && has('tasks.list')` |
| المستندات | `/documents` | `has('documents.list')` |
| سجلات العمل | `/work-records` | `features.work_management && has('work_management.record.read')` |
| صندوق الموافقات | `/inbox` | `features.work_management && has('workflow.step.read')` |
| المنظمة | `/organization` | `has('organization.cluster.read') \|\| has('organization.facility.read')` |
| الحسابات والصلاحيات | `/access` | `has('identity.account.read') \|\| has('authorization.role.read')` |
| التقارير والمراقبة | `/reports` | `has('reporting.read') \|\| has('audit.event.read')` |
| إدارة المنصة | `/platform` | `has('platform_settings.read') \|\| has('platform_operations.health.read')` |

The header holds: `SidebarTrigger`, the `⌘K` search trigger (Task 10), the effective-scope badge, the theme toggle, the locale toggle, and the account menu.

Fix the two `exhaustive-deps` warnings from the baseline by memoising `capabilities` and `features` — the current code recreates the `features` object each render.

- [ ] **Step 4: Run the tests**

```bash
npm --prefix apps/web run test:unit -- AppShell
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Verify the shell renders in a browser**

```bash
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- shell.spec.ts
```

Expected: the shell journey passes. **If it fails on a selector**, the spec predates the rebuild — update the selector to a role/label query per `AGENTS.md`, and note the change in the commit message. Do not weaken an assertion to make it pass.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/app/AppShell.tsx apps/web/src/app/AppShell.test.tsx apps/web/src/app/principal-context.tsx
git commit -m "feat(web): rebuild the app shell on the shadcn sidebar

Feature-gated destinations are absent rather than disabled, matching the
non-disclosing 404 the API returns when work_management is off.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 10: Command palette

**Files:**
- Create: `apps/web/src/components/command-menu.tsx`
- Modify: `apps/web/src/app/AppShell.tsx`
- Test: `apps/web/src/components/command-menu.test.tsx`

**Interfaces:**
- Consumes: `search` from `@/api/generated/cluster` via a new `useSearch(query)` hook added to `@/api/hooks`.
- Produces: `<CommandMenu />` — opens on `⌘K` / `Ctrl+K`, closes on `Escape`.

- [ ] **Step 1: Add the search hook**

Append to `apps/web/src/api/hooks.ts`:

```ts
export function useSearch(query: string, enabled: boolean) {
  const { scopeEpoch } = useAuth()
  return useQuery<generated.CollectionResponse>({
    queryKey: ['search', query, scopeEpoch] as const,
    queryFn: async () => unwrap(await generated.search({ q: query, limit: 10 }, requestInit(null))),
    enabled: enabled && query.trim().length >= 2,
  })
}
```

- [ ] **Step 2: Write the failing test**

Create `apps/web/src/components/command-menu.test.tsx`:

```tsx
// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CommandMenu } from './command-menu'

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CommandMenu locale="ar" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('command menu', () => {
  it('is closed until the keyboard shortcut fires', () => {
    const { queryByRole } = mount()
    expect(queryByRole('dialog')).toBeNull()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    expect(queryByRole('dialog')).not.toBeNull()
  })

  it('closes on Escape', () => {
    const { queryByRole } = mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(queryByRole('dialog')).toBeNull()
  })
})
```

- [ ] **Step 3: Run it and verify it fails**

```bash
npm --prefix apps/web run test:unit -- command-menu
```

Expected: FAIL — module not found.

- [ ] **Step 4: Implement**

Create `apps/web/src/components/command-menu.tsx` on `CommandDialog` from `@/components/ui/command`. A `useEffect` registers a `keydown` listener for `(e.metaKey || e.ctrlKey) && e.key === 'k'`, calls `e.preventDefault()`, and toggles open. Results come from `useSearch` debounced by 250 ms, grouped by result type with a `lucide` icon per group, plus a static group of navigation destinations. Selecting a result navigates and closes.

- [ ] **Step 5: Run the tests and mount it**

```bash
npm --prefix apps/web run test:unit -- command-menu
```

Expected: PASS, 2 tests. Then render `<CommandMenu />` inside `AppShell`'s header and add a header button that opens it, labelled with the `⌘K` hint.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/command-menu.tsx apps/web/src/components/command-menu.test.tsx \
        apps/web/src/api/hooks.ts apps/web/src/app/AppShell.tsx
git commit -m "feat(web): add the command palette

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 11: Route the new information architecture

Routes must exist before screens migrate, so navigation and screens can move independently.

**Files:**
- Modify: `apps/web/src/router.tsx`
- Test: `apps/web/src/router.test.tsx`

**Interfaces:**
- Produces: the route tree from `PAGES.md`. Feature-gated and planned paths are **not** registered — they fall through to the 404 element.

- [ ] **Step 1: Write the failing test**

Create `apps/web/src/router.test.tsx`:

```tsx
// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { routePaths } from './router'

describe('route tree', () => {
  it('registers the consolidated workspaces', () => {
    for (const path of ['/', '/tasks', '/documents', '/organization', '/organization/import',
                        '/access', '/reports', '/platform', '/me', '/search', '/notifications']) {
      expect(routePaths()).toContain(path)
    }
  })

  it('does not register planned workspaces whose API is unimplemented', () => {
    for (const path of ['/governance', '/risk', '/strategy', '/portfolio', '/workflow/operations-office']) {
      expect(routePaths()).not.toContain(path)
    }
  })

  it('does not register the retired per-resource organization routes', () => {
    for (const path of ['/accounts-permissions', '/reports-monitoring', '/platform-management',
                        '/audit', '/dashboards', '/imports', '/me/security', '/me/access']) {
      expect(routePaths()).not.toContain(path)
    }
  })
})
```

- [ ] **Step 2: Run it and verify it fails**

```bash
npm --prefix apps/web run test:unit -- router
```

Expected: FAIL — `routePaths` is not exported.

- [ ] **Step 3: Implement**

In `apps/web/src/router.tsx`, extract the children array into a module-level `const ROUTES` and export `export function routePaths(): string[] { return ROUTES.map(r => r.path) }`. Register:

`/` · `/tasks` · `/tasks/new` · `/tasks/:taskId` · `/documents` · `/documents/:documentId` · `/organization` · `/organization/import` · `/organization/import/:jobId` · `/access` · `/reports` · `/platform` · `/notifications` · `/search` · `/me` · `/login` · `*`

Register `/work-records`, `/work-records/:recordId`, `/inbox`, `/workflow`, and `/work-definitions` **only inside a conditional guarded by `features.work_management`**, so an unauthorised deep link hits the 404 element and discloses nothing. Because `features` comes from `/me`, build the router after the principal resolves — or register them always and have each screen return the 404 element when the flag is off. **Prefer the first**: an unregistered route cannot leak a loading skeleton.

Redirect the retired paths (`/accounts-permissions` → `/access`, `/reports-monitoring` → `/reports`, `/platform-management` → `/platform`, `/audit` → `/reports`, `/dashboards` → `/reports`, `/imports` → `/organization/import`, `/me/security` and `/me/access` → `/me`) with `<Navigate replace />` so existing bookmarks and the 53 e2e specs keep working.

- [ ] **Step 4: Run the tests**

```bash
npm --prefix apps/web run test:unit -- router
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Run the full e2e suite and record the damage**

```bash
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local 2>&1 | tail -40
```

Append the result to `docs/design/plans/baseline.md` under `## e2e after routing`. Fix any spec that broke **only** because a path moved — the redirects should cover most. Any spec that fails for a behavioural reason is a real regression: stop and report it rather than editing the assertion.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/router.tsx apps/web/src/router.test.tsx docs/design/plans/baseline.md
git commit -m "feat(web): route the consolidated information architecture

Retired paths redirect so bookmarks and existing journeys keep working.
Feature-gated paths are registered only when the flag is on.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 12: Retire the legacy design system

**Only run this task when no file outside `src/styles` and `src/ui` imports them.** Verify first; the deletion is atomic with the last importer's removal.

**Files:**
- Delete: `apps/web/src/styles/{tokens,base,shell,screens}.css`, `apps/web/src/ui/index.tsx`
- Modify: `apps/web/src/main.tsx`, `scripts/check-design-rules.sh`

- [ ] **Step 1: Prove nothing imports them**

```bash
rg -n "styles/(tokens|base|shell|screens)\.css|from '.*\/ui'" apps/web/src --glob '!apps/web/src/ui/**'
```

Expected: **no output**. If anything is listed, those screens have not been migrated yet — **stop**, finish the screen-migration plan, and return here.

- [ ] **Step 2: Delete**

```bash
git rm apps/web/src/styles/tokens.css apps/web/src/styles/base.css \
       apps/web/src/styles/shell.css apps/web/src/styles/screens.css \
       apps/web/src/ui/index.tsx
```

Remove the four corresponding `import` lines from `apps/web/src/main.tsx`.

- [ ] **Step 3: Un-scope the design gate**

In `scripts/check-design-rules.sh`, delete the two exemption globs added in Task 4 Step 3, along with the comment that says to remove them.

- [ ] **Step 4: Verify everything**

```bash
npm --prefix apps/web exec tsc -b \
 && npm --prefix apps/web run lint \
 && npm --prefix apps/web run test:unit \
 && npm --prefix apps/web run build \
 && ./scripts/check-design-rules.sh \
 && python3 scripts/verify-page-coverage.py
```

Expected: every command exits 0, and `check-design-rules.sh` now prints `Design rules OK.` **without** exemptions.

- [ ] **Step 5: Commit**

```bash
git add -A apps/web/src apps/web/src/main.tsx scripts/check-design-rules.sh
git commit -m "refactor(web): delete the hand-written design system

Replaced by the shadcn-admin theme. The design gate now runs unscoped.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 13: Wire the gates into CI

**Files:**
- Modify: `.github/workflows/ci.yml`, `Makefile`

- [ ] **Step 1: Confirm the gates pass locally**

```bash
make verify-design
```

Expected: `Design rules OK.` then `Page coverage OK: 205 operations, all assigned.`

- [ ] **Step 2: Add the job step**

In `.github/workflows/ci.yml`, in the job that already runs web lint and build, add after the lint step:

```yaml
      - name: Verify design rules and page coverage
        run: make verify-design
```

- [ ] **Step 3: Verify the workflow parses**

```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('workflow OK')"
```

Expected: `workflow OK`.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml Makefile
git commit -m "ci: enforce design rules and page coverage

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Follow-up plan (not this document)

Screen-by-screen migration onto the new primitives is a separate plan, one task per workspace, each ending with its own e2e run:

`/tasks` · `/documents` · `/organization` (9 tabs + import wizard) · `/access` (4 tabs) · `/reports` (4 tabs) · `/platform` (9 tabs) · `/me` · `/login` · `/` dashboard · then the feature-gated `/work-records`, `/inbox`, `/workflow`, `/work-definitions`.

Each of those tasks reads its target section of `docs/design/PAGES.md` for the required output and endpoints, and `docs/design/DESIGN-RULES.md` for how to build it.

The four planned workspaces (`/governance`, `/risk`, `/strategy`, `/portfolio` — 38 operations) are **not** built until their API ships.
