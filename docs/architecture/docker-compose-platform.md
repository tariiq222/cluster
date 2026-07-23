---
doc_id: OPS-DP-001
title: Docker Compose Platform on a VPS
type: operations
status: accepted
version: 3.0.0
date: 2026-07-17
owner: Operations Lead
reviewers:
- Platform Engineering Office
- Information Security Lead
classification: internal
review_cycle: semi-annual
sources:
- docs/architecture/overview.md
- docs/adr/023-single-host-dokploy-deployment.md
references:
- docs/operations/physical-topology.md
- docs/operations/ha-dr-backup.md
---
# Docker Compose Platform on a VPS

> Originally published as `docs/operations/kubernetes-platform.md` under
> the historical filename. After the move to
> `docs/architecture/docker-compose-platform.md`, the document is the source
> of truth for the deployment. The product uses no Kubernetes and no Dokploy.
> ADR-023 (single-host Docker Compose + Caddy) governs this design.

## Layout

Production runs on a single VPS. Docker Compose starts five services: Caddy,
Web, API, Worker, and a one-shot Migration. The application reuses the MySQL
and Redis instances installed on the host.

| Component | Decision |
|---|---|
| HTTPS | Caddy issues the certificate and routes to the Web service |
| Web | React static build served by Nginx |
| API | Laravel on PHP-FPM |
| Processing | Outbox worker and Redis Streams; no Scheduler, no scheduled jobs |
| State | MySQL and Redis live outside the application Compose stack and are not exposed publicly |
| Secrets | `.env.production` on the host, never committed to Git |

## Deployment

```sh
make deploy-vps
```

The command builds images from the Dockerfiles and lockfiles, runs
migrations, starts the services, and verifies `/up`. No registry, no
admin panel, and no runner are required on the host.

## Access

- `80/tcp` for redirects and certificate issuance; `443/tcp,udp` for users.
- SSH is restricted to administrative addresses.
- `3306` (MySQL), `6379` (Redis), and the Docker socket are not public.
- Containers reach host services through `host.docker.internal:host-gateway`
  or another private address.

## Rollback

Select the last known-good commit and re-run `make deploy-vps`. Never use a
destructive down-migration. Apply a forward fix or restore MySQL instead.

## Availability limits

The VPS is a single point of failure. Running multiple containers does not
provide high availability. Risks are mitigated through monitoring, off-host
backups, and the ability to recreate the bundle on a replacement VPS.

## Change log

| Version | Date | Change |
|---|---|---|
| 3.0.0 | 2026-07-17 | Adopted direct Compose + Caddy with host-resident MySQL/Redis |
| 2.0.0 | 2026-07-16 | Replaced Kubernetes with a single-host Compose deployment |
