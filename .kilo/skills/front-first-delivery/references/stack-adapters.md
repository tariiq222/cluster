# Stack Adapters

## React / Vite

Look for:

- React Router or TanStack Router
- TanStack Query/React Query
- React Hook Form/Formik
- Vitest/Jest and Testing Library
- MSW browser and node setup
- Vite environment conventions

Prefer generated query hooks only when the repository already uses them. Otherwise generate a typed client and wrap it using the existing query pattern.

## Next.js

Determine App Router versus Pages Router and server/client component boundaries. Do not introduce browser-only MSW code into server execution. Use the repository's mock/test setup.

## Vue / Angular

Use the existing data and form abstractions. The generated TypeScript client remains the boundary; do not force React-specific patterns.

## Laravel

Look for:

- `routes/api.php`
- Form Requests
- Policies/Gates or centralized access-decision logic
- API Resources
- Service/action/domain layers
- Pest or PHPUnit feature tests
- database transaction conventions

Map field validation to the repository's `422` envelope. Do not return raw Eloquent models.

## Node / TypeScript backend

Use the established framework, schema validator, authorization middleware, service layer, ORM, and test harness. Do not add a second validation framework without a material reason.

## Unknown stack

Follow this order:

1. package/workspace scripts;
2. neighboring feature implementation;
3. repository rules and architecture docs;
4. established tests;
5. only then introduce minimal new tooling.
