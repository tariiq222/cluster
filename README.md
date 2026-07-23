---
doc_id: GOV-ROOT-001
title: Third Health Cluster Platform
type: governance
status: accepted
version: 2.0.0
date: 2026-07-17
owner: Engineering Office
reviewers:
- Architecture Review Board
- Operations and Security Review Board
classification: internal
review_cycle: as needed
sources:
- docs/README.md
references:
# Third Health Cluster Platform

Laravel modular monolith with a unified React interface, Arabic by default and supporting
English and RTL/LTR.

## Components

- Laravel API and Outbox/Notifications worker.
- React + TypeScript inside Nginx.
- MySQL and Redis.
- Docker Compose directly on a single VPS, with Caddy for HTTPS.
## Local verification

```sh
make verify-w1-1
make verify-w1-1-local
./scripts/validate-docs.sh
```

`make verify-w1-1` runs API, web, boundary, and MySQL/Redis browser-journey tests. `make verify-w1-1-local` builds production images and runs the temporary Compose bundle.
## VPS deployment

Deployment expects MySQL and Redis to already be running on the server, with their ports not exposed publicly.
1. Copy the environment example:

```sh
install -m 600 infra/platform/production/.env.example infra/platform/production/.env.production
```

2. Set the domain, `APP_KEY`, and MySQL and Redis credentials in `.env.production`.

3. Make MySQL and Redis listen on a private address reachable from the Docker network, with a firewall allowing only the Docker network. `host.docker.internal` identifies the host gateway but cannot reach a service bound only to `127.0.0.1`.

4. Point the domain's DNS to the VPS and ensure Caddy owns `80/tcp` and `443/tcp,udp`; it needs them to issue and renew the HTTPS certificate.

5. Deploy:

```sh
make deploy-vps
The command builds the Laravel and React images, runs migrations, then waits for the API, worker, web, and Caddy health checks and probes `https://<APP_DOMAIN>/up`. Secrets remain in `.env.production` with permission `600` and excluded from Git.
## Operating commands

```sh
docker compose \
  --env-file infra/platform/production/.env.production \
  -f infra/platform/production/compose.yaml ps

docker compose \
  --env-file infra/platform/production/.env.production \
  -f infra/platform/production/compose.yaml logs -f api web worker caddy
```

To roll back, move to the last known-good commit and run `make deploy-vps` again. This rollback rebuilds from source, so the commit and Docker base images must remain available on the server. Do not use a destructive migration for rollback; use a forward fix or restore a MySQL backup.
## Documentation

- Architecture: `docs/architecture/overview.md`.
- Delivery status: `docs/plans/active-delivery-status.md`.
- Operations: `docs/operations/runbooks.md`.
## Confidentiality

Do not add secrets, personal data, or `.env.production` files to Git.
