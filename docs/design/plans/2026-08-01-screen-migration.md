# Screen Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Prerequisite:** [Unified Theme Foundation](2026-08-01-unified-theme-foundation.md) Tasks 1–11 must be complete and committed. This plan consumes the primitives they produce. Task 12 of that plan (deleting the legacy CSS) runs **after** this plan finishes — it is gated on no file importing `src/ui`.

**Goal:** Move all 22 existing screens onto the shadcn primitives and the consolidated information architecture, then build the four feature-gated workspaces that have a working API but no UI.

**Architecture:** One task per workspace. Each task migrates a whole destination — shell, tabs, and screens — so the app is coherent after every commit rather than half-converted. Two screens are oversized and get split as part of their migration: `OrganizationScreen.tsx` (2509 lines → 9 tab files) and `AccountsPermissionsScreen.tsx` (1447 lines → 4 tab files). API wiring, hooks, and transport are **not** touched; this is a presentation migration.

**Tech Stack:** shadcn/ui · Tailwind v4 · `@tanstack/react-table` · `react-hook-form` + `zod` · `sonner` · `lucide-react` · `recharts`

## Global Constraints

- **Binding:** `docs/design/DESIGN-RULES.md`. Every task inherits all of it.
- **Per-screen source of truth:** the matching section of `docs/design/PAGES.md`. Read the target section's **المخرج** (output) and **الإجراءات** (actions) rows before writing code. They are the acceptance criteria.
- **Zero literal colors.** No `#hex`/`rgb()`/`hsl()`/`oklch()` outside `src/styles/theme.css`.
- **Logical properties only.** `ms-/me-/ps-/pe-/start-/end-/text-start/text-end/border-s/border-e`. Never `ml-/mr-/pl-/pr-/left-/right-/text-left/text-right`.
- **403 and 404 render the identical string** — use `ResourceBoundary` from `@/components/states`; never write the copy twice.
- **All seven resource states** on every data-fetching screen, via `ResourceBoundary`.
- **Cursor pagination only.** No page numbers, no totals.
- **No raw HTTP headers in screens.** `Idempotency-Key`/`If-Match`/`X-CSRF-Token` come from `requestInit` via `src/api/hooks.ts`.
- **Never hand-edit** `src/api/generated/` or `src/components/ui/`.
- **Destructive actions require `AlertDialog`.** Irreversible ones (restore, disposition, signing) require typing a confirmation value, not just a click.
- **No file over ~400 lines.** If a migrated screen exceeds it, split by tab or by section before committing.

### The migration recipe

Every task in this plan follows the same nine steps. This is the recipe; the tasks below specify only what differs.

1. Read the target section of `docs/design/PAGES.md`.
2. Write or update the screen's test file first, asserting the states and the capability gating.
3. Run it; confirm it fails.
4. Replace `@/ui` imports with `@/components/ui/*` and `@/components/{states,data-table}`.
5. Replace every bespoke `className` with token utilities; delete the screen's CSS dependencies.
6. Wrap fetches in `ResourceBoundary`; use `DataTable` for every list.
7. Run the screen's unit tests, `npx tsc -b`, and `./scripts/check-design-rules.sh`.
8. Run `make verify-e2e-drift` (Task 0). The criterion is **no drift**, not "all green": nine specs are frozen red from before this programme. A newly failing spec is a regression — stop and report it rather than weakening the assertion. A newly passing spec that is still in `known-red.json` means you fixed it — remove its entry in this same commit.
9. Commit.

- Commands run from the repo root. Type check is `cd apps/web && npx tsc -b` (`npm exec` swallows `-b`).
- Do not run `git push`.

---

## File Structure

| Path | Change |
|---|---|
| `apps/web/src/app/LoginScreen.tsx` | Migrate (83 lines) |
| `apps/web/src/features/dashboard/HomeDashboard.tsx` | Migrate (309) |
| `apps/web/src/features/tasks/*.tsx` | Migrate (304 / 953 / 243) |
| `apps/web/src/features/documents/*.tsx` | Migrate (362 / 767) |
| `apps/web/src/features/organization/OrganizationScreen.tsx` | **Split** 2509 → shell + 9 tabs |
| `apps/web/src/features/organization/tabs/*.tsx` | Create — 9 files |
| `apps/web/src/features/imports/ImportReviewScreen.tsx` | Rebuild as a 4-step wizard (845) |
| `apps/web/src/features/accounts/AccountsPermissionsScreen.tsx` | **Split** 1447 → shell + 4 tabs |
| `apps/web/src/features/accounts/tabs/*.tsx` | Create — 4 files |
| `apps/web/src/features/reports/*.tsx` + `audit/AuditScreen.tsx` | Migrate into `/reports` (82 / 437 / 243 / 728) |
| `apps/web/src/features/platform/**` | Migrate shell + 7 sections, add 2 |
| `apps/web/src/features/identity/*.tsx` | Merge into one `/me` workspace (160 + 311) |
| `apps/web/src/features/notifications/NotificationsScreen.tsx` | Migrate (200) |
| `apps/web/src/features/search/SearchScreen.tsx` | Migrate (255) |
| `apps/web/src/features/api-docs/ApiDocsScreen.tsx` | **Delete** — see Task 1 |
| `apps/web/src/features/work-records/`, `inbox/`, `workflow/`, `work-definitions/` | Create — Tasks 12–13 |

