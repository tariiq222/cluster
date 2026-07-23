---
doc_id: OPS-TP-001
title: Physical Topology and Failure Domains
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
- docs/architecture/overview.md
- docs/adr/023-single-host-dokploy-deployment.md
references:
- docs/architecture/docker-compose-platform.md
- docs/operations/ha-dr-backup.md
---
# Physical Topology and Failure Domains

## Scope

Production components run on a single, port-restricted VPS. This model does
not name any real address, domain, or secret, and does not claim high
availability when the host fails.

```text
User
  -> Firewall: 80/443
  -> Caddy / HTTPS
       -> React/Nginx -> Laravel API
       -> Laravel worker
       -> Host-resident MySQL and Redis over a private network
  -> Local monitoring and alerts

Out of host-failure scope
  -> Encrypted backup store with separate credentials
  -> Separate restore target for exercises and incidents
```

## Access boundaries

| Path | Policy |
|---|---|
| User HTTPS | The only operational port exposed to authorized users |
| HTTP | Optional, for HTTPS redirect only |
| SSH | VPN or restricted administrative network/addresses |
| MySQL and Redis | Loopback or a private interface accessible only to the Docker network |
| Docker socket/API | Not exposed on the public network |
| Backups | Outbound connection to an approved external store, or a controlled transfer |

## Failure domain

The server, power, disk, and local network are a single failure domain. A
multi-container layout does not protect against a machine failure. Risks are
mitigated through monitoring, the ability to recreate the Compose stack on
another target, off-host backups, and a measured restore exercise.

## Reference capacity

| Item | Target | Validation method |
|---|---:|---|
| Accounts | 5,000 to 20,000 | Similar-volume data test |
| Concurrent users | Up to 2,000 | Load test on the actual host |
| API | `p95 <= 500ms` | Measurement under approved load |
| Search | `p95 <= 2s` | Measurement under approved load |
| Data loss | `RPO <= 15 minutes` | Restore exercise from the external backup |
| Recovery | `RTO <= 2 hours` | Recreation and restore on a separate target |

These numbers are not guaranteed until they succeed under tests on the actual
host. If load or recovery fails, resources or the single-host decision are
re-evaluated.

## Topology acceptance

- Only the approved ports are reachable from the user path.
- State and management services are inaccessible from unauthorized networks.
- The bundle can be recreated from a commit, lockfiles, and Compose.
- A host failure does not block access to the external backup.
- An isolated restore on a separate target succeeds within the approved
  metrics.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Operations Lead | Initial multi-node Kubernetes model |
| 2.0.0 | 2026-07-16 | Platform Owner | Adopted a single internal host with Compose and backups outside its failure domain |
