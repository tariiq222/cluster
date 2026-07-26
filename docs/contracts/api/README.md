# API Contracts — Source of Truth

This directory is the **single source of truth** for every OpenAPI contract in
Cluster. The web client bundles, generates, and lints against these files;
nothing else in the repository is authoritative.

## Files

| File | Purpose | Generated artifacts that depend on it |
| --- | --- | --- |
| `openapi.yaml` | Master API surface (current sprint: W1.3 R1 + W1.4 / W1.5 / W1.7 screens). Bundled by Redocly; consumed by Orval. | `apps/web/.orval/cluster-client.openapi.yaml`, `apps/web/src/api/generated/cluster.ts`. |
| `w1-1.openapi.yaml` | W1.1 snapshot — frozen contract for the first walking-skeleton release. Do not change casually. | `apps/web/.orval/cluster.openapi.yaml` (intermediate bundle only). |
| `w1-2.openapi.yaml` | W1.2 snapshot — frozen contract for the second milestone. Do not change casually. | `apps/web/.orval/cluster-w1-2.openapi.yaml` (intermediate bundle only). |
| `r1-screens.openapi.yaml` | R1 screens snapshot — frozen contract for the R1 release bundle. | `apps/web/.orval/cluster-r1-screens.openapi.yaml` (intermediate bundle only). |

## Regeneration pipeline

```
docs/contracts/api/*.yaml
        │
        ▼  redocly bundle (apps/web/redocly.yaml)
apps/web/.orval/*.yaml                ← intermediate bundles
        │
        ▼  build-client-contract.mjs
apps/web/.orval/cluster-client.openapi.yaml
        │
        ▼  orval --config apps/web/orval.config.ts
apps/web/src/api/generated/cluster.ts  ← consumed by React screens
```

Run from the repository root:

```sh
npm --prefix apps/web run api:generate   # bundle + build-client + orval
npm --prefix apps/web run api:lint       # redocly lint on every source
npm --prefix apps/web run api:check      # lint + check-generated-api (drift guard)
```

Run `make docs-validate` to execute the focused Notifications,
Authentication, WorkRecords, W1.1, and W1.2 validators plus YAML/JSON and
Markdown-link checks. The repository intentionally uses a lean documentation
tree; the removed catalog/MkDocs registry is not a prerequisite.

## Editing rules

1. **Edit the source here**, never the intermediate bundle in `apps/web/.orval/`.
2. After editing, run `npm --prefix apps/web run api:generate` and commit the
   regenerated intermediate bundles alongside the source change.
3. The `w1-1`, `w1-2`, and `r1-screens` snapshots are frozen — a change to one of
   them requires a new snapshot file (for example `w1-3.openapi.yaml`) and a
   coordinated update to `apps/web/package.json` `api:bundle` script.
4. Lint failures block CI. A PR that touches a contract must keep
   `npm --prefix apps/web run api:check` green.

## Schemas

Outbox event JSON Schemas live one level up in `docs/contracts/schemas/`. The
`test_every_event_type_in_outbox_has_a_matching_json_schema` architecture test
asserts that every `event_type` string emitted by an outbox row has a matching
schema file in that directory.
