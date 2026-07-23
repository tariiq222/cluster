---
doc_id: OPS-SC-001
title: Image and Update Supply Chain for the Internal Host
type: operations
status: accepted
version: 2.0.0
date: 2026-07-16
owner: Operations Lead
reviewers:
- Platform Engineering Office
- Information Security Lead
classification: internal
review_cycle: semi-annual
sources:
- docs/adr/023-single-host-dokploy-deployment.md
- docs/architecture/overview.md
references:
- docs/governance/assumptions-constraints.md
- docs/data-security/threat-model.md
- docs/architecture/docker-compose-platform.md
---
# Image and Update Supply Chain for the Internal Host

> The filename is kept for historical link stability. The environment is not
> a corporate air gap, and it does not require package mirrors or an internal
> registry unless the actual environment later imposes them.

## Principle

Dependencies are built from lockfiles, producing a fixed OCI image that can
be traced to a Git revision. The build and update path may reach approved
sources, but production containers do not download packages at start-up,
and the user-facing surface depends on no public CDN or public scripts.

## Release flow

1. The change, lockfiles, test results, secret scan, and vulnerability scan
   are reviewed.
2. Laravel and React images are built from the Dockerfiles and lockfiles.
3. Compose is validated, migrations run, then healthchecks execute.
4. The deployed commit is recorded and the last known-good commit is kept
   for rollback.

## Controls

- Containers never depend on `latest` or download packages at user-service
  start.
- Git, images, and logs contain no secrets.
- Production containers never run `composer install`, `npm install`, or
  unexpected image pulls after they have started serving users.
- Vulnerabilities and licenses are reviewed before release.
- The Docker socket is not reachable from the user path.
- Docker and image updates are scheduled inside a maintenance window with a
  documented rollback plan.

## Per-release acceptance evidence

| Evidence | Acceptance condition |
|---|---|
| Git commit | Pinned, and CI passed on it |
| Compose | Valid and compatible with the environment variables |
| Tests and scans | No failure or unapproved exception blocks the release |
| VPS deploy | `make deploy-vps` with successful healthchecks |
| Rollback | A previous known-good commit is available and rehearsed |

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Operations Lead | Initial isolated supply-chain controls |
| 2.0.0 | 2026-07-16 | Platform Owner | Repositioned the controls as the update supply chain for a single internal host with restricted connectivity |
