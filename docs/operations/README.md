---
doc_id: OPS-OV-001
title: Operations Documentation Index
type: operations
status: accepted
version: 1.1.0
date: 2026-07-16
owner: Operations Lead
reviewers:
- Platform Engineering Office
- Information Security Lead
classification: internal
review_cycle: semi-annual
sources:
- docs/architecture/overview.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/adr/023-single-host-dokploy-deployment.md
references:
- docs/governance/document-control.md
- docs/governance/assumptions-constraints.md
---
# Operations Documentation Index

This bundle describes the operational design of a single, port-restricted VPS.
It contains no real host names, domains, addresses, or secrets; those values
are held in `.env.production` on the server.

| Document | Purpose |
|---|---|
| [Physical Topology](physical-topology.md) | Site layers, failure domains, and reference capacity |
| [Docker Compose Platform](../architecture/docker-compose-platform.md) | Single-server decision, containers, access, and deployment |
| [High Availability, Recovery, and Backup](ha-dr-backup.md) | Recovery, PITR, RPO/RTO targets, and restore exercises |
| [Observability and SLOs](observability-and-slos.md) | Metrics, alerts, and service-level objectives |
| [Supply Chain](air-gap-supply-chain.md) | Image pinning, updates, reviews, and SBOM |
| [Incident Response](incident-response.md) | Severity levels, roles, containment, and communication |
| [Runbooks](runbooks.md) | Executable procedures for recurring and critical cases |

## Binding decisions

- Production runs on a single VPS through Docker Compose and Caddy. There is
  no Kubernetes and no Dokploy in production.
- The application reuses the MySQL and Redis instances installed on the host;
  their ports are not published to the public network.
- HTTPS is the user-facing path. SSH is restricted to administrative addresses.
- Images are built from lockfiles at deploy time, and any release can be
  rolled back to a known-good commit.
- Dev and Test must stay outside Prod data and secrets. A second Compose
  project on the same host does not, on its own, constitute full security
  isolation.
- Backups are stored encrypted outside the production host, and recovery is
  exercised against a separate target.

## Operational acceptance criteria

- `RPO <= 15 minutes` and `RTO <= 2 hours`, demonstrated by a quarterly
  restore exercise.
- High availability is **not** claimed when the single host fails. Effective
  availability is measured. API `p95 <= 500ms`, search `p95 <= 2s`, and
  indexing lag `<= 60s`.
- A load test must show the service supporting up to `2,000` concurrent users
  before launch.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Operations Lead | Initial operations bundle index |
| 2.0.0 | 2026-07-17 | Platform Owner | Adopted direct Docker Compose + Caddy with host-resident MySQL/Redis on a single VPS |
