---
doc_id: OPS-DR-001
title: Recovery and Backup
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
- docs/data-security/threat-model.md
- docs/operations/runbooks.md
---
# Recovery and Backup

> **NOT IMPLEMENTED.** No backup or PITR scripts exist in the repository.
> `PLATFORM_BACKUP_COMMAND` (and `PLATFORM_RESTORE_VALIDATION_COMMAND`) are
> read by `apps/api/config/platform_operations.php` but no executor, scheduler,
> or backup script implements them. The RPO `<= 15 min` and RTO `<= 2 h`
> targets stated below are **aspirational** until scripts exist.

## Binding objectives

| Objective | Value | Verification |
|---|---|---|
| RPO | `<= 15 minutes` | Latest restorable data point in the exercise |
| RTO | `<= 2 hours` | From disaster declaration to service recovery |
| Restore exercise | Quarterly | Minutes, measurements, gaps, and a remediation plan |

## Availability limits

The product runs on a single VPS through Docker Compose. There is no
host-level high availability; a host failure halts the service until the
target is restored or the bundle is moved to a replacement. The only
in-host protection is healthchecks and capacity monitoring. Redis and the
search index are not treated as the sole copy of business data.

## Backups and retention

- A MySQL job on the VPS takes full and incremental backups or binlog copies
  sufficient for PITR within the RPO.
- Backups are sent to a target that is independent of the host and encrypted,
  with an account and access keys separate from the application.
- The external target applies immutable or WORM retention when available; the
  fallback is documented when it is not.
- Checksums and signatures are verified after backup and before restore. Any
  verification failure raises an alert and the backup is not considered
  successful.
- Detailed retention periods and legal hold are governed by the data
  governance documents; this document does not override them.

## Recovery sequence

1. The incident commander declares the recovery level and blocks further
   writes when required.
2. The team identifies the last good backup and the required PITR time, and
   records the start time and approvers.
3. MySQL is restored to a separate target or an isolated network. Data,
   application integrity, and the loss window are measured.
4. Files consistent with the data point are restored, then indexes and
   projections are rebuilt from sources of truth when needed.
5. The incident commander decides on returning to service after health,
   access controls, and critical functions are verified.
6. Actual RPO/RTO are measured, and remediation is opened for any failure or
   breach.

A backup is not restored directly over production without verification in an
isolated environment, unless the incident commander documents that this is
impossible and the delay would threaten RTO.

## Quarterly exercise

The exercise includes a point-in-time MySQL restore, a sample of files,
running the Compose bundle on a separate target, verifying the API and audit
paths, and measuring RPO/RTO. Evidence is kept without sensitive data and
covers the tested backup, the results, and any deviations. The RPO and RTO
values remain targets to be demonstrated; they do not imply continuous
availability when the host fails.

## Change log

| Version | Date | Role | Change |
|---|---|---|---|
| 1.1.0 | 2026-07-16 | Operations Lead | Replaced clustered high availability with off-host recovery under ADR-023 |