---

## Task 0: Freeze the known-red e2e list

The foundation plan ended with **9 of 15 e2e specs failing**, and that was verified against commit `17a84ac` through a worktree: identical counts, so none of it is a regression from the theme work. Those failures are older than this programme.

That fact makes every "run the e2e spec and confirm it passes" step in the tasks below unusable as written. With nine specs already red, an implementer either halts at every task or — worse — learns to wave failures away as pre-existing and lets a real regression through. A gate that cannot distinguish known red from new red is not a gate.

This task converts the acceptance criterion from *passes* to *does not differ from the frozen list*.

**Files:**
- Create: `apps/web/e2e/known-red.json`
- Create: `scripts/check-e2e-drift.py`
- Modify: `Makefile`

**Interfaces:**
- Produces: `scripts/check-e2e-drift.py <results.json>` — exit 0 when the failing set equals the frozen set; exit 1 naming any spec that newly failed **or** newly passed.

- [ ] **Step 1: Capture the current failing set**

```bash
cd /Users/tariq/code/R3/cluster
bash infra/dev/run-w1-1-e2e.sh 2>&1 | tee /tmp/e2e-baseline.txt | tail -20
```

Expected: 9 failed · 5 passed · 1 skipped. **If the counts differ from that, stop and report** — the environment is not the one this list was frozen against.

- [ ] **Step 2: Write the frozen list**

Create `apps/web/e2e/known-red.json` with an entry per failing spec: its `file`, its `title`, and a one-line `reason`. Take the reasons from `docs/design/plans/baseline.md` §"e2e بعد إعادة التوجيه" — for example the W1.1 seed granting no `platform_settings.calendar.manage` and seeding no calendar.

Every entry also carries `"owner"`: either `"seed"` (the W1.1 fixture lacks data or capabilities) or `"screen"` (the screen genuinely misbehaves). This matters because the `"screen"` entries are expected to turn green during migration, and the `"seed"` ones are not.

- [ ] **Step 3: Write the drift checker**

`scripts/check-e2e-drift.py` reads a Playwright JSON report and `known-red.json`, then:

- fails naming any spec that failed but is **not** in the frozen list — a real regression;
- fails naming any spec that passed but **is** in the frozen list — the list is stale and must be updated in the same commit that fixed it.

Both directions matter. A frozen list that is never pruned rots into permission to fail.

- [ ] **Step 4: Prove the checker fires in both directions**

```bash
python3 scripts/check-e2e-drift.py /tmp/e2e-results.json; echo "exit=$?"
```

Expected: exit 0 against the frozen state. Then temporarily delete one entry from `known-red.json` and re-run: expect exit 1 naming that spec as a regression. Restore the entry and confirm exit 0 again.

- [ ] **Step 5: Wire it in**

Add to `Makefile`:

```make
verify-e2e-drift:
	bash infra/dev/run-w1-1-e2e.sh --reporter=json > /tmp/e2e-results.json || true
	python3 scripts/check-e2e-drift.py /tmp/e2e-results.json
.PHONY: verify-e2e-drift
```

- [ ] **Step 6: Commit**

```bash
git add apps/web/e2e/known-red.json scripts/check-e2e-drift.py Makefile
git commit -m "test(web): freeze the known-red e2e list and gate on drift

Nine specs were already failing at 17a84ac, verified through a worktree, so
'run the spec and confirm it passes' cannot be an acceptance criterion. The
gate now fails on any spec that newly fails or newly passes, so a regression
is visible and a fix cannot silently rot the list.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

**From here on, every task's e2e step means `make verify-e2e-drift`, not "confirm it passes".** When a task migrates a screen whose spec is frozen with `"owner": "screen"`, re-run that spec and, if it now passes, remove its entry from `known-red.json` in the same commit.

---

## Task 1: Dependency hygiene and the api-docs decision

Three dependencies are declared and imported nowhere. `swagger-ui-react` alone is over a megabyte. They ship in the production bundle for no benefit.

**Files:**
- Modify: `apps/web/package.json`
- Delete: `apps/web/src/features/api-docs/ApiDocsScreen.tsx`
- Modify: `apps/web/src/router.tsx`

**Interfaces:**
- Produces: `recharts` available for the dashboard tasks.

- [ ] **Step 1: Prove the three dependencies are unused**

```bash
cd apps/web
for d in swagger-ui-react js-yaml echarts; do
  echo "$d -> $(grep -rl "$d" src 2>/dev/null | wc -l | tr -d ' ') files"
