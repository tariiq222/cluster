---
name: cluster-contracts
description: Keep OpenAPI, Laravel adapters, Orval clients, and React consumers consistent. Use when routes, public DTOs, API schemas, events, or generated clients change.
---

# Cluster Contract Workflow

Authoritative API specifications live under `docs/contracts/api/`. Generated TypeScript clients live under `apps/web/src/api/generated/`.

## Rules

- Change the OpenAPI contract, Laravel implementation, focused API tests, generated client, and React consumer in the same vertical slice when applicable.
- Do not hand-edit files under `apps/web/src/api/generated/`.
- Use `npm --prefix apps/web run api:generate` for generation.
- Assign one owner to shared OpenAPI entry points and generated output.
- Keep handwritten transport and application code outside generated directories.
- Stable `operationId`, response schema, authorization behavior, and error shape are contract concerns.
- TypeScript generation is not runtime response validation.

## Verification

```sh
npm --prefix apps/web run api:lint
npm --prefix apps/web run api:generate
npm --prefix apps/web run api:check
npm --prefix apps/web run build
```

Run generation twice when reproducibility is material and inspect unexpected generated deletions or signature changes.
