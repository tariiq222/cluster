---
doc_id: ARC-EN-005
title: Continuous Integration, Delivery, and Release
type: engineering
status: accepted
version: 3.0.0
date: 2026-07-17
owner: Technical Delivery
reviewers: []
classification: internal
review_cycle: As needed
sources:
- docs/adr/023-single-host-dokploy-deployment.md
- docs/architecture/overview.md
references:
- docs/engineering/database-migrations.md
- docs/plans/readiness-checklist.md
---

> **NOT IMPLEMENTED.** Automated rollback, pre-migration backup, and restore targets are not wired into the deployment chain. Treat the related requirements below as manual operational controls until automation is added.

# Continuous Integration, Delivery, and Release

## CI

`.github/workflows/ci.yml` runs on GitHub-hosted runners for every push and pull request:

| Job | Verification |
|---|---|
| `api` | Composer validation, Pint, PHPStan, dependency audit, API tests, and module boundaries |
| `web` | npm, the OpenAPI contract, linting, coverage, and build |
| `docs` | Documentation validation and a strict MkDocs build |
| `secrets` | Secret scanning with Gitleaks |
| `w1-2-readiness` | W1.2 contract-readiness validation after the API, web, docs, and secrets jobs |
| `production-bundle` | Compose policy, image builds, and the complete MySQL/Redis/Worker/Browser journey |

There are no self-hosted runners, mandatory registry, image-signing step, SBOM step, or build receipts.

## Deployment

On the VPS:

```sh
install -m 600 infra/platform/production/.env.example infra/platform/production/.env.production
# Edit the file once with the actual values.
make deploy-vps
```

Compose builds the images from source, runs the migration, and starts the API, worker, web application, and Caddy. It uses MySQL and Redis already present on the server through `DB_HOST` and `REDIS_HOST`.

## Rollback

1. Select the latest known-good commit and ensure its source and Docker base images remain available for rebuilding.
2. Confirm that its migrations are backward compatible or prepare a forward fix.
3. Run `make deploy-vps` for the selected revision.
4. Verify `/up`, login, and worker processing manually.

No dedicated rollback Makefile target exists; rollback is a rebuild and redeployment of the selected compatible revision.

## Fixed Rules

- No secrets in Git; `.env.production` exists only on the server.
- Caddy is the only public entry point on ports `80/443`.
- MySQL and Redis are not exposed to the internet.
- Dockerfiles build from lockfiles and run as non-root users.
- A valid MySQL backup is required before a high-risk migration, but the deployment chain does not automate this backup.

## Change Log

| Version | Date | Change |
|---|---|---|
| 3.0.0 | 2026-07-17 | Adopted hosted CI and direct VPS deployment with Caddy and external MySQL/Redis |
| 2.0.0 | 2026-07-17 | Simplified CI and moved server operation to the final phase |