done
```

Expected: `0 files` for all three. **If any is non-zero, stop** — do not remove a dependency that is in use.

- [ ] **Step 2: Remove them and add the charting library the plan actually uses**

```bash
npm --prefix apps/web uninstall swagger-ui-react js-yaml echarts
npm --prefix apps/web install recharts
```

- [ ] **Step 3: Delete the api-docs screen**

`ApiDocsScreen.tsx` renders the OpenAPI spec inside the product. It is a developer utility, it is assigned to no page in `docs/design/PAGES.md`, it consumes no API operation the coverage gate tracks, and the same reference is already produced by `npm run api:docs` into `.orval/api-reference.html`. Delete it rather than migrate it.

```bash
git rm apps/web/src/features/api-docs/ApiDocsScreen.tsx
```

Remove its import and its `/api-docs` route from `apps/web/src/router.tsx`. Add a redirect so an existing bookmark does not 404 into a dead end:

```tsx
{ path: '/api-docs', element: <Navigate to="/" replace /> },
```

- [ ] **Step 4: Verify the bundle shrank**

```bash
npm --prefix apps/web run build 2>&1 | tail -8
```

Expected: build succeeds, and the reported JS total is smaller than the figure recorded in `docs/design/plans/baseline.md`. Record the new figure there under `## bundle`.

- [ ] **Step 5: Verify the gates**

```bash
cd apps/web && npx tsc -b && npm run test:unit 2>&1 | tail -4
cd /Users/tariq/code/R3/cluster && ./scripts/check-design-rules.sh && python3 scripts/verify-page-coverage.py
```

Expected: all pass. Page coverage stays at `205 operations, all assigned` — removing a screen that consumed no tracked operation cannot change it.

- [ ] **Step 6: Commit**

```bash
git add apps/web/package.json apps/web/package-lock.json apps/web/src/router.tsx \
        docs/design/plans/baseline.md
git add -A apps/web/src/features/api-docs 2>/dev/null || true
git commit -m "chore(web): drop unused heavy dependencies and the in-product API browser

swagger-ui-react, js-yaml and echarts were declared but imported nowhere.
ApiDocsScreen duplicated npm run api:docs and consumed no tracked operation.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 2: Login and session

Smallest screen, and the one every e2e spec depends on. Migrating it first proves the primitives work end to end before anything large is touched.

**Files:**
- Modify: `apps/web/src/app/LoginScreen.tsx`
- Test: `apps/web/src/app/LoginScreen.test.tsx`

**PAGES.md section:** الدخول والجلسة

- [ ] **Step 1: Write the failing test**

```tsx
// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { LoginScreen } from './LoginScreen'

describe('login screen', () => {
  it('labels both credential fields in Arabic', () => {
    const { getByLabelText } = render(
      <LoginScreen locale="ar" onSuccess={() => {}} setLocale={() => {}} />,
    )
    expect(getByLabelText('اسم المستخدم')).toBeTruthy()
    expect(getByLabelText('كلمة المرور')).toBeTruthy()
  })

  it('offers a language switch', () => {
    const { getByRole } = render(
      <LoginScreen locale="ar" onSuccess={() => {}} setLocale={() => {}} />,
    )
    expect(getByRole('button', { name: /English/i })).toBeTruthy()
  })

  it('uses no directional utility classes', () => {
    const { container } = render(
      <LoginScreen locale="ar" onSuccess={() => {}} setLocale={() => {}} />,
    )
    expect(container.innerHTML).not.toMatch(/\b(ml|mr|pl|pr)-\d/)
    expect(container.innerHTML).not.toMatch(/\btext-(left|right)\b/)
  })
})
```

Adjust the props to the component's real signature if it differs; keep the three assertions.

- [ ] **Step 2: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- LoginScreen
```

- [ ] **Step 3: Migrate**

Follow the recipe. Structure: centred `Card` on `bg-background`, `Input` + `Label` per field, `Button` submit, a quiet `Button` for the language switch. Field errors render under the field via `Form`'s message slot, not as a page-level alert.

- [ ] **Step 4: Run the tests**

