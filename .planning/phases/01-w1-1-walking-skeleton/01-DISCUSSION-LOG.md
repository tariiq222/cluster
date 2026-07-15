# Phase 1: W1.1 Walking Skeleton - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `01-CONTEXT.md`; this log preserves the alternatives considered.

**Date:** 2026-07-15
**Phase:** 1-W1.1 Walking Skeleton
**Areas discussed:** Cluster, storage, and deployment operating model; MySQL recovery model; development and deferred supply chain; thin-path user proof

---

## Cluster, Storage, and Deployment Operating Model

| Option | Description | Selected |
|--------|-------------|----------|
| Docker for development only | Use Kubernetes and GitOps for testing and production. | |
| Docker on one local server | Permanent Docker production runtime on one on-premises server. | Yes |
| Transitional Docker | Use Docker until Kubernetes is available. | |

**User's choice:** Docker on one local production server; use separate internal MinIO for attachments and backups.
**Notes:** Development work stays on GitHub. A future release bundle is transferred by an approved internal channel, then the server uses `docker load` and `docker compose up -d`. The user chose to leave the permanent release gate open rather than decide the internal release path now.

---

## MySQL Recovery Model

| Option | Description | Selected |
|--------|-------------|----------|
| Single MySQL with backup | One MySQL instance, encrypted MinIO backup, and manual recovery. | Yes |
| Three-server MySQL cluster | InnoDB Cluster and Router for automatic high availability. | |
| Separate database server | Run MySQL on a different internal server. | |

**User's choice:** One MySQL instance on the Docker production server, encrypted backup to MinIO every 15 minutes, 30-day retention, and manual recovery.
**Notes:** The system administrator owns backup monitoring and recovery. The user explicitly preferred the simplest practical solution over a three-server database cluster.

---

## Development and Deferred Supply Chain

| Option | Description | Selected |
|--------|-------------|----------|
| GitHub for external development | Develop and preserve work externally; keep production isolated. | Yes |
| Internal source platform | Use an internally hosted GitHub or GitLab instance. | |
| Automated external synchronization | Pull source or artifacts through a controlled bridge. | |

**User's choice:** Use Git and external GitHub for development. Internet package downloads are allowed on development machines only. GitHub may contain revocable development secrets, never production secrets.
**Notes:** The system administrator will approve future release bundles. Image signing, signing-key custody, and the detailed isolated intake procedure are deferred; no permanent release can close the Phase 1 gate until they are resolved.

---

## Thin-Path User Proof

| Option | Description | Selected |
|--------|-------------|----------|
| Two fixed test accounts | One development-only account per test facility. | Yes |
| One account switching facility | Simulate isolation with a selector. | |
| Full organization and identity setup | Implement the later-phase capabilities now. | |

**User's choice:** Two fixed development-only facility accounts. The request form contains title and description and submits directly.
**Notes:** The user asked the agent to choose remaining low-level notification and fixture details, provided the implementation is verified.

---

## the agent's Discretion

- Choose the smallest internal notification behavior that proves the request Outbox event is consumed idempotently.
- Choose the detailed test data, API adapters, Docker development configuration, and validation approach consistent with the canonical contracts.

## Deferred Ideas

- Establish the governed internal intake, signing, signing-key custody, signature verification, and deployment procedure before closing the permanent Phase 1 deployment gate.
- Formally resolve or supersede the documented Kubernetes/GitOps target before accepting the Docker-on-one-server runtime as compliant production infrastructure.
