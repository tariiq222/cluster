# API Contracts — Source of Truth

This directory holds the **single source of truth** for the Cluster API
contract. The web client generates and lints against this file; nothing else in
the repository is authoritative.

## Files

| File | Purpose | Generated artifacts that depend on it |
| --- | --- | --- |
| `openapi.yaml` | The complete API surface (every live route plus explicitly `planned` operations). Consumed directly by Orval and Redocly. | `apps/web/src/api/generated/cluster.ts`, `apps/web/.orval/api-reference.html`. |

There is exactly one contract file. Milestone split contracts
(`w1-1`/`w1-2`/`r1-screens`) and the bundle/merge pipeline were removed; their
history remains in git if a past snapshot is ever needed.

## Regeneration pipeline

```
docs/contracts/api/openapi.yaml
        │
        ▼  orval --config apps/web/orval.config.ts
apps/web/src/api/generated/cluster.ts  ← consumed by React screens
```

Run from the repository root:

```sh
npm --prefix apps/web run api:generate   # orval, direct from the source contract
npm --prefix apps/web run api:lint       # redocly lint on the source contract
npm --prefix apps/web run api:check      # lint + check-generated-api (drift guard)
npm --prefix apps/web run api:docs       # build the browsable Redoc reference
```

Run `make docs-validate` to execute the focused Notifications, Authentication,
and WorkRecords validators plus YAML/JSON and Markdown-link checks. The
repository intentionally uses a lean documentation tree; the removed
catalog/MkDocs registry is not a prerequisite.

## Editing rules

1. **Edit the source here**, never the generated client in
   `apps/web/src/api/generated/`.
2. After editing, run `npm --prefix apps/web run api:generate` and commit the
   regenerated client alongside the source change.
3. Tag every operation that is documented but not yet wired to a live route
   with `x-implementation-status: planned`. Anything untagged must be live.
4. Lint failures block CI. A PR that touches the contract must keep
   `npm --prefix apps/web run api:check` green.

## Schemas

Outbox event JSON Schemas live one level up in `docs/contracts/schemas/`. The
`test_every_event_type_in_outbox_has_a_matching_json_schema` architecture test
asserts that every `event_type` string emitted by an outbox row has a matching
schema file in that directory.