```bash
npm --prefix apps/web run test:unit -- LoginScreen
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Run the login journeys**

```bash
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- login.spec.ts
```

Expected: both specs pass — they query by label and role, so a faithful migration keeps them green. **A failure here means the accessible names changed**; restore them rather than editing the spec.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/app/LoginScreen.tsx apps/web/src/app/LoginScreen.test.tsx
git commit -m "refactor(web): migrate the login screen to shadcn primitives

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 3: Home dashboard

**Files:** Modify `apps/web/src/features/dashboard/HomeDashboard.tsx` · Test `HomeDashboard.test.tsx`

**PAGES.md section:** الرئيسية

- [ ] **Step 1: Write the failing test asserting card independence**

The rule that matters here: one card failing must not take down the dashboard.

```tsx
it('renders the remaining cards when one query fails', () => {
  // Mount with the tasks query rejecting and the notifications query resolving.
  // Assert the notifications card content is present and an error alert appears
  // only inside the tasks card.
})
```

Write it concretely against the component's actual hooks, mocking `@/api/hooks` with `vi.mock`.

- [ ] **Step 2: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- HomeDashboard
```

- [ ] **Step 3: Migrate**

Responsive `Card` grid: `grid gap-4 sm:grid-cols-2 lg:grid-cols-3`. Each card wraps its own `ResourceBoundary`. No write actions. Cards navigate to their full workspace.

- [ ] **Steps 4–6: Test, e2e, commit**

