# Final review fix report

## Findings and exact fixes

1. **Important — organization workspace-local navigation bypassed.** `apps/web/e2e/org-hierarchy-tree.spec.ts` now clicks the visible primary `المنشآت والموظفون` link and then the actual local `المنشآت والهيكل التنظيمي` link in both journeys; the post-entry `page.goto('/admin/organization/structure')` bypasses are removed.
2. **Important — platform workspace navigation mutated browser history.** `apps/web/e2e/platform-settings-comprehensive.spec.ts` now enters through the visible `إدارة المنصة` / `Platform management` primary link, then selects visible links within the labelled `أقسام إعدادات المنصة` / `Platform settings sections` navigation. The primary-link test now clicks through and proves the workspace local navigation mounts. Direct `page.goto` remains only in unauthorized direct-link tests, not in the reviewed authorized workspace journeys.
3. **Important — employee primary navigation was under-asserted.** `apps/web/e2e/capability-navigation.spec.ts` now scopes to `.desktop-sidebar .primary-navigation a`, asserts exactly three links, exact ordered text `الرئيسية`, `مهامي`, `المستندات`, and visibility for every link. Any additional primary destination fails the test.
4. **Important — W1.2 import controls lost their accessibility contract.** `apps/web/e2e/w1-2-cookie-csrf.spec.ts` now addresses the upload and quarantine controls by their production accessible labels `ملف البيانات` and `مرجع الملف`; raw `#import-upload-file` and `#quarantine-id` selectors are removed.
5. **Minor — creation mapping claimed supervisory support.** `apps/web/src/features/authorization/AuthorizationAdmin.tsx` introduces `CreatableAdminResource = Exclude<AdminResource, 'supervisory'>`, types the mapping as `Record<CreatableAdminResource, string>`, and accepts that narrower type without assertions. The focused helper test already covers supported mappings, so no expectation change was required.

## Test evidence

- `npm run test:unit -- src/features/authorization/AuthorizationAdmin.test.tsx` — exit 0; 1 file passed, 5 tests passed.
- `npm run test:e2e:list` — exit 0; 144 tests listed in 23 files.
- `npm run lint` — exit 0; 0 errors, 72 pre-existing warnings.
- `npm run build` — exit 0; TypeScript build and Vite production build completed; Vite reported only the existing large-chunk advisory.

No browser/E2E execution or full unit suite was run, per the task constraints.
