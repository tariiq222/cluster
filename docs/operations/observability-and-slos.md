---
doc_id: OPS-MN-001
title: Observability and Service Level Objectives
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
- docs/adr/023-single-host-dokploy-deployment.md
references:
- docs/operations/incident-response.md
- docs/operations/runbooks.md
---
# Observability and Service Level Objectives

> **NOT IMPLEMENTED.** OpenSearch, Loki, and Prometheus are documented as
> observability backends below, but none of them is present in
> `infra/platform/production/compose.yaml`. There is no `/metrics` endpoint
> under `apps/api/routes/`. The signals, dashboards, and alert acceptance
> criteria in this document are **aspirational** until a metrics stack is
> wired up.

## Observability principles

The platform collects internal metrics, logs, and traces. It uses a
correlation ID from the gateway through the API and into the worker, and it
redacts secrets, PII, and sensitive payloads. Retention periods are set in a
separate policy; this document does not record live operational values.

## SLOs

| Service | SLI | SLO | Measurement window |
|---|---|---:|---|
| API | Share of successful requests after exclusions | `99.9%` | Calendar month |
| API | `p95` latency of monitored requests | `<= 500ms` | Rolling window sized for alerting and reporting |
| Search | `p95` latency | `<= 2s` | Rolling window sized for alerting and reporting |
| Indexing | Lag between source event and index visibility | `<= 60s` | Rolling window sized for alerting and reporting |
| Backups | Most recent restorable point | `<= 15 minutes` | Continuous and quarterly exercise |

Errors excluded from availability are client requests rejected by validation
or authorization, and announced planned maintenance. Exclusions are recorded
in a versioned SLI definition before being applied.

## Dashboards and signals

| Domain | Minimum signals |
|---|---|
| API | Request rate, error rate, latency, saturation, readiness |
| Workers | Queue depth, age of oldest message, failures, retries, DLQ |
| MySQL | Health, latency, connections, backup health, storage |
| Redis | Health, memory, connections, Streams, pending entries |
| OpenSearch (search) | Cluster health, query latency, queue, indexing lag, disk |
| Loki (logs) | Ingestion health, log lag, query latency, storage errors, retention |
| Storage | Capacity, errors, latency, backup success, Object Lock |
| Server and Docker | CPU, memory, disk, Docker health, container restarts, healthchecks |
| Security | Rejected ports, SSH attempts, Caddy certificate expiry, deploy audit |

## Alerts and escalation

- `P1`: Wide API outage, data-loss risk, restore failure, or an active
  security incident. The incident commander is paged immediately.
- `P2`: Sustained SLO breach, queue backlog, sustained degradation with
  service still up, or critical capacity without immediate widespread
  impact.
- `P3`: Predictive or non-critical alert; recorded and handled in priority
  order.
- Every alert maps to a role owner, a runbook, and a closing criterion;
  non-actionable alerts are forbidden.

## Observability acceptance

- A dashboard and the corresponding signals exist for every SLI above before
  production launch.
- At least one alert per P1/P2 is exercised in `Staging` without production
  data.
- An incident can be linked to its start time, scope, the deployed release,
  and the relevant correlation IDs.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.0.0 | 2026-07-15 | Operations Lead | Initial SLOs and observability model |
| 1.1.0 | 2026-07-16 | Platform Owner | Aligned observability with the single Compose host and accepted the single-failure-domain risk |