```bash
npm --prefix apps/web run test:unit -- HomeDashboard
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- dashboard-navigation-browser-qa.spec.ts
git add apps/web/src/features/dashboard/
git commit -m "refactor(web): migrate the home dashboard to shadcn cards

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 4: Tasks workspace

**Files:** Modify `TasksScreen.tsx` (304), `TaskDetailScreen.tsx` (953), `TaskCreateScreen.tsx` (243) · Test each

**PAGES.md section:** المهام

`TaskDetailScreen.tsx` is 953 lines. Split it into `TaskDetailScreen.tsx` (shell + header + actions) and `tabs/{TaskDetailsTab,TaskCommentsTab,TaskParticipantsTab}.tsx` as part of the migration.

- [ ] **Step 1: Write the failing test for allowed actions**

The rule that matters: transitions come from the server, never from client-side inference.

```tsx
it('renders only the transitions the server allows', () => {
  // Mount the detail screen with a task whose allowed_actions is ['start'].
  // Assert a 'start' control exists and no control for 'complete' or 'cancel'.
})
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- TaskDetail
```

- [ ] **Step 3: Migrate the list**

`DataTable` with columns title / state `Badge` / priority / assignee / due. Toolbar: text search, state filter, priority filter. Primary `Button` "أنشئ مهمة" navigates to `/tasks/new`.

- [ ] **Step 4: Migrate the detail and split it**

Full page. Header carries title, state `Badge`, and one `Button` per entry in `allowed_actions`. Body is `Tabs`: التفاصيل · التعليقات · المشاركون, each in its own file under `tabs/`. Transition dialogs collect the reason in a `Dialog`; the mutation passes `lockVersion` for `If-Match`.

- [ ] **Step 5: Migrate the create form**

`react-hook-form` + `zod`, `Form` components, submit disabled while pending.

- [ ] **Step 6: Verify**

```bash
npm --prefix apps/web run test:unit -- Task
cd apps/web && npx tsc -b && cd .. && ./scripts/check-design-rules.sh
wc -l apps/web/src/features/tasks/*.tsx apps/web/src/features/tasks/tabs/*.tsx
```

Expected: tests pass, and no file over ~400 lines.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/tasks/
git commit -m "refactor(web): migrate the tasks workspace and split the detail screen

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 5: Documents workspace

**Files:** Modify `DocumentsScreen.tsx` (362), `DocumentDetailScreen.tsx` (767) → split into shell + `tabs/{Preview,Versions,Links,Access}Tab.tsx`

**PAGES.md section:** المستندات (14 operations)

- [ ] **Step 1: Write the failing test for the upload flow**

```tsx
it('presents initiate, upload and complete as one progress affordance', () => {
  // Mount the upload sheet. Assert exactly one progress element is rendered,
  // and that no step navigation ("next"/"back") controls are present.
})
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- Document
```

- [ ] **Step 3: Migrate the list**

`DataTable` columns title / type / state / updated. "أنشئ مستند" opens a `Sheet`.

- [ ] **Step 4: Migrate the detail and split it**

Full page, header with state and transitions, `Tabs`: المعاينة · النسخ · الروابط · الوصول. Malware-scan state renders as a `Badge` with a `lucide` icon, never a bespoke colour.

- [ ] **Step 5: Verify and commit**

```bash
npm --prefix apps/web run test:unit -- Document
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- documents.spec.ts
git add apps/web/src/features/documents/
git commit -m "refactor(web): migrate the documents workspace and split the detail screen

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 6: Organization workspace — split and migrate

The largest single change: 2509 lines covering nine resources become a shell plus nine focused tab files.

**Files:**
- Modify: `apps/web/src/features/organization/OrganizationScreen.tsx` → shell only
- Create: `apps/web/src/features/organization/tabs/{StructureTab,FacilitiesTab,PositionsTab,JobTitlesTab,PeopleTab,AssignmentsTab,TemporaryAssignmentsTab,SupervisoryTab,ClusterTab}.tsx`
- Test: `apps/web/src/features/organization/OrganizationScreen.test.tsx`

**PAGES.md sections:** الهيكل التنظيمي · المنشآت · الوظائف · المسميات الوظيفية · الموظفون · التكليفات · التكليفات المؤقتة · العلاقات الإشرافية · إعداد المجمّع (37 operations)

- [ ] **Step 1: Write the failing test for tab gating**

```tsx
it('omits tabs the principal cannot read', () => {
  // Mount with capabilities ['organization.facility.read'] only.
  // Assert a facilities tab exists and that no people, positions,
  // or cluster tab is rendered.
})

it('renders the cluster tab empty state rather than an error when no cluster exists', () => {
  // Mount with the cluster query resolving to null (the 404 path).
  // Assert a create affordance appears and no error alert is rendered.
})
```

The second assertion matters: 404 on the cluster is the expected setup path, not a failure — `useCluster` already special-cases it.

- [ ] **Step 2: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- Organization
```

- [ ] **Step 3: Extract the nine tabs**

Move each resource's existing logic verbatim into its own file first, **without restyling**. Commit nothing yet; just confirm `npx tsc -b` passes and the screen still renders. Splitting and restyling in one motion makes a regression impossible to bisect.

- [ ] **Step 4: Verify the split alone is behaviour-preserving**

```bash
cd apps/web && npx tsc -b && npm run test:unit
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- org-hierarchy-tree.spec.ts
```

Expected: unchanged results. Commit this step on its own:

```bash
git add apps/web/src/features/organization/
git commit -m "refactor(web): split OrganizationScreen into nine tab modules

Pure extraction, no behaviour or styling change, so the restyle that
follows can be bisected independently.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Migrate the shell and each tab**

Shell: `Page` + `Tabs` filtered by capability. Then per `PAGES.md`:

- **الهيكل** — `Collapsible` tree, not a table. Reordering is an explicit mode with a save action carrying `If-Match`; no implicit save on drop.
- **المنشآت / الوظائف / المسميات / الموظفون / التكليفات / المؤقتة / الإشرافية** — `DataTable` + `Sheet` editors.
- **المؤقتة** — the expiry column shows remaining duration as text ("٣ أيام"), not just a date.
- **المجمّع** — single form; 404 renders a create empty state.

- [ ] **Step 6: Verify and commit**

```bash
npm --prefix apps/web run test:unit -- Organization
cd apps/web && npx tsc -b && cd .. && ./scripts/check-design-rules.sh
wc -l apps/web/src/features/organization/OrganizationScreen.tsx apps/web/src/features/organization/tabs/*.tsx
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- org-hierarchy-tree.spec.ts walking-skeleton.spec.ts
```

Expected: no file over ~400 lines; both specs pass.

```bash
git add apps/web/src/features/organization/
git commit -m "refactor(web): migrate the organization workspace to shadcn primitives

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 7: Employee import wizard

**Files:** Rebuild `apps/web/src/features/imports/ImportReviewScreen.tsx` (845) as `ImportWizard.tsx` + `steps/{UploadStep,ValidateStep,ReviewStep,CommitStep}.tsx`

**PAGES.md section:** استيراد الموظفين (5 operations)

- [ ] **Step 1: Write the failing test for the review default filter**

```tsx
it('filters to blocking rows by default on the review step', () => {
  // Mount the review step with 3 clean rows and 1 row carrying a blocking error.
  // Assert one row is rendered and a control exists to show all rows.
})
```

The user wants the problems first; showing 400 clean rows buries the four that need a decision.

- [ ] **Step 2: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- Import
```

- [ ] **Step 3: Build the four steps**

Numbered stepper with a progress indicator. Upload: drop zone plus a downloadable CSV template. Validate: `Skeleton`, not a spinner. Review: `DataTable` of rows with per-row accept/ignore. Commit: count summary plus an `AlertDialog`.

The wizard lives at `/organization/import`, **not** as a tenth organization tab — it is an operation, not a destination.

- [ ] **Step 4: Verify and commit**

```bash
npm --prefix apps/web run test:unit -- Import
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- w1-2-cookie-csrf.spec.ts
git add apps/web/src/features/imports/
git commit -m "refactor(web): rebuild employee import as a four-step wizard

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 8: Access workspace — split and migrate

**Files:**
- Modify: `AccountsPermissionsScreen.tsx` (1447) → shell only, renamed to `AccessScreen.tsx`
- Create: `apps/web/src/features/accounts/tabs/{AccountsTab,RolesTab,DiagnosticsTab,BootstrapTab}.tsx`

**PAGES.md sections:** الحسابات · الأدوار والقدرات · تشخيص الوصول · التهيئة الأولية (17 operations)

- [x] **Step 1: Write the failing tests for the two rules that matter**

```tsx
it('confirms controlled activation delivery and expiry without ever exposing the secret', () => {
  // Issue an activation. The mocked response deliberately carries an
  // unexpected `token` property. Assert the dialog shows controlled delivery
  // and expiry, and that the sentinel secret appears nowhere in the rendered
  // table or dialog.
})

it('searches assignment scopes on demand rather than preloading them', () => {
  // Mount the roles tab. Assert the scope query is not fired on mount, and is
  // fired after typing two characters into the scope combobox.
})
```

- [x] **Step 2: Run them and confirm they fail**

```bash
npm --prefix apps/web run test:unit -- Access
```

- [x] **Step 3: Extract the tabs verbatim, commit the pure split**

The pure split already produced three extracted legacy tabs (`AccountsTab`, `RolesTab`, `InspectorTab`); the migration later adds the fourth `BootstrapTab`. Extraction discipline is the same as Task 6 Step 3 — extraction with no restyle, verified, committed alone.

```bash
cd apps/web && npx tsc -b && npm run test:unit
git add apps/web/src/features/accounts/
git commit -m "refactor(web): split the access workspace into three tab modules

Pure extraction, no behaviour change.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [x] **Step 4: Migrate**

- **الحسابات** — `DataTable`; activation secret is delivered through the controlled approved channel and is never exposed by this administrative UI. A `Dialog` confirms issuance and expiry only (no copy button, no "shown once" warning); status changes behind `AlertDialog`.
- **الأدوار** — one `DataTable` with a resource-type switcher (roles / capabilities / assignments) in the toolbar, not three tabs. Scope target selection is a `Combobox` querying `assignment-scope-targets` incrementally. Sensitive capabilities carry a `ShieldAlert` icon, not a colour.
- **التشخيص** — decision form rendering the justification chain as a timeline. This screen may use technical terminology; state that on the page, as `DESIGN-RULES.md` §2.5 otherwise forbids it.
- **التهيئة** — visible only while the bootstrap status permits it; disappears once complete.

- [x] **Step 5: Verify and commit**

```bash
npm --prefix apps/web run test:unit -- Access
cd apps/web && npx tsc -b && cd .. && ./scripts/check-design-rules.sh
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- accounts-permissions.spec.ts w1-3-authorization.spec.ts
git add apps/web/src/features/accounts/ apps/web/src/router.tsx
git commit -m "refactor(web): migrate the access workspace to shadcn primitives

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 9: Reports and monitoring workspace

**Files:** Modify `ReportsMonitoringScreen.tsx` (82), `ReportsScreen.tsx` (437), `DashboardsScreen.tsx` (243), `audit/AuditScreen.tsx` (728) · Create an exports tab

**PAGES.md sections:** التقارير · لوحات المعلومات · سجل التدقيق · التصديرات (12 operations)

- [ ] **Step 1: Write the failing tests**

```tsx
it('does not block the UI while an export is being prepared', () => {
  // Trigger an export (202). Assert the trigger control is re-enabled and no
  // blocking overlay is rendered.
})

it('stops polling an export once it reaches a terminal state', () => {
  // Advance timers with the export resolving to 'ready'.
  // Assert no further fetch occurs.
})
```

- [ ] **Step 2: Run them and confirm they fail**

```bash
npm --prefix apps/web run test:unit -- Reports
```

- [ ] **Step 3: Migrate**

- **التقارير** — report list beside the selected report. Export opens a format `Dialog`, then a `sonner` toast; the result is tracked in the exports tab.
- **اللوحات** — `Card` grid; `recharts` series coloured **only** from `chart-1`…`chart-5`.
- **التدقيق** — `DataTable` with date-range, actor-type and outcome filters; cursor pagination is mandatory; event detail in a `Sheet`; integrity verification renders its result as an `Alert`, not a persistent badge.
- **التصديرات** — status list; `refetchInterval` stops at a terminal state.

Add the exports tab to the workspace's `Tabs` and to the capability filter.

- [ ] **Step 4: Verify and commit**

```bash
npm --prefix apps/web run test:unit -- Reports
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- audit-workspace.spec.ts
git add apps/web/src/features/reports/ apps/web/src/features/audit/
git commit -m "refactor(web): migrate the reports and monitoring workspace

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 10: Platform management workspace

Nine tabs, 24 operations, and the most dangerous actions in the product.

**Files:** Modify `PlatformManagementScreen.tsx` (97) and `sections/*.tsx` (7 files) · Create `sections/{RestoreSection,AlertsSection}.tsx`

**PAGES.md sections:** نظرة عامة · الإعدادات والإصدارات · التقويم · الصحة · النسخ الاحتياطي · طلبات الاستعادة · الصيانة · السجلات التقنية · سياسات التنبيهات

- [ ] **Step 1: Write the failing tests for the three rules that matter**

```tsx
it('hides tabs the principal cannot access instead of admitting then denying', () => {
  // Mount with only platform_operations.health.read.
  // Assert the security, calendars, backups, logs and maintenance tabs are absent.
})

it('requires typing the backup name before a restore can be confirmed', () => {
  // Open the restore confirmation. Assert the confirm control is disabled until
  // the exact backup name is typed.
})

it('renders deferred logs as an explanatory alert with a restore action, not a generic error', () => {
  // Resolve the logs query with a 503 problem+json.
  // Assert a restore-request control is present.
})
```

The first replaces today's behaviour: the screen currently renders all seven tabs regardless of capability and shows "unavailable" after the click, which both wastes the click and discloses that the section exists.

- [ ] **Step 2: Run them and confirm they fail**

```bash
npm --prefix apps/web run test:unit -- Platform
```

- [ ] **Step 3: Migrate**

Filter `SECTIONS` by capability before rendering `Tabs`, matching `ReportsMonitoringScreen`'s existing approach. Migrate the seven sections; add restore and alerts. Health must continue to leak no secret, host, or connection string — asserted by `platform-settings-live.spec.ts`. Settings publish stays disabled until validation passes, with a tooltip giving the reason.

- [ ] **Step 4: Verify and commit**

```bash
npm --prefix apps/web run test:unit -- Platform
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- platform-settings-live.spec.ts platform-settings-workflows.spec.ts
git add apps/web/src/features/platform/
git commit -m "refactor(web): migrate platform management and gate its tabs by capability

Tabs are filtered before render rather than admitted and then denied.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 11: My account, notifications, and search

**Files:** Merge `identity/PersonalSecurityScreen.tsx` (160) + `identity/AccessContextScreen.tsx` (311) into `identity/MeScreen.tsx` + `tabs/{SecurityTab,AccessTab}.tsx` · Modify `notifications/NotificationsScreen.tsx` (200), `search/SearchScreen.tsx` (255)

**PAGES.md sections:** أماني · صلاحياتي ونطاقاتي · الإشعارات · البحث

- [ ] **Step 1: Write the failing test for scope invalidation**

```tsx
it('invalidates scope-bound queries when the effective scope changes', () => {
  // Switch scope. Assert the query cache no longer serves the previous scope's
  // rows — showing them would display data the user may not be entitled to.
})
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- Me
```

- [ ] **Step 3: Migrate**

- **`/me`** — two tabs. Security: password form with a `Progress` strength meter using `primary` only; success shows a toast and does not sign the user out. Access: scope list with the effective one marked, plus a read-only capability list grouped by module.
- **الإشعارات** — vertical list, not a table. Unread marked by a `bg-primary` dot and heavier weight, never a tinted row background. "More" button, not infinite scroll.
- **البحث** — full results page with type and status filters and cursor pagination, reached from the command palette's "show all results".

- [ ] **Step 4: Verify and commit**

```bash
npm --prefix apps/web run test:unit
cd apps/web && npx tsc -b && cd .. && ./scripts/check-design-rules.sh
git add apps/web/src/features/identity/ apps/web/src/features/notifications/ \
        apps/web/src/features/search/ apps/web/src/router.tsx
git commit -m "refactor(web): merge the personal account screens and migrate notifications and search

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 12: Retire the legacy design system

This is Task 12 of the foundation plan, now unblocked. Run it here.

- [ ] **Step 1: Prove nothing imports the legacy modules**

```bash
rg -n "styles/(tokens|base|shell|screens)\.css|from '.*\/ui'" apps/web/src --glob '!apps/web/src/ui/**'
```

Expected: **no output**. Any hit names an unmigrated file — finish it before continuing.

- [ ] **Step 2: Execute foundation-plan Task 12 Steps 2–5**

Delete the four stylesheets and `src/ui/index.tsx`, remove the imports from `main.tsx`, un-scope `scripts/check-design-rules.sh`, verify, and commit as specified there.

- [ ] **Step 3: Confirm the gate now passes unscoped**

```bash
./scripts/check-design-rules.sh
```

Expected: `Design rules OK.` with no exemption globs left in the script.

---

## Task 13: The feature-gated workspaces

New construction, not migration: `/work-records`, `/inbox`, `/workflow`, `/work-definitions` — 24 operations with a working, tested API and no UI.

**Files:** Create `apps/web/src/features/{work-records,inbox,workflow,work-definitions}/`

**PAGES.md sections:** سجلات العمل · صندوق الموافقات · سير العمل · نماذج العمل

- [ ] **Step 1: Enable the flag locally**

```bash
grep -n CLUSTER_WORK_MANAGEMENT_ENABLED apps/api/.env.example
```

Set `CLUSTER_WORK_MANAGEMENT_ENABLED=true` in the local API `.env` only. **Do not change the default in `apps/api/config/features.php`** — the default is a product decision, not a build detail.

- [ ] **Step 2: Write the failing test for the absence rule**

```tsx
it('renders nothing and registers no route when the flag is off', () => {
  // With features.work_management false, assert /work-records resolves to the
  // 404 element and that no loading skeleton is rendered first — a skeleton
  // would disclose that the resource exists.
})
```

- [ ] **Step 3: Run it and confirm it fails**

```bash
npm --prefix apps/web run test:unit -- work-records
```

- [ ] **Step 4: Build the four workspaces**

- **سجلات العمل** — list plus detail. The payload is rendered **from the work definition's schema**, not from fixed fields, and `field_access` in the response decides per-field read/edit. This is the substance of the module; hard-coding fields defeats it.
- **صندوق الموافقات** — list of assigned steps with the decision taken in a side `Sheet` so the approver keeps list context.
- **سير العمل** — three tabs: definitions, versions, running instances. The graph renders as an ordered node list, not an interactive diagram.
- **نماذج العمل** — two tabs. The version lifecycle (draft → test → approve → sign → publish) renders as a horizontal `Stepper`. Signing opens a dedicated `Dialog` with an irreversibility warning.

- [ ] **Step 5: Verify with the flag both on and off**

```bash
npm --prefix apps/web run test:unit
CLUSTER_WORK_MANAGEMENT_ENABLED=true W1_1_API_ORIGIN=http://127.0.0.1:8000 \
  npm --prefix apps/web run test:e2e:local -- walking-skeleton.spec.ts
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local -- walking-skeleton.spec.ts
```

Expected: both runs pass. The flag-off run must still satisfy `walking-skeleton.spec.ts:128` and `:145`, which assert 409 feature-disabled on mutations and a single non-disclosing 404 on reads.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/work-records/ apps/web/src/features/inbox/ \
        apps/web/src/features/workflow/ apps/web/src/features/work-definitions/ \
        apps/web/src/router.tsx
git commit -m "feat(web): build the feature-gated work management workspaces

24 operations that had a tested API and no UI. Absent entirely when the
work_management flag is off, matching the non-disclosing 404.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 14: Final verification

- [ ] **Step 1: Route-level code splitting (React.lazy + Suspense)**

Convert the route screens in `apps/web/src/router.tsx` to route-level `React.lazy` + `Suspense` boundaries before the final gates:

- Wrap every route element (workspace shells and detail screens) in `React.lazy(() => import('./features/...'))` with a shared `Suspense` fallback per route.
- Preserve feature-gated route absence: work-management routes are still registered only when the flag is on — lazy loading must never re-register a hidden path or leak a loading skeleton for a non-disclosing 404.
- Keep the fallback accessible: skeleton-based (the shared `LoadingState`), labelled with `role="status"` where appropriate, and no raw spinners.
- After conversion, run `npm --prefix apps/web run build` and record the new **main-entry chunk** and **per-route chunk** sizes (minified + gzip) in `docs/design/plans/baseline.md`.
- Resolve the current >1 MB monolithic warning: the pre-Task-8 main chunk was **1,023.86 kB minified · 284.54 kB gzip** with Vite's ">500 kB" warning. Route-level splitting must bring the main entry under the warning threshold or the remaining size must be explicitly justified in the baseline record. **Plan-only for Task 8 — do not implement lazy routing before this step.**

- [ ] **Step 2: Run every gate**

```bash
cd apps/web && npx tsc -b && cd ..
npm --prefix apps/web run lint
npm --prefix apps/web run test:unit
npm --prefix apps/web run coverage
npm --prefix apps/web run build
./scripts/check-design-rules.sh
python3 scripts/verify-page-coverage.py
bash scripts/validate-docs.sh
make verify-boundaries
W1_1_API_ORIGIN=http://127.0.0.1:8000 npm --prefix apps/web run test:e2e:local
```

Expected: every command exits 0. Record the results in `docs/design/plans/baseline.md` under `## final`.

- [ ] **Step 3: Confirm no legacy remains**

```bash
rg -n "from '.*\/ui'" apps/web/src | wc -l
ls apps/web/src/styles/
```

Expected: `0`, and `styles/` containing only `theme.css` and `index.css`.

- [ ] **Step 4: Commit the record**

```bash
git add docs/design/plans/baseline.md
git commit -m "docs: record the post-migration verification results

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Not in this plan

The four planned workspaces — `/governance` (12 operations), `/risk` (9), `/strategy` (7), `/portfolio` (10) — are specified in `docs/design/PAGES.md` but their API is documented in `openapi.yaml` only, with no route and no controller. They are built when that API ships, not before.
