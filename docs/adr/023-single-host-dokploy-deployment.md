---
doc_id: ADR-023
title: Direct VPS Deployment with Docker Compose
type: adr
status: accepted
version: 2.0.0
date: 2026-07-17
owner: Engineering Office
reviewers: []
classification: internal
review_cycle: as needed
sources:
- docs/architecture/overview.md
references:
- docs/operations/runbooks.md
- docs/operations/ha-dr-backup.md
deciders:
- Engineering Office
scope: hosting, deployment, access, and recovery
supersedes:
- ADR-018
- ADR-019
superseded_by: []
related_adrs:
- ADR-001
- ADR-007
- ADR-013
review_by: 2027-01-17
---
# ADR-023: Direct VPS Deployment with Docker Compose

## Context

The developer has a single VPS with MySQL and Redis. The current application needs no Kubernetes, no Dokploy, and no enterprise release chain.

## Decision

Deploy directly from the repository with Docker Compose. The stack runs Caddy, React/Nginx, the Laravel API, the worker, and a migration job only. Containers use the MySQL and Redis already on the VPS through environment-configured addresses.

Caddy is the only public ingress and issues HTTPS automatically. MySQL, Redis, and the Docker socket are never public. Runtime secrets remain in `.env.production` outside Git.

## Rationale

- One deployment command the developer can run and understand.
- No registry, self-hosted runners, image signing, or operational receipts.
- Host databases are not duplicated inside the production Compose stack.
- A previous release can be rebuilt from a known commit for rollback.

## Runtime

```text
Internet -> Caddy :443 -> React/Nginx -> Laravel PHP-FPM
                                      -> Worker
Laravel/Worker -> MySQL + Redis on the VPS
```

No Kubernetes and no Dokploy are used. The scheduler is not run until a real scheduled task exists. The one-shot migration container precedes the API and worker. Images are built from lockfiles through multi-stage Dockerfiles.

## Security

- Open firewall ports are `80/443` for users and SSH for management addresses only.
- MySQL and Redis listen on loopback or a private interface available only to the Docker network.
- The application uses a least-privilege MySQL account and a Redis password.
- No `network_mode: host` and no privileged containers.

## Deployment and Rollback

`make deploy-vps` performs validation, builds, runs `docker compose up -d`, and checks health. Roll back by selecting the last known-good commit and running the command again. Database changes are backward-compatible; use an independent MySQL backup if data is lost.

## Consequences

- VPS failure stops the service; there is no HA claim.
- Host operations own MySQL, Redis, and backups.
- Reconsider an orchestrator only after adding more than one server or an operations team.

## Change Log

| Version | Date | Change |
|---|---|---|
| 2.0.0 | 2026-07-17 | Dropped Dokploy; adopted direct Docker Compose deployment using the MySQL and Redis already on the VPS |
| 1.0.0 | 2026-07-16 | Adopted Dokploy and Docker Compose on one internal server |
