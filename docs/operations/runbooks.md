---
doc_id: OPS-RB-001
title: Operational Runbooks
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
- docs/adr/023-single-host-dokploy-deployment.md
- docs/architecture/overview.md
references:
- docs/operations/physical-topology.md
- docs/operations/ha-dr-backup.md
- docs/operations/incident-response.md
---
# Operational Runbooks

> **NOT IMPLEMENTED.** No `Staging` environment exists. Every runbook below
> instructs the operator to test in Staging first, but `infra/` only
> contains `dev/` (local) and `platform/production/`. Runbooks therefore run
> against production with no staging gate.

## Common execution rules

1. Open an incident or change record, and note the environment, scope,
   release, and start time.
2. Run in `Staging` first whenever possible. Do not run destructive deletes
   or irreversible changes without incident-commander approval.
3. Replace `<...>` placeholders with values from the governed configuration
   store. Never put real names or secrets into a ticket or shell history.
4. Record the command or action, the result, and the end time, and verify
   health and SLOs before closing.

## RB-01: API failure or SLO breach

**Trigger:** API availability below `99.9%`, API `p95 > 500ms`, or a wide
readiness alert.

1. Declare P1 if the impact is wide, otherwise P2, and stop any new Compose
   deploys.
2. Inspect the Compose container state, events, restarts, resource use, and
   disk space.
3. Correlate the impact start with the most recent Compose revision, a
   structural change, or a load shift.
4. If the latest deploy is the likely cause, roll back to the last
   known-good commit and run `make deploy-vps`, then verify health, error
   rate, and `p95`.
5. If the deploy is not the cause, inspect API connectivity to MySQL and
   Redis without exposing secrets. Escalate to RB-02 or RB-03 depending on
   the service.
6. Close only after the SLI is stable for the approved window and the cause
   or hypothesis is recorded.

## RB-02: MySQL incident or PITR

**Trigger:** MySQL outage, data corruption, disk full, or a restore request.

1. Declare P1 on data risk or write outage, and stop work that could
   increase damage after incident-commander approval.
2. Inspect the MySQL service on the VPS, its health, disk, and backups. Do
   not start manual recovery without an approved action.
3. After the service returns or is replaced, verify read/write health, the
   outbox, and the worker.
4. On corruption or data loss, identify the PITR time and the safe backup,
   then restore into an isolated environment following
   `ha-dr-backup.md`.
5. Verify the schema, critical data, and consistent files, then record
   RPO/RTO and obtain the return-to-service approval.

## RB-03: Worker backlog or indexing lag

**Trigger:** Oldest message age or indexing lag above `60s`, or a non-empty
DLQ.

1. Identify the stream/consumer, the release, and the start of the backlog,
   then inspect Redis, worker health, and MySQL.
2. Do not restart every worker at once. Restart a single stuck container
   within the approved capacity.
3. Inspect repeated failures and idempotency. Move failing messages to the
   DLQ and do not delete them before preserving evidence.
4. Once processing is stable, re-index the affected data from the source of
   truth when needed, and confirm that the index exposes no unauthorized
   data.
5. Close when the backlog drains and indexing lag stays below `60s`.

## RB-04: Backup failure or restore exercise

**Trigger:** Backup older than 15 minutes, verification failure, or a
quarterly exercise.

1. Open P2 if the RPO is exceeded or the last good backup failed, and stop
   any backup deletion or retention change.
2. Inspect the MySQL backup job on the host, the backup store, capacity, and
   checksums, without writing access keys into logs.
3. Run a fallback backup after fixing the cause and verify it is present and
   intact in the separate store.
4. For an exercise, restore into an isolated network, perform PITR, verify
   the API, files, and samples, then compute RPO/RTO.
5. The exercise is not successful if it exceeds either target or does not
   document its gaps and remediation plan.

## RB-05: Deploy or rollback on the VPS

**Trigger:** Approved release or failed deploy.

1. Verify the commit, CI success, and a recent MySQL backup for high-risk
   migrations.
2. Run `make deploy-vps` from the required commit and record the operation.
3. Monitor health, error rate, API SLOs, and internal network state.
4. On a failure that cannot be fixed quickly, roll back to the last
   known-good commit and re-run `make deploy-vps`.
5. Record both revisions, the rollback reason, and any migration or state
   that requires follow-up.

## RB-06: Certificate or exposed/expired secret

**Trigger:** PKI expiry alert, secret leak, or service authentication
failure.

1. Open P1 if a production secret may be exposed or the outage is wide, and
   notify the information security lead.
2. Rotate the credential in MySQL, Redis, or the upstream provider, and
   update `.env.production` on the VPS with `600` permissions. Never share
   the value in a ticket, chat, or commit.
3. Run `make deploy-vps`, then revoke the previous credential after the
   HTTPS and service checks pass.
4. Verify connectivity and audit logs, and look for any unusual use of the
   exposed identity.
5. Document the scope, time, cause, and preventive actions. Follow
   `incident-response.md` if exploitation is suspected.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-16 | Operations Lead | Updated procedures for the single-host Compose deployment |
