# Cluster Backup, Restore, and Release Rollback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `skill://subagent-driven-development` (recommended) or `skill://executing-plans` to implement this plan task-by-task. Use an isolated worktree. Steps use checkbox (`- [ ]`) syntax for execution tracking.

```yaml
plan_id: P03
status: blocked
depends_on: [P01, P02]
blocks: ['P07:production-execution']
shared_file_owner:
  - 'infra/platform/production/compose.yaml (token after P02)'
  - 'infra/platform/production/.env.example (token after P02)'
  - 'deployment/rollback scripts selected by P03'
implementation_commit: null
last_verified_commit: null
last_status_change: '2026-07-26'
tree_digest: "sha256(concat(UTF-8 file bytes for M00-M07 and P01-P08 in ascending plan_id order, removing only each tree_digest YAML scalar token))"
```

**Goal:** Give Cluster operators an encrypted, integrity-checked, cross-store recovery path and a release procedure that can safely choose rollback or roll-forward, with measured RPO/RTO evidence.

**Architecture:** MySQL and the two P02 versioned document buckets are authoritative. P01's durable Redis transport/review state is captured in a fenced server-wide AOF; recovery loads that artifact, preserves DB 0 Streams/groups/PELs/DLQs, then flushes DB 1 cache/sessions before any application starts. A P03-owned, non-root operations image provides fixed backup tooling, while host-side scripts exclusively orchestrate the existing production Compose topology and an explicitly isolated recovery overlay. Releases use immutable image digests, an expand/contract schema declaration, a pre-migration coherent backup, and fail-closed rollback rules.

**Tech Stack:** Docker Compose v2, MySQL 8.4, Redis 8.2, S3-compatible object storage, AWS CLI, Restic, Bash, Python 3.12, Laravel migrations, JSON Schema 2020-12.

**Approved Design:** `docs/superpowers/specs/2026-07-26-cluster-production-and-modules-program-design.md`

## 1. Status, dependencies, and handoff gates

P03 remains `blocked` until the orchestration ledger records both `P01` and `P02` as `completed`, and grants P03 all three production-topology tokens from the same clean base commit:

- `PROD-COMPOSE` for `infra/platform/production/compose.yaml`, after P02 has merged and released it;
- `PROD-ENV` for `infra/platform/production/.env.example`, after P02 has merged and released it;
- `PROD-RELEASE` for the deployment, backup, restore, drill, and rollback files listed in §6.

The token grant must retain P01's final durability and workload contract: MySQL domain/outbox rows and `platform_operation_requests` are authoritative; Redis Streams, consumer PELs, and DLQs are durable transport/review state backed by Redis AOF; cache keys are rebuildable; worker health uses `/tmp/worker.ready` with a 30-second freshness bound; scheduler health uses `/tmp/scheduler.ready` with a 90-second freshness bound. The exact quiesce gate stops `scheduler`, `caddy`, `web`, `api`, and `worker`, then runs `docker compose ... run --rm --no-deps worker /usr/local/bin/worker-loop run-once` until two consecutive zero-work cycles and both owner-unpublished counts and Redis `XPENDING` counts are zero. Recovery order is MySQL, Redis/AOF, migration compatibility, API, worker, scheduler, web, then Caddy. It forbids global `published_at` resets and broad `XACK`, `XDEL`, `DEL`, or DLQ purge; any replay uses exact event IDs through an owner-approved path while preserving CloudEvent IDs. The grant must also retain the exact P02 document runtime contract. P02's current agreed contract is the existing zone-prefixed environment family:

- `DOCUMENTS_QUARANTINE_AWS_ACCESS_KEY_ID`, `DOCUMENTS_QUARANTINE_AWS_SECRET_ACCESS_KEY`, `DOCUMENTS_QUARANTINE_AWS_SESSION_TOKEN`, `DOCUMENTS_QUARANTINE_AWS_DEFAULT_REGION`, `DOCUMENTS_QUARANTINE_AWS_BUCKET`, `DOCUMENTS_QUARANTINE_AWS_ENDPOINT`, `DOCUMENTS_QUARANTINE_AWS_USE_PATH_STYLE_ENDPOINT`, `DOCUMENTS_QUARANTINE_KMS_KEY_ID`;
- the mirrored `DOCUMENTS_AVAILABLE_AWS_*` variables and `DOCUMENTS_AVAILABLE_KMS_KEY_ID`;
- `DOCUMENTS_AVAILABLE_OBJECT_LOCK_MODE`;
- private, versioned `documents-quarantine` and `documents-available` buckets, with Object Lock on the available bucket;
- the successful `verify-documents-runtime.sh` handoff evidence.

P03 cannot become `ready` until the production Redis owner proves a fenced, server-wide AOF export and import mechanism. Record the provider command/API name, export location or immutable snapshot identifier, retention, encryption, a successful `redis-check-aof` result, and an isolated import exercise in the `PROD-RELEASE` grant evidence. Because Redis persistence files span every logical database, the artifact necessarily includes DB 1; restore must load it while all application services remain stopped and immediately execute an authenticated `redis-cli -n \"$REDIS_CACHE_DB\" FLUSHDB` before any application process starts. If the external Redis service cannot export and import AOF while preserving Streams, consumer groups, PELs, and DLQs, P03 remains `blocked`; there is no RDB, logical-key, or broad outbox-replay fallback in this plan.

If P01 or P02 changes any of those outputs before token release, P03 must update this plan and the orchestration ledger through the approved mutation protocol before implementation. It must not silently adapt.

The current architecture-closure plan remains `in_progress` and retains `Makefile`, `.github/workflows/ci.yml`, `.github/workflows/ci-e2e.yml`, `docs/contracts/api/openapi.yaml`, `apps/web/src/api/generated/cluster.ts`, `apps/api/tests/Architecture/ModuleBoundariesTest.php`, and its active `apps/api/routes/web.php` token. P03 neither edits nor requests those surfaces. P08 alone may integrate P03 commands into Make/CI after the current Task 13 handoff.

No commit, push, deployment, migration, cloud mutation, retention deletion, or restore is authorized by this plan. A commit checkpoint may be recorded only after explicit user authorization.

## 2. Goal and user-visible outcome

After implementation, an authorized operator can:

1. create one coherent encrypted backup containing the MySQL dump and migration ledger, a server-wide Redis AOF containing durable DB 0 transport state, and version-aware inventories plus bytes/retention metadata for both document buckets;
2. verify repository authentication, ciphertext integrity, component hashes, database readability, Redis AOF validity, and object inventory completeness without restoring production;
3. restore only into an empty, positively identified drill target by default;
4. run a full isolated recovery drill and retain machine-readable proof that the recovered API, workers, scheduler, outbox/streams, and document lifecycle operate;
5. deploy digest-pinned API/web images only after a preflight and coherent backup;
6. roll back application images when the schema is backward-compatible, otherwise refuse unsafe rollback and perform a reviewed roll-forward;
7. demonstrate a normal target of RPO ≤ 86,400 seconds and RTO ≤ 14,400 seconds from the newest verified coherent full backup. PIT recovery is **mandatorily unavailable** in every initial recovery: the operator must read `pit_disclosure.point_in_time_capable=false` (the canonical gate; `point_in_time_recovery_supported` is the P08 alias and equals `point_in_time_capable` per the alias mapping), a recovery window ending at the newest verified coherent full backup, and an explicit unsupported reason of "no continuous binlog shipping" from the live evidence manifest via the documented operator command. The runbook (`docs/operations/ha-dr-backup.md`) is checked-in infrastructure that mandates the fixed field names, the initial fixed values, the four mandatory future PIT conditions, and the operator command that reads the live evidence — it does not embed per-run values. A point-in-time claim is **prohibited** unless every one of the four §8 PIT conditions is independently evidenced on the requested UTC cutoff and the same manifest flips `point_in_time_capable` to `true` (and therefore `point_in_time_recovery_supported` to `true`) with a precise recovery window and restore point. The runbook carries the fixed field names and the initial fixed values; the evidence manifest and the P08 handoff descriptor carry the same `pit_disclosure` block byte-for-byte (the only run-scoped byte-equality check); no operator is permitted to infer PIT support from tooling, schedules, or vendor documentation.

The web/API backup-request surfaces remain honest: `CommandBackupOperationsGateway` stays fail-closed/unconfigured in production unless a later approved plan provides a non-container-escape execution transport. P03 does not mount the Docker socket into API, worker, or scheduler containers and does not represent an operator-only host script as an application-executable backup.

## 3. Current source evidence

The executor must re-read these exact artifacts on the granted base commit and retain a short evidence inventory before editing:

- `infra/platform/production/compose.yaml` currently defines `api`, `worker`, `scheduler`, and one-shot `migrate` services, an `app-storage` local volume, external MySQL/Redis endpoints, Redis DB 0/default and DB 1/cache, and automatic `php artisan migrate --force --no-interaction` before API startup.
- `infra/platform/production/.env.example` currently covers only application, MySQL, Redis, and log settings; there is no backup repository, recovery target, retention, or release-manifest contract.
- `infra/platform/production/deploy-vps.sh` currently executes `docker compose up --detach --build --remove-orphans`, waits for migration and service health, and checks `/up`; it has no immutable digest requirement, pre-migration backup, schema compatibility gate, retained release state, or rollback.
- `infra/platform/production/build-images.sh` and `verify-images.sh` build and inspect local tags but do not prove registry digests or a reversible release.
- `infra/platform/production/compose.test.yaml` supplies pinned MySQL 8.4.6 and Redis 8.2.1 test services with durable volumes; it is not a destructive recovery target contract.
- `infra/platform/production/run-local-e2e.sh` proves Caddy, migrations, API/web/worker health, Redis restart, and selected Playwright journeys, but not backup, restore, document-object recovery, schema rollback, or RPO/RTO.
- `apps/api/config/database.php` separates Redis `default` (`REDIS_DB`, currently 0) and `cache` (`REDIS_CACHE_DB`, currently 1).
- `apps/api/config/cache.php` binds the Redis cache store to the `cache` connection. `apps/api/config/session.php` allows `SESSION_CONNECTION`; P03 must set it to `cache` so restored durable Redis state cannot resurrect sessions. Because production currently leaves `SESSION_CONNECTION` unset, this clean cutover logs out all active users and must be announced in release preflight; reverting the variable logs them out again.
- `apps/api/Shared/Infrastructure/Outbox/DatabaseTransactionalOutbox.php`, module outbox tables, and Redis relays show that MySQL stores authoritative event envelopes while Redis Streams transport them. P01's completed contract makes Redis Streams, consumer PELs, and DLQs durable review/transport state backed by AOF; its exact bounded drain and replay prohibitions are mandatory P03 inputs.
- `apps/api/config/module_migrations.php` is the canonical ordered migration list. It includes the shared `outbox_events`, Documents core/governance tables and `document_outbox_events`, and all current module migrations.
- `apps/api/config/filesystems.php` defines separate quarantine and available S3 credentials/buckets/KMS keys and the `documents/quarantine` and `documents/available` roots. P02 completes the production versioning/Object Lock policy.
- `apps/api/app/Integrations/PlatformOperations/CommandBackupOperationsGateway.php` accepts only one absolute executable path and currently reports no successful/failed/validation timestamps. It cannot safely orchestrate host Docker from the worker container.
- `apps/api/config/platform_operations.php` defaults to `PLATFORM_OPERATIONS_RUNTIME_ENABLED=false` and names `docs/operations/ha-dr-backup.md`; that runbook does not currently establish a tested production restore.

Retain this inventory at `artifacts/p03-recovery/source-inventory.json` with the base commit, SHA-256 of each inspected file, and P01/P02 token evidence paths. It must contain paths and hashes only, never environment values.

## 4. Scope and explicit non-goals

### In scope

- scheduled coherent full backups and operator-triggered full backups;
- Restic client-side encryption/authentication and a separately credentialed S3-compatible repository;
- component SHA-256 checks, Restic checks, retention/pruning safeguards, and restore selection by immutable Restic snapshot ID;
- MySQL dump/restore with migration and binlog/GTID metadata;
- server-wide Redis AOF capture/restore, preservation of DB 0 Streams/groups/PELs/DLQs, and mandatory post-load flush of DB 1 cache/sessions before application startup;
- version-aware capture/restore of P02 quarantine and available objects, KMS/Object Lock/legal-hold metadata, and source-to-restored-version mapping;
- isolated recovery Compose overlay, restore drill, API/workload/document smoke exercises, and RPO/RTO evidence;
- immutable release manifests, schema compatibility rules, deploy, application-image rollback, and roll-forward;
- operator runbooks and machine-readable evidence validation.

### Non-goals

- a second deployment topology, Kubernetes, managed-service selection, multi-region active/active, or a new cloud provider;
- changing module routes, OpenAPI, generated clients, capabilities, public Contracts/Events, module tables, or application command semantics;
- exposing production restore through HTTP or automatically restoring production;
- mounting `/var/run/docker.sock` into an application container;
- claiming point-in-time recovery from a daily full backup;
- using `php artisan migrate:rollback` against production;
- retaining any Redis DB 1 cache/session key after AOF import, or reusing recovery credentials against production;
- weakening P02 document retention, Object Lock, legal hold, encryption, or malware-scan controls;
- editing `Makefile` or workflows; P08 owns final integration.

## 5. Architecture and ownership boundaries

### 5.1 Coherent recovery set

A backup is valid only if its manifest binds these components to one `backup_id` and one UTC `consistency_cutoff`:

1. **MySQL:** `database.sql.zst`, `migrations.json`, row-count/watermark summary, `@@GLOBAL.gtid_executed`, current binary-log file/position when available, and SHA-256 values.
2. **Redis server persistence:** `redis-server.aof`, `redis-check-aof` result, P01 DB 0 stream/DLQ key and consumer-group/PEL inventory, DB 1 key count before capture, and SHA-256. The manifest records `scope=server_wide`; recovery loads the AOF while fenced, flushes DB 1, proves DB 1 has zero keys, and then validates the preserved DB 0 inventory.
3. **Documents quarantine:** every current object version referenced at cutoff, including bucket, opaque key digest, source version ID, ETag/checksum, length, KMS encryption metadata, retain-until/legal-hold metadata, and copied bytes.
4. **Documents available:** the same version-aware inventory; restore must preserve or extend retention and legal hold, never shorten either.
5. **Release context:** source commit, API/web image digests, migration batch/status, P01/P02 token evidence, and Compose configuration digest.

Application entry points and all P01 mutation workloads are fenced before capture. The sequence is: validate credentials and free space; stop `scheduler`, `caddy`, `web`, `api`, and `worker`; run `docker compose ... run --rm --no-deps worker /usr/local/bin/worker-loop run-once` until two consecutive zero-work cycles and both owner-unpublished and Redis `XPENDING` counts are zero; prove no application writer remains; invoke the recorded provider mechanism to export the server-wide Redis AOF; capture MySQL and both version inventories; record the cutoff; restart the unchanged release; then copy the exact recorded S3 version IDs and upload the assembled set to Restic. Failure before restart invokes the restart trap; failure after restart leaves the uncommitted staging set for secure deletion and never labels it valid.

### 5.2 Encryption and secret boundary

Restic provides authenticated client-side encryption. `RESTIC_PASSWORD_FILE` is a mode-0400 file outside the repository and Compose project. Backup-repository access uses dedicated credentials with write/list/read but no source-bucket rights. Restore uses separate read-only repository credentials plus drill-target-only database, Redis, and S3 credentials. Scripts begin with `umask 077`, reject secret files with mode broader than 0400/0600, never use `set -x`, redact endpoints to SHA-256 fingerprints in evidence, and use an operator-provided encrypted temporary filesystem. Secrets, PHI/PII, object keys, cookies, and document content never enter logs or evidence.

**Credential ownership and secret-manager boundary.** The production backup repository, the production restore repository, the production Redis AOF export/import wrapper, and the production credential-mounter process are owned by explicit named roles recorded in the P03 token grant (no implicit ownership): `backup_repository_credential_owner` (production Secrets Manager namespace `/cluster/backup/repo`, write/list/read for the production Restic S3 bucket only), `restore_repository_credential_owner` (production Secrets Manager namespace `/cluster/backup/restore`, read-only for the same Restic bucket, distinct IAM principal), `redis_aof_wrapper_owner` (production Secrets Manager namespace `/cluster/redis/aof`, owns the root-owned allow-listed `REDIS_AOF_EXPORT_EXECUTABLE` and `REDIS_AOF_IMPORT_EXECUTABLE` and the provider access credentials), and `credential_mounter_owner` (production Secrets Manager namespace `/cluster/backup/mounter`, owns the host-side secret-file mount that exposes the mode-0400 Restic password and the AWS access key files into the operations container). The secret-manager boundary is the production tenant's Secrets Manager: secrets never live in Git, in `.env`, in CI variables, in API/worker/scheduler runtime, in `run-local-e2e.sh`, in the operations container image, in `php artisan` invocations, or in any evidence file. The operations image never receives a Docker socket or a `docker`/`kubectl` client; the operations container receives only the named mode-0400 secret-file mounts and the four read-only script mounts listed in §6. Each role names one human approver and one delegated CI/admin role; the delegations form the named secret-manager boundary traced by the audit evidence.

**Least-privilege scopes.** The four roles carry only the documented scopes and are revoked outside them: `backup_repository_credential_owner` may not list, read, modify, or delete objects in `documents-quarantine` or `documents-available`, may not invoke the Redis AOF wrappers, and may not write to any path other than the encrypted Restic repository prefix; `restore_repository_credential_owner` may invoke `s3:ListBucket` and `s3:GetObject` (and `s3:GetObjectVersion` when versioning is enabled) on the Restic repository prefix only, plus `kms:Decrypt` on the Restic repository KMS key when the bucket is KMS-encrypted, and **must not** perform `s3:PutObject`, `s3:PutObjectAcl`, `s3:DeleteObject`, `s3:DeleteObjectVersion`, `s3:DeleteBucket`, `s3:PutBucketPolicy`, `s3:PutBucketLifecycle`, `s3:PutBucketObjectLockConfiguration`, `s3:BypassGovernanceRetention`, or any retention/legal-hold mutation on the Restic repository — this role is read-only by design so a leaked credential cannot destroy backups. It may not invoke the Redis AOF wrappers, may not list/read/write any production source bucket (`documents-quarantine`, `documents-available`), may not reach production database or production Redis endpoints, and may not reach the source application container image registry except the explicitly allow-listed immutable Restic repository prefix. `redis_aof_wrapper_owner` may only invoke the two documented entry points with the documented input/output environment variables and may not read production secret files; `credential_mounter_owner` may only mount the four listed secret-file paths into the operations container and may not read their contents. Drill-target credentials belong to the P03 isolated overlay only and never share an endpoint, database, Redis logical DB, bucket, volume, or IAM principal with the production roles. The P03 plan never composes these scopes: P07, P04, and the API runtime are not permitted to request them, and the credential mounter refuses any mount list outside the four allow-listed paths.

**Rotation, expiry, and audit evidence.** Each owned credential has a `rotation_period_days`, an `expiry_at` (UTC), a `last_rotated_at` (UTC), a `rotation_owner` (human role), an `incident_contact`, and a `rotation_audit_log_path` reconciled into the P03 evidence manifest. Rotation evidence covers the AWS IAM access-key last-used timestamp, the Secrets Manager read of the last rotation record, and a successful re-verification of the newest verified coherent snapshot after the rotation. Expired credentials fail closed: `backup.sh`, `verify-backup.sh`, `prune-backups.sh`, `restore.sh`, `recovery-drill.sh`, and `deploy-vps.sh` exit 76 when the expiry is past the run start time and the audit log is missing the current rotation record. The audit evidence is committed at `artifacts/p03-recovery/${RUN_ID}/credentials-audit.json` and contains each role's name, owner, rotation period, expiry, last-rotated timestamp, principal fingerprint, and SHA-256 of the audit-log entry; it never contains the credentials themselves. P03 never extends a credential past its declared expiry; P04 may impose a shorter period, never longer.

**Vendor-classification input for P04.** The P03 evidence manifest publishes a `vendors` block addressing exactly the fields P04 Task 7 Step 3 requires for every backup-restore data path: the backup repository (Restic-backed S3-compatible store), the Redis AOF export/import wrapper provider, the Redis persistence storage provider, and the operator host key custodian. Each entry carries `provider`, `legal_entity`, `service_or_region`, `data_categories` (the categories of Cluster data that flow through the path; e.g. encrypted MySQL dumps, encrypted Redis AOF, encrypted object bytes, KMS metadata), `purpose`, `subprocessors` (none declared when absent), `retention_or_deletion`, `incident_contact`, `agreement_or_baa_decision`, `approval_owner`, `approval_date`, `approval_expiry`, `restricted_evidence_sha256` (the SHA-256 of the immutable evidence file that records the agreement/decision), `backup_encryption_or_restore_proof_path` (the exact evidence path for the demonstrated encryption at rest and the demonstrated restore into the isolated target), and `declared_rpo_seconds` (the floor from §8, not a marketed number). P04 validates and references this block; P04 does not procure vendors, execute agreements, configure backups, or assert RPO. P03 never asserts that a vendor decision is "legal sufficiency"; P03 surfaces only the documented approval owner/date/expiry and the restricted evidence hash, and P04 rejects any active flow whose vendor is `unknown` or `blocked`.

### 5.3 Release compatibility

Every release has `/var/lib/cluster/releases/${RELEASE_ID}/release.json` with full image digests, commit, Compose digest, `schema_change` (`none`, `expand`, or `contract`), `minimum_compatible_api_release`, pre-release backup snapshot ID, migration before/after sets, operator/change record, and smoke results.

- `none` and reviewed `expand` releases may roll back application images without reversing migrations.
- `contract` releases require the prior application release to have been retired for one complete release window and set `rollback_allowed=false`; failures use roll-forward. The deploy script refuses a contract release whose manifest does not identify the earlier expand release and its evidence.
- Any unclassified/destructive SQL, failed migration, or mismatch between declared and observed migration sets fails closed before traffic resumes.
- A production data restore is a disaster-recovery action, not a routine deployment rollback.

## 6. Files to create, modify, move, or remove

### Create

- `infra/platform/production/backup.Dockerfile` — non-root operations image containing Bash, MySQL client, Redis tools, AWS CLI, Restic, jq, zstd, and coreutils; no Docker CLI/socket.
- `infra/platform/production/ops/recovery-common.sh` — strict argument/env parsing, fingerprinting, secret-mode checks, target/source inequality, evidence helpers, locks, and redaction.
- `infra/platform/production/ops/capture-backup.sh` — component capture inside the operations container.
- `infra/platform/production/ops/restore-components.sh` — integrity validation and component restore into an empty target.
- `infra/platform/production/backup.sh` — host write fence, operations-container invocation, restart trap, Restic snapshot finalization, and evidence output.
- `infra/platform/production/verify-backup.sh` — non-mutating Restic/component verification.
- `infra/platform/production/prune-backups.sh` — guarded retention selection and explicit deletion mode.
- `infra/platform/production/restore.sh` — host restore entry point; validation/drill by default and separately gated production mode.
- `infra/platform/production/recovery-drill.sh` — isolated target lifecycle, restore, smoke, timing, evidence, and cleanup.
- `infra/platform/production/rollback-vps.sh` — digest-only application rollback with schema compatibility refusal.
- `infra/platform/production/compose.recovery.yaml` — overlay that adds isolated `recovery-mysql`, `recovery-redis`, P02 MinIO recovery buckets, and no public production ports.
- `infra/platform/production/.env.recovery.example` — isolated names and non-secret file paths; it must not share any production endpoint, database, Redis DB, bucket, volume, or credential.
- `infra/platform/production/test-backup-restore-release.sh` — hermetic shell contract tests using fake tool binaries and disposable Compose project names.
- `scripts/validate-p03-recovery-evidence.py` — stdlib validator with deterministic errors and exit codes.
- `docs/operations/schemas/cluster-recovery-evidence.schema.json` — JSON Schema 2020-12 retained-evidence contract.
- `docs/operations/ha-dr-backup.md` — backup schedule, restore drill, production break-glass restore, RPO/RTO, escalation, and key-loss procedure.
- `docs/operations/release-rollback.md` — release, expand/contract, rollback refusal, roll-forward, and evidence runbook.

### Modify only after the named tokens are granted

- `infra/platform/production/compose.yaml` (`PROD-COMPOSE`) — add the digest-pinned operations image/profile, read-only script mounts, `SESSION_CONNECTION=cache`, and backup-specific read-only secret mounts; preserve the P01/P02 topology and service contracts.
- `infra/platform/production/.env.example` (`PROD-ENV`) — add `SESSION_CONNECTION=cache`, backup repository/secret-file/retention variables, immutable release inputs, and point-in-time capability flags; preserve P01/P02 names.
- `infra/platform/production/deploy-vps.sh` (`PROD-RELEASE`) — clean cutover from build-on-deploy to manifest-driven digest deployment with preflight, pre-migration backup, schema gate, migration evidence, health/smoke, and release-state recording.

### Move/remove

None. Existing production scripts remain at their current paths. No generated artifact is edited.

## 7. Public contracts, events, routes, schemas, and capabilities

P03 creates no application route, capability, module Contract/DTO, or domain Event. Existing API semantics—problem+json, correlation IDs, session/CSRF, authorization-before-disclosure, idempotency, ETag/`If-Match`, `lock_version`, cursor pagination, and transactional outbox—remain unchanged.

The public surface is operational CLI only:

```text
backup.sh --env-file FILE --evidence-dir DIR [--full-check]
verify-backup.sh --env-file FILE --snapshot SNAPSHOT_ID --evidence-dir DIR --read-data 5%|100%
prune-backups.sh --env-file FILE --evidence-dir DIR --dry-run
prune-backups.sh --env-file FILE --evidence-dir DIR --confirm-repository SHA256_FINGERPRINT --apply
restore.sh --env-file SOURCE_FILE --target-env TARGET_FILE --snapshot SNAPSHOT_ID --evidence-dir DIR --validate-only
restore.sh --env-file SOURCE_FILE --target-env TARGET_FILE --snapshot SNAPSHOT_ID --evidence-dir DIR --confirm-isolated-target cluster-p03-recovery
recovery-drill.sh --source-env FILE --drill-env FILE --evidence-dir DIR --confirm-isolated-target cluster-p03-recovery
deploy-vps.sh --env-file FILE --release-manifest FILE --evidence-dir DIR
rollback-vps.sh --env-file FILE --release-manifest FILE --evidence-dir DIR --confirm-release-id RELEASE_ID
```

`restore.sh` has no implicit production mode. Break-glass production restore additionally requires all of:

```text
--production
--incident-id INCIDENT_ID
--approval-record APPROVAL_RECORD
--confirm-production-target SHA256_TARGET_FINGERPRINT
ALLOW_PRODUCTION_RESTORE=1
```

It still aborts if source and target fingerprints match unexpectedly, the target is not fenced, the backup is unverified, two-person approval evidence is absent, or the restored objects would reduce a retain-until/legal-hold value.

`docs/operations/schemas/cluster-recovery-evidence.schema.json` requires:

```json
{
  "schema_version": 1,
  "run_id": "UTC timestamp plus random suffix",
  "operation": "backup|verify|restore_drill|release|rollback|roll_forward",
  "result": "pass|fail",
  "commit_sha": "40 hexadecimal characters",
  "backup_id": "UUIDv7 or null for a pre-backup release failure",
  "snapshot_id": "64 lowercase hexadecimal characters or null",
  "consistency_cutoff": "RFC3339 UTC timestamp",
  "source_fingerprint": "sha256 lowercase hexadecimal",
  "target_fingerprint": "sha256 lowercase hexadecimal or null",
  "image_digests": {"api": "name@sha256:digest", "web": "name@sha256:digest", "operations": "name@sha256:digest"},
  "components": {
    "mysql": {"sha256": "hex", "gtid_executed": "string", "binlog_file": "string or null", "binlog_position": "integer or null", "migration_count": "integer", "integrity": "pass"},
    "redis_aof": {"scope": "server_wide", "sha256": "hex", "stream_count": "integer", "consumer_group_count": "integer", "pending_count": "integer", "integrity": "pass"},
    "redis_db1": {"disposition": "flushed_after_aof_load", "post_restore_key_count": 0},
    "documents_quarantine": {"object_count": "integer", "total_bytes": "integer", "inventory_sha256": "hex", "integrity": "pass"},
    "documents_available": {"object_count": "integer", "total_bytes": "integer", "inventory_sha256": "hex", "retention_preserved": true, "integrity": "pass"}
  },
  "schema_compatibility": {"change": "none|expand|contract", "rollback_allowed": "boolean", "migration_before": ["string"], "migration_after": ["string"]},
  "rpo_floor_seconds": "non-negative integer (the initial RPO floor; MUST be 86400 in this plan)",
  "rto_floor_seconds": "non-negative integer (the initial RTO floor; MUST be 14400 in this plan)",
  "rpo_seconds": "non-negative integer (the measured RPO from the newest verified coherent full backup; satisfies rpo_seconds <= rpo_floor_seconds)",
  "rto_seconds": "non-negative integer (the measured RTO from the newest verified coherent full backup; satisfies rto_seconds <= rto_floor_seconds)",
  "pit_disclosure": {
    "point_in_time_capable": "boolean (canonical PIT gate; MUST be false in every initial manifest; equals true only when every conditions_evaluated[*].status is pass)",
    "point_in_time_recovery_supported": "boolean (P08-compatible alias; MUST equal point_in_time_capable field value; the validator enforces equality)",
    "recovery_window_start": "RFC3339 UTC timestamp or null when point_in_time_capable is false",
    "recovery_window_end": "RFC3339 UTC timestamp (the newest verified coherent full backup's consistency_cutoff when point_in_time_capable is false)",
    "restore_point": "RFC3339 UTC timestamp or null when point_in_time_capable is false",
    "unsupported_reason": "string or null (the documented string 'no continuous binlog shipping' when point_in_time_capable is false)",
    "conditions_evaluated": [
      {"id": "mysql_binlog_shipping", "status": "pass|fail|not_applicable", "evidence_path": "absolute path (not null when status is pass)", "evidence_sha256": "sha256 lowercase hex (not null when status is pass)", "cutoff": "RFC3339 UTC timestamp (the cutoff the drill was performed against)", "commit_sha": "40 lowercase hex (the commit the drill ran against)", "run_id": "UTC timestamp plus random suffix (the run that produced this evidence)"},
      {"id": "p02_object_version_retention", "status": "pass|fail|not_applicable", "evidence_path": "absolute path (not null when status is pass)", "evidence_sha256": "sha256 lowercase hex (not null when status is pass)", "cutoff": "RFC3339 UTC timestamp", "commit_sha": "40 lowercase hex", "run_id": "UTC timestamp plus random suffix"},
      {"id": "p01_redis_cutoff_recovery", "status": "pass|fail|not_applicable", "evidence_path": "absolute path (not null when status is pass)", "evidence_sha256": "sha256 lowercase hex (not null when status is pass)", "cutoff": "RFC3339 UTC timestamp", "commit_sha": "40 lowercase hex", "run_id": "UTC timestamp plus random suffix"},
      {"id": "synchronized_utc_drill", "status": "pass|fail|not_applicable", "evidence_path": "absolute path (not null when status is pass)", "evidence_sha256": "sha256 lowercase hex (not null when status is pass)", "cutoff": "RFC3339 UTC timestamp", "commit_sha": "40 lowercase hex", "run_id": "UTC timestamp plus random suffix"}
    ],
    "p08_alias_mapping": {
      "point_in_time_capable_to_point_in_time_recovery_supported": "identity",
      "rpo_floor_seconds_to_p08_rpo_floor": "identity",
      "rto_floor_seconds_to_p08_rto_floor": "identity",
      "consistency_check": "point_in_time_capable == point_in_time_recovery_supported in every evidence manifest"
    },
    "disclosure_match_runbook": "boolean (true only when the runbook's mandated fixed literals match the keys and fixed strings in evidence.json; no per-run values from the runbook)",
    "disclosure_match_p08_handoff": "boolean (true only when the descriptor's pit_disclosure block byte-equals evidence.json's pit_disclosure block)"
  },
  "credentials": {
    "backup_repository_credential_owner": {"role": "string", "secrets_manager_namespace": "string", "iam_principal_fingerprint": "sha256 lowercase hex", "rotation_period_days": "non-negative integer", "last_rotated_at": "RFC3339 UTC", "expiry_at": "RFC3339 UTC", "audit_log_path": "string", "audit_log_sha256": "sha256 lowercase hex"},
    "restore_repository_credential_owner": {"role": "string", "secrets_manager_namespace": "string", "iam_principal_fingerprint": "sha256 lowercase hex", "rotation_period_days": "non-negative integer", "last_rotated_at": "RFC3339 UTC", "expiry_at": "RFC3339 UTC", "audit_log_path": "string", "audit_log_sha256": "sha256 lowercase hex"},
    "redis_aof_wrapper_owner": {"role": "string", "secrets_manager_namespace": "string", "iam_principal_fingerprint": "sha256 lowercase hex", "rotation_period_days": "non-negative integer", "last_rotated_at": "RFC3339 UTC", "expiry_at": "RFC3339 UTC", "audit_log_path": "string", "audit_log_sha256": "sha256 lowercase hex"},
    "credential_mounter_owner": {"role": "string", "secrets_manager_namespace": "string", "iam_principal_fingerprint": "sha256 lowercase hex", "rotation_period_days": "non-negative integer", "last_rotated_at": "RFC3339 UTC", "expiry_at": "RFC3339 UTC", "audit_log_path": "string", "audit_log_sha256": "sha256 lowercase hex"}
  },
  "vendors": [
    {"path": "database", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "redis", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "s3_kms", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "clamav", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "backup_repository", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "backup_encryption_or_restore_proof_path": "string", "declared_rpo_seconds": "non-negative integer", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "restore_repository", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "backup_encryption_or_restore_proof_path": "string", "declared_rpo_seconds": "non-negative integer", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "redis_aof_wrapper", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "backup_encryption_or_restore_proof_path": "string", "declared_rpo_seconds": "non-negative integer", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "redis_persistence_storage", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "backup_encryption_or_restore_proof_path": "string", "declared_rpo_seconds": "non-negative integer", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "operator_host_key_custodian", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "backup_encryption_or_restore_proof_path": "string", "declared_rpo_seconds": "non-negative integer", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "mail", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "error_monitoring", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "technical_log_archive", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "support_tools", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"},
    {"path": "llm_analytics", "provider": "string", "legal_entity": "string", "service_or_region": "string", "data_categories": ["string"], "purpose": "string", "subprocessors": ["string"], "retention_or_deletion": "string", "incident_contact": "string", "agreement_or_baa_decision": "non_phi_only|approved_for_declared_scope|blocked", "approval_owner": "string", "approval_date": "RFC3339 UTC", "approval_expiry": "RFC3339 UTC", "restricted_evidence_sha256": "sha256 lowercase hex", "active_flow_state": "non_phi_only|approved_for_declared_scope|blocked", "evidence_owner": "string (human role)", "vendor_classification_evidence_path": "string"}
  ],
  "vendor_p04_inventory": {
    "paths": ["database", "redis", "s3_kms", "clamav", "backup_repository", "restore_repository", "redis_aof_wrapper", "redis_persistence_storage", "operator_host_key_custodian", "mail", "error_monitoring", "technical_log_archive", "support_tools", "llm_analytics"],
    "p03_assembles_full_inventory": true,
    "p03_credential_ownership_paths": ["backup_repository", "restore_repository", "redis_aof_wrapper", "operator_host_key_custodian"],
    "p04_relationship": "P04 consumes the P03-assembled inventory as input and validates the classification fields and active-flow states; P04 does not redundantly fill the same fields. P03 owns the assembly so there is no UP-stream dependency on P04 to mark P04-owned paths before P03 records the manifest."
  },
  "smoke": {"api": "pass|fail", "worker": "pass|fail", "scheduler": "pass|fail", "outbox": "pass|fail", "documents": "pass|fail", "release": "pass|fail", "rollback": "pass|fail"},
  "commands": [{"name": "allow-listed command name", "exit_code": "integer", "started_at": "RFC3339 UTC", "finished_at": "RFC3339 UTC"}],
  "approvals": [{"role": "operator|reviewer", "record": "opaque reference"}],
  "handoff": {
    "p08_handoff_descriptor_path": "absolute path under artifacts/p03-recovery/<run-id>/handoff/p08-handoff.json",
    "p08_consumed_fields": [
      {"field": "rpo_floor_seconds", "value": "non-negative integer", "source": "evidence.json::rpo_floor_seconds"},
      {"field": "rto_floor_seconds", "value": "non-negative integer", "source": "evidence.json::rto_floor_seconds"},
      {"field": "rpo_seconds", "value": "non-negative integer", "source": "evidence.json::rpo_seconds"},
      {"field": "rto_seconds", "value": "non-negative integer", "source": "evidence.json::rto_seconds"},
      {"field": "pit_disclosure", "value": "object", "source": "evidence.json::pit_disclosure"},
      {"field": "credentials", "value": "object", "source": "evidence.json::credentials"},
      {"field": "vendors", "value": "array", "source": "evidence.json::vendors"}
    ],
    "p08_owned_dossier": "docs/architecture/PROGRAM-CLOSURE.md (rendered by P08 from the descriptor; P03 does not hash this file back into its evidence)"
  }
}
```

The handoff is **strictly one-way and digest-stable**. The capture writes `artifacts/p03-recovery/${RUN_ID}/evidence.json` first, hashes it to `evidence.sha256`, then writes `artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.json` whose `source_evidence` block carries `evidence.json` path/digest plus the `p08_consumed_fields` list above. `evidence.json` records only the deterministic `p08_handoff_descriptor_path`; it **must not** contain a `p08_handoff_descriptor_sha256` because the descriptor does not exist when `evidence.json` is finalized. P08 reads the descriptor by exact path and SHA-256 (recorded against the sibling `p08-handoff.sha256`) and is the sole owner of `docs/architecture/PROGRAM-CLOSURE.md`. P03 never hashes `docs/architecture/PROGRAM-CLOSURE.md` back into its evidence, never recomputes a digest over a P08-rendered file, and never includes a `program_closure_dossier_*` field in the P03 schema. The closure-gates-expansion `G09` row remains the authoritative cross-check: P08 re-extracts `rpo_seconds`, `rto_seconds`, `point_in_time_capable`, `point_in_time_recovery_supported`, `recovery_window`, `restore_point`, and PIT explanations from the P03 evidence manifest and renders them into its owned dossier, without any P03 reference to the rendered file. The validator verifies (a) the descriptor exists at the recorded path, (b) the descriptor's `source_evidence.path`/`source_evidence.sha256` point at `evidence.json`/`evidence.sha256`, and (c) every `p08_consumed_fields[*].source` references a field present in `evidence.json`; it does not require a digest back from the descriptor into `evidence.json`.

The schema sets `additionalProperties: false` recursively where the plan owns the object, forbids secret-like field names, requires all component checks to pass when `result=pass`, and **mandatorily requires** `pit_disclosure.point_in_time_capable=false` (the canonical gate; `pit_disclosure.point_in_time_recovery_supported` MUST equal `point_in_time_capable` per the `p08_alias_mapping.consistency_check`) with `unsupported_reason="no continuous binlog shipping"`, `recovery_window_end` equal to the newest verified coherent full backup's `consistency_cutoff`, and named `rpo_floor_seconds=86400` / `rto_floor_seconds=14400` until every one of the four §8 PIT conditions is independently evidenced on the requested UTC cutoff. The manifest must also publish the full P04 vendor inventory (`vendors` carrying the documented 14 paths plus `vendor_p04_inventory` declaring `p03_assembles_full_inventory=true` and `p03_credential_ownership_paths`); P03 owns the assembly of the full 14-path inventory from operator and vendor-classification inputs, with a named `evidence_owner` (human role) and `vendor_classification_evidence_path` recorded for every entry. A manifest whose `pit_disclosure` is missing, whose `recovery_window_end` is later than the newest verified coherent full backup's `consistency_cutoff`, whose `disclosure_match_runbook` or `disclosure_match_p08_handoff` is `false`, whose `point_in_time_capable` and `point_in_time_recovery_supported` disagree, whose `rpo_floor_seconds` is not `86400`, whose `rto_floor_seconds` is not `14400`, whose `credentials` block is missing one of the four named roles, whose `vendors` block omits any of the 14 P04 paths, whose vendor entry lacks `evidence_owner` or `vendor_classification_evidence_path`, whose vendor's `active_flow_state` is `blocked` or `unknown` for an active flow, or whose `handoff` block omits `p08_handoff_descriptor_path` is invalid; the validator exits non-zero and the manifest is rejected. The earliest valid `point_in_time_capable=true` (and therefore `point_in_time_recovery_supported=true`) requires every `conditions_evaluated[*].status` to be `pass` with a non-empty `evidence_path` and an exact-cutoff drill whose three stores share one `restore_point`.



## 8. Database, indexes, migration order, and recovery assumptions

P03 creates no application table, index, constraint, foreign key, or Laravel migration. It does not edit `apps/api/config/module_migrations.php`. The backup contains the complete MySQL schema/data and the ordered `migrations` ledger; restoration must compare that ledger with the restored API image's `php artisan migrate:status` before health checks.

The capture command uses MySQL 8.4 client options equivalent to:

```bash
mysqldump --single-transaction --quick --routines --events --triggers --hex-blob \
  --set-gtid-purged=COMMENTED --source-data=2 --no-tablespaces "$DB_DATABASE" \
  | zstd -T0 -19 > database.sql.zst
```

The database backup user is read-only and receives only the documented MySQL dump/metadata privileges. Restore credentials belong only to the positively identified target. Restore creates an empty database, imports the dump, runs `mysqlcheck`, compares table/row/outbox watermarks, then runs `php artisan migrate:status`; it never applies new migrations until compatibility is explicitly evaluated.

### Point-in-time disclosure contract (mandatory)

P03 is **mandatorily PIT-incapable** in every initial release. The guaranteed baseline is the newest verified coherent full backup at `artifacts/p03-recovery/${RUN_ID}/evidence.json`, scheduled at least every 24 hours. The initial measurable RPO floor is `rpo_floor_seconds=86400` and the initial measurable RTO floor is `rto_floor_seconds=14400`. The initial evidence manifest must declare `pit_disclosure.point_in_time_capable=false` (the canonical gate; `point_in_time_recovery_supported` MUST equal `point_in_time_capable`), `recovery_window_end=<consistency_cutoff of the newest verified coherent full backup>`, `restore_point=null`, and `unsupported_reason="no continuous binlog shipping"`. The runbook (`docs/operations/ha-dr-backup.md`) is checked-in infrastructure that mandates the fixed field names, the initial fixed values, the four mandatory future PIT conditions, and the operator command that reads the live evidence — it does not embed per-run values. The P08 handoff descriptor (`artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.json`) repeats the same `pit_disclosure` block byte-for-byte, with `source_evidence.path`/`source_evidence.sha256` pointing at `evidence.json`/`evidence.sha256`. P08 owns `docs/architecture/PROGRAM-CLOSURE.md` and renders the PIT/credential/vendor fields from the descriptor; P03 publishes no P03-owned `program-closure-dossier.*` artifact. The full P04 vendor inventory (14 paths) is published in `evidence.json::vendors` with `vendor_p04_inventory.p03_assembles_full_inventory=true`; P03 owns the assembly from operator and vendor-classification inputs, with a named `evidence_owner` and `vendor_classification_evidence_path` per entry. The validator rejects any manifest whose `pit_disclosure` block is missing, whose `recovery_window_end` is later than the newest verified coherent full backup's `consistency_cutoff`, whose `disclosure_match_runbook` or `disclosure_match_p08_handoff` is `false`, whose `point_in_time_capable` and `point_in_time_recovery_supported` disagree, whose `rpo_floor_seconds` is not `86400`, whose `rto_floor_seconds` is not `14400`, or whose `unsupported_reason` deviates from the documented string. `disclosure_match_runbook` is `true` only when the runbook's mandated fixed literals match the keys and fixed strings in `evidence.json` (no per-run values); `disclosure_match_p08_handoff` is `true` only when the descriptor's `pit_disclosure` block byte-equals `evidence.json`'s `pit_disclosure` block. Operators **must not infer PIT support** from tooling, schedules, managed-database snapshots, provider claims, or vendor documentation; only the evidenced `pit_disclosure` block is authoritative.

### Four mandatory future PIT conditions

`point_in_time_capable` may be true only when **all four** of the following conditions are independently evidenced on the requested UTC cutoff by retained evidence at `artifacts/p03-recovery/${RUN_ID}/evidence.json` and recorded in `pit_disclosure.conditions_evaluated[*]` with the exact `evidence_path`, `evidence_sha256`, `cutoff`, `commit_sha`, and `run_id`:

1. **MySQL GTID/binlog shipping.** MySQL GTID/binlogs are continuously copied to an independently durable repository, retained at least 7 days, and a timestamp-bounded `restore_point` can be selected from the binlog without data loss. The exact evidence is `artifacts/pit-evidence/mysql_binlog_shipping/<commit_sha>/<cutoff>/<run_id>.json` with its `evidence_sha256` recorded in `pit_disclosure.conditions_evaluated[0]`; the evidence file is the immutable shipping-drill artifact that names the same `commit_sha` and `cutoff` and lists the binlog retention window and timestamp-bounded restore-points selected without data loss.
2. **P02 object-version retention.** P02 versioning retains every referenced object version through that cutoff, including deleted markers, KMS metadata, Object Lock, and legal holds, and a `restore_point` can be selected from the bucket inventory without loss. The exact evidence is `artifacts/pit-evidence/p02_object_version_retention/<commit_sha>/<cutoff>/<run_id>.json` with its `evidence_sha256` recorded in `pit_disclosure.conditions_evaluated[1]`; the evidence file is the immutable object-version-retention drill artifact that names the same `commit_sha` and `cutoff` and lists every referenced object version, deleted markers, KMS metadata, Object Lock, and legal hold preserved.
3. **P01 Redis cutoff recovery.** P01 durable transport recovery can select state at the same cutoff, or MySQL outbox/inbox evidence proves deterministic idempotent reconstruction without message loss at the same `restore_point`. The exact evidence is `artifacts/pit-evidence/p01_redis_cutoff_recovery/<commit_sha>/<cutoff>/<run_id>.json` with its `evidence_sha256` recorded in `pit_disclosure.conditions_evaluated[2]`; the evidence file is the immutable Redis-cutoff drill artifact that names the same `commit_sha` and `cutoff` and proves the same `restore_point`.
4. **Synchronized UTC drill.** Every component uses synchronized UTC with maximum observed skew ≤ 5 seconds, and a single drill restores all three stores (MySQL, both document buckets, Redis AOF) to the same `restore_point` with verified component integrity. The exact evidence is `artifacts/pit-evidence/synchronized_utc_drill/<commit_sha>/<cutoff>/<run_id>.json` with its `evidence_sha256` recorded in `pit_disclosure.conditions_evaluated[3]`; the evidence file is the immutable cross-store drill artifact that names the same `commit_sha` and `cutoff` and proves the three stores share one `restore_point`.

All four conditions must be `pass` simultaneously with full `evidence_path` + `evidence_sha256` + `cutoff` + `commit_sha` + `run_id` populated. The validator hashes each referenced `evidence_path` and rejects any report whose on-disk SHA-256 disagrees with `evidence_sha256`, whose `commit_sha` does not match `evidence.json::commit_sha`, or whose `cutoff` does not match the requested UTC cutoff. A drill that fails any condition returns the manifest to `point_in_time_capable=false`. Managed-database snapshots, provider claims, vendor documentation, or operator assertions are **not sufficient** without the cross-store drill. Any stricter value than `rpo_floor_seconds=86400` or `rto_floor_seconds=14400` must be earned by retained evidence; the manifest records the stricter value only when both the measured `rpo_seconds` and the measured `rto_seconds` are below the documented floor.


### PIT disclosure record in the runbook, P08 handoff, and PROGRAM-CLOSURE dossier

The operator runbook (`docs/operations/ha-dr-backup.md`) is checked-in infrastructure and **does not embed per-run values**. It mandates the fixed field names, the initial fixed values, and the four mandatory future PIT conditions, and it ships the operator command that reads the live evidence. Run-scoped byte equality applies only between two run-scoped artifacts: the P03 evidence manifest (`artifacts/p03-recovery/${RUN_ID}/evidence.json`) and the P08 handoff descriptor (`artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.json`), which is finalized after `evidence.json` and carries `source_evidence.path`/`source_evidence.sha256` pointing at it. P08 reads the descriptor by exact path/digest and is the sole owner of `docs/architecture/PROGRAM-CLOSURE.md`; P03 never hashes the P08-rendered dossier back into its evidence. The `disclosure_match_p08_handoff` flag is set `true` only when the descriptor's `pit_disclosure` block equals `evidence.json`'s `pit_disclosure` block byte-for-byte (the only run-scoped byte-equality check). The `disclosure_match_runbook` flag is set `true` only when the runbook's mandated field names and initial fixed values — `point_in_time_recovery_supported=false`, `unsupported_reason="no continuous binlog shipping"`, and the four-condition rubric — match the keys and fixed literals in `evidence.json`; it is **not** a byte-equality check and never includes the run-specific `recovery_window_end`, `restore_point`, or any SHA-256. P03 publishes no P03-owned `program-closure-dossier.*` artifact; PROGRAM-CLOSURE fields (RPO/RTO/PIT/credential/vendor) reach the dossier only through the P08 handoff descriptor, and P08 commits those fields into its owned render file.

Rollback/recovery order is: fence application writers; restore MySQL; restore both document buckets and verify version/retention mapping; import the server-wide Redis AOF through the recorded provider mechanism; immediately run authenticated `redis-cli -n \"$REDIS_CACHE_DB\" FLUSHDB` and prove DB 1 has zero keys; start the exact restored API image; confirm migration compatibility; start P01 worker, then scheduler, then web/Caddy; prove the preserved DB 0 stream/group/PEL/DLQ inventory matches and owner-unpublished plus `XPENDING` counts are zero; run smoke; release the fence. No global `published_at` reset, broad DB 0 `XACK`/`XDEL`/`DEL`, or DLQ purge is permitted.

## 9. TDD implementation tasks

### Task 1: Lock the evidence and safety contracts

**Files:** create `docs/operations/schemas/cluster-recovery-evidence.schema.json`, `scripts/validate-p03-recovery-evidence.py`, and the initial `infra/platform/production/test-backup-restore-release.sh`.

**Produces:** `validate-p03-recovery-evidence.py MANIFEST`, exit 0 only for the §7 contract; shell fixtures that prove secrets, skipped checks, source/target equality, invalid image tags, and missing component evidence fail.

- [ ] **Step 1: Write failing fixtures first.** Add a minimal valid fixture and separate invalid fixtures for `result=pass` with a failed smoke, a secret-like key, equal source/target fingerprints, a mutable image tag, Redis AOF not marked server-wide, Redis DB 1 nonzero after restore, and available-object retention not preserved. Additional invalid fixtures must cover: missing `pit_disclosure` block, `point_in_time_recovery_supported=true` without four `pass` conditions, `disclosure_match_runbook=false`, `disclosure_match_p08_handoff=false`, `recovery_window_end` later than the newest verified coherent full backup's `consistency_cutoff`, `unsupported_reason` deviating from the documented string, missing one of the four named credential roles, a credential role with `expiry_at` in the past, missing one of the four `vendors` paths, missing `handoff.p08_handoff_descriptor_path`, evidence.json containing a `p08_handoff_descriptor_sha256` (mutual-hash cycle), and a P08 handoff descriptor whose `source_evidence.sha256` does not match the actual `evidence.sha256`.
- [ ] **Step 2: Run the red gate.** Run `bash infra/platform/production/test-backup-restore-release.sh --case evidence`. Expected: non-zero with `validator missing`; no Docker resource is created.
- [ ] **Step 3: Implement the schema and stdlib validator.** Parse JSON strictly; reject duplicate keys, unknown fields, non-UTC timestamps, non-digest images, secret-like names, invalid component hashes, a passing result with any failed/skipped check, and restore evidence whose target equals source. **Reject** any evidence missing the `pit_disclosure`, `credentials`, `vendors`, or `handoff` blocks; reject `pit_disclosure.point_in_time_recovery_supported=true` unless every `conditions_evaluated[*].status` is `pass` with a non-empty `evidence_path`; reject `pit_disclosure.recovery_window_end` later than the newest verified coherent full backup's `consistency_cutoff`; reject `pit_disclosure.unsupported_reason` deviating from the documented string; reject any credential role whose `expiry_at` is past the run start time; reject any `vendors` block missing one of the four required paths or whose `agreement_or_baa_decision` is `unknown`/`blocked`; reject any `handoff` block missing `p08_handoff_descriptor_path`; reject any evidence that contains `p08_handoff_descriptor_sha256`, `program_closure_dossier_path`, `program_closure_dossier_sha256`, or any other field that hashes the descriptor or dossier back into evidence (mutual-hash cycle). The validator must additionally verify (a) the descriptor exists at the recorded path, (b) the descriptor's `source_evidence.path`/`source_evidence.sha256` point at `evidence.json`/`evidence.sha256`, and (c) every `p08_consumed_fields[*].source` references a field present in `evidence.json`. The validator never reads `docs/architecture/PROGRAM-CLOSURE.md` and never recomputes a digest over a P08-rendered file.
- [ ] **Step 4: Run green and mutation checks.** Run the same command. Expected: `PASS: P03 evidence contract and redaction rules`; every invalid fixture is observed failing and the valid fixture exits 0.

### Task 2: Build the least-privileged operations tool image

**Files:** create `infra/platform/production/backup.Dockerfile`, `infra/platform/production/ops/recovery-common.sh`, and extend the shell contract test.

**Produces:** a non-root `cluster-backup-ops` image with fixed tool versions and no application code, Docker client, socket, shell history, or embedded credentials.

- [ ] **Step 1: Add image tests before the Dockerfile.** Assert UID is non-zero; `mysqldump`, `redis-cli`, `redis-check-aof`, `aws`, `restic`, `jq`, `zstd`, and `sha256sum` exist; `/var/run/docker.sock` and `/var/www/html` do not; secret-file mode 0644 is rejected.
- [ ] **Step 2: Run red.** Run `bash infra/platform/production/test-backup-restore-release.sh --case image`. Expected: non-zero because `infra/platform/production/backup.Dockerfile` is absent.
- [ ] **Step 3: Implement the image and common library.** Pin the base image by digest, install only the listed clients, copy P03 ops scripts read-only, create UID/GID 10002, set `USER 10002:10002`, and default to a help/usage command that exits 2. `recovery-common.sh` must expose `require_file_mode`, `sha256_fingerprint`, `require_distinct_targets`, `acquire_lock`, `emit_command_evidence`, and `redact_and_fail`.
- [ ] **Step 4: Run green.** Build `cluster-backup-ops:p03-test`, run the image case, and inspect it. Expected: all assertions pass and the test prints the immutable operations-image digest.

### Task 3: Implement coherent encrypted backup, verification, and retention

**Files:** create `ops/capture-backup.sh`, `backup.sh`, `verify-backup.sh`, and `prune-backups.sh`; extend contract tests.

**Produces:** one Restic snapshot tagged `cluster-coherent`, `backup-id:${BACKUP_ID}`, and `cutoff:${CONSISTENCY_CUTOFF}`, only after all component captures and checks pass.

- [ ] **Step 1: Add red contract tests.** Fake Compose/MySQL/Redis-provider/S3/Restic binaries must prove: services restart after capture failure; a second backup lock exits 75; a server-wide AOF is exported only after the drain; exact S3 version IDs are fetched; available-object retention/legal hold cannot be reduced; Restic failure creates no passing evidence; prune defaults to dry-run and preserves the newest two verified coherent snapshots.
- [ ] **Step 2: Run red.** `bash infra/platform/production/test-backup-restore-release.sh --case backup`. Expected: non-zero with missing backup entry point.
- [ ] **Step 3: Implement capture.** Use `set -Eeuo pipefail`, `umask 077`, a host `flock`, a secure staging mount, and an EXIT trap that restarts only services stopped by this invocation. Stop the five P01 services and execute the exact `worker-loop run-once` drain until two zero cycles plus zero unpublished/`XPENDING` counts; invoke the recorded provider AOF export; capture MySQL and both version inventories; record cutoff; restart the same release; fetch exact object versions; calculate hashes; write the manifest; then invoke Restic. A snapshot is valid only after `redis-check-aof`, `restic check --read-data-subset=5%`, and evidence validation pass. The capture must additionally emit `artifacts/p03-recovery/${RUN_ID}/credentials-audit.json` (the four named credential roles, their `rotation_period_days`, `last_rotated_at`, `expiry_at`, `rotation_owner`, `incident_contact`, `rotation_audit_log_path`, and the SHA-256 of the audit-log entry), `artifacts/p03-recovery/${RUN_ID}/pit-disclosure.json` (the §8 disclosure block mirroring `evidence.json` with `point_in_time_recovery_supported=false`, `recovery_window_end=<consistency_cutoff>`, `unsupported_reason="no continuous binlog shipping"`, and the four `conditions_evaluated` rows), `artifacts/p03-recovery/${RUN_ID}/vendors.json` (the four-path vendor-classification block), and — only after `evidence.json` is finalized and hashed to `evidence.sha256` — `artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.json` (which carries the `source_evidence.path`/`source_evidence.sha256` referencing `evidence.json`/`evidence.sha256`, plus the `p08_consumed_fields` list of RPO/RTO/PIT/credential/vendor source pointers). Each emitted file is followed by a sibling `<name>.sha256` file containing its lowercase hex SHA-256. P03 publishes **no** `program-closure-dossier.*` artifact; P08 owns `docs/architecture/PROGRAM-CLOSURE.md` and reads the descriptor by exact path/digest to render those fields.
- [ ] **Step 4: Implement verification.** `verify-backup.sh` verifies repository authenticity, restores into encrypted temporary storage, runs Restic check at the requested percentage, recalculates all hashes, validates SQL decompression, runs `redis-check-aof`, validates stream/PEL/DLQ inventory and every S3 inventory entry/retention field, and emits independent evidence.
- [ ] **Step 5: Implement retention.** Dry-run prints the exact keep/delete snapshot IDs for `--keep-daily 14 --keep-weekly 8 --keep-monthly 12`. Apply mode additionally requires the repository fingerprint, a fresh successful full verification, and preserves the newest two verified snapshots plus every legal/incident hold tag. P04 may lengthen this policy; P03 never shortens a later compliance policy.
- [ ] **Step 6: Run green.** Run backup contract tests. Expected: `PASS: coherent backup, verification, restart trap, and retention guards` with zero live Docker resources.

### Task 4: Implement destructive-safe restore

**Files:** create `ops/restore-components.sh`, `restore.sh`, and extend contract tests.

**Produces:** validation-only restore by default and a component restore that can target only a separately credentialed, empty, fingerprinted destination.

- [ ] **Step 1: Add red safety cases.** Cover missing confirmation, equal endpoint/database/bucket fingerprints, production-like DNS names in drill mode, nonempty target, invalid Restic/AOF check, unknown snapshot, missing P02 object version, shorter restored retention, failure to flush Redis DB 1 after server-wide AOF import, absent two-person production approvals, and interruption halfway through object restore.
- [ ] **Step 2: Run red.** `bash infra/platform/production/test-backup-restore-release.sh --case restore`. Expected: non-zero because restore scripts are absent.
- [ ] **Step 3: Implement validation and restore.** Validate the snapshot before connecting to targets. Require target names to start with `cluster-p03-recovery`, compare SHA-256 fingerprints of DB/Redis/S3 endpoints and resource names, require empty targets, write a restore lease, import MySQL, restore object bytes/metadata with a source-version to target-version map, import the server-wide Redis AOF through the recorded provider mechanism, immediately flush DB 1, and prove it has zero keys before any application service starts. Leave all services fenced until DB 0 stream/group/PEL/DLQ integrity passes. On interruption, retain failed evidence and the isolated target for inspection; never retry a non-idempotent object operation without comparing expected checksum and metadata.
- [ ] **Step 4: Implement break-glass refusal.** Production mode must require the five §7 controls, a verified snapshot within declared RPO, an incident-scoped evidence directory, and a typed target fingerprint. It must print the data-loss cutoff and wait for a second operator record before the first mutation.
- [ ] **Step 5: Run green.** Run restore contract tests. Expected: `PASS: restore defaults to validation and destructive controls fail closed`; every negative case proves zero source mutation.

### Task 5: Build the isolated recovery drill and smoke exercises

**Files:** create `compose.recovery.yaml`, `.env.recovery.example`, `recovery-drill.sh`, and extend tests.

**Produces:** one disposable Compose project whose resource names are prefixed `cluster-p03-recovery`, with no production endpoints or public ports.

- [ ] **Step 1: Add overlay/drill red tests.** Compose config must contain isolated MySQL, Redis, and the P02 MinIO bootstrap contract; use distinct volumes/buckets/credentials; bind any diagnostic port to `127.0.0.1`; and refuse an existing project not created by the current run lease.
- [ ] **Step 2: Run red.** `bash infra/platform/production/test-backup-restore-release.sh --case drill`. Expected: non-zero with missing recovery overlay.
- [ ] **Step 3: Implement the drill.** Create the isolated target; call `restore.sh`; launch restored `migrate` in status-only mode first; start API, P01 workers/scheduler, web, and Caddy from restored image digests; execute `/up`, authenticated organization read, one idempotent mutation with stale-write rejection, outbox-to-Redis-to-inbox delivery, clean document upload/scan/promote/download, quarantine/infected refusal, worker restart, scheduler heartbeat, and available-object retention checks. Use synthetic non-PHI fixtures only.
- [ ] **Step 4: Measure recovery.** Compute `rpo_seconds = drill_started_at - consistency_cutoff` and `rto_seconds = final_smoke_passed_at - drill_started_at`; fail if either exceeds 86,400 or 14,400 respectively. Record checks rather than skipping unavailable integrations.
- [ ] **Step 5: Run the local green drill.** Use a newly created backup from the isolated source fixture. Expected: validated evidence at `artifacts/p03-recovery/${RUN_ID}/evidence.json`, all smoke fields `pass`, and cleanup removes only lease-owned recovery resources.

### Task 6: Replace build-on-deploy with manifest-driven release and safe rollback

**Files:** modify `deploy-vps.sh`; create `rollback-vps.sh`; extend tests.

**Produces:** digest-only deployment and application rollback that never reverses production migrations.

- [ ] **Step 1: Add release red cases.** Reject mutable tags, unknown schema change, contract release without prior expand evidence, missing pre-release verified backup, observed migration drift, health failure without retained logs, rollback across incompatible schema, and rollback to an unrecorded digest.
- [ ] **Step 2: Run red.** `bash infra/platform/production/test-backup-restore-release.sh --case release`. Expected: failures against current build-on-deploy behavior.
- [ ] **Step 3: Implement deploy preflight.** Validate release JSON/evidence, require full registry digests, run Compose config, inspect image platform/users/health, verify the previous release, invoke `backup.sh --full-check`, record migration status/pretend output, and classify every observed migration against the manifest before stopping traffic.
- [ ] **Step 4: Implement rollout.** Remove `--build`; pull exact digests; run the one-shot migration; start API/worker/scheduler, then web/Caddy; require all P01/P02 health checks and public HTTPS `/up`; run one read and one non-destructive document-object check; atomically switch `current`/`previous` release records only after green.
- [ ] **Step 5: Implement rollback/roll-forward.** `rollback-vps.sh` verifies `rollback_allowed=true` and that current schema is accepted by the prior API, restores prior image digests without any migration down, reruns health/smoke, and records evidence. If incompatible, exit 78 with `ROLL_FORWARD_REQUIRED`, leave the database untouched, and direct the operator to the reviewed hotfix release flow.
- [ ] **Step 6: Run green.** Run release contract tests. Expected: `PASS: digest rollout, schema gate, rollback refusal, and roll-forward path`.

### Task 7: Integrate the final production-topology token

**Files:** modify `compose.yaml` and `.env.example` only after `PROD-COMPOSE`/`PROD-ENV` grants; extend tests.

**Produces:** one existing topology with an opt-in operations profile, P01/P02 services preserved, Redis session/cache isolation, and no application Docker socket.

- [ ] **Step 1: Add token-aware red assertions.** Require P01/P02 service definitions and health checks unchanged, `SESSION_CONNECTION=cache`, operations image by digest, read-only secret/script mounts, `profiles: [operations]`, no published ports, no Docker socket, no source bucket credential reuse for backup repository, and all required variables.
- [ ] **Step 2: Run red against the granted integrated branch.** `bash infra/platform/production/test-backup-restore-release.sh --case topology`. Expected: only P03 assertions fail; P01/P02 assertions pass.
- [ ] **Step 3: Apply the narrow merge.** Rebase or revoke a stale token rather than hand-resolving ownership. Add only the P03 profile/environment/mounts; do not rewrite P01/P02 sections. `.env.example` must document `RESTIC_REPOSITORY`, `RESTIC_PASSWORD_FILE`, backup-repository credential files, retention values 14/8/12, `SESSION_CONNECTION=cache`, root-owned allow-listed `REDIS_AOF_EXPORT_EXECUTABLE` and `REDIS_AOF_IMPORT_EXECUTABLE` paths, release manifest location, and `POINT_IN_TIME_CAPABLE=false`. Each Redis wrapper accepts its input/output path only through a P03-set environment variable, refuses user-supplied argv/shell text, and records the provider snapshot identifier. `.env.example` must additionally document the four named credential roles via `P03_BACKUP_REPOSITORY_ROLE`, `P03_RESTORE_REPOSITORY_ROLE`, `P03_REDIS_AOF_WRAPPER_ROLE`, `P03_CREDENTIAL_MOUNTER_ROLE` (each pointing to the documented Secrets Manager namespace and IAM principal fingerprint), the `P03_PIT_DISCLOSURE` block (the `point_in_time_recovery_supported=false`, `recovery_window_end`, `restore_point=null`, `unsupported_reason` triple), the `P03_VENDORS` block (the four-path vendor classification input for P04), and the `P03_HANDBINDING` listing `P03_P08_HANDOFF_PATH`, `P03_P08_HANDOFF_SHA256`, `P03_PROGRAM_CLOSURE_DOSSIER_PATH`, and `P03_PROGRAM_CLOSURE_DOSSIER_SHA256` (the binding digests of the two exact handoff artifacts). The capture script must refuse to start if any of these is missing, syntactically invalid, or refers to a path that does not exist with the recorded digest.
- [ ] **Step 4: Validate Compose and contracts.** Run the topology case and `docker compose --env-file infra/platform/production/.env.recovery --file infra/platform/production/compose.yaml --file infra/platform/production/compose.recovery.yaml config --quiet`. Expected: exit 0, one topology, no missing variable, and no production endpoint in the recovery service configuration.

### Task 8: Write runbooks and complete the real isolated rehearsal

**Files:** create `docs/operations/ha-dr-backup.md` and `docs/operations/release-rollback.md`; all implementation artifacts participate.

**Produces:** operator procedures with prerequisites, exact commands, expected evidence, abort/escalation paths, and no unsafe shorthand.

- [ ] **Step 1: Write the runbooks.** Include backup schedule, failed-backup alert, key/repository loss, verification cadence, retention dry-run/apply, quarterly restore drill, source/target fingerprinting, break-glass production restore, session invalidation, outbox reconciliation, document Object Lock/legal-hold preservation, RPO/RTO interpretation, expand/contract rollout, rollback refusal, roll-forward, and incident evidence retention. `docs/operations/ha-dr-backup.md` is checked-in infrastructure and **must not embed per-run values** (no run-specific `recovery_window_end`, `restore_point`, or SHA-256 in the runbook); it must open with a **mandatory PIT disclosure block** that mandates the fixed field names, the initial fixed values, the four mandatory future PIT conditions, and the operator command that reads the live evidence. The block must therefore contain exactly the fixed strings `point_in_time_recovery_supported=false`, `unsupported_reason="no continuous binlog shipping"`, `rpo_floor_seconds=86400`, `rto_floor_seconds=14400`, the four-condition rubric (`mysql_binlog_shipping`, `p02_object_version_retention`, `p01_redis_cutoff_recovery`, `synchronized_utc_drill`), and the operator command — `jq -r '.pit_disclosure' artifacts/p03-recovery/${RUN_ID}/evidence.json` plus a `sha256sum -c evidence.sha256 p08-handoff.sha256` verification — that reads the live manifest and verifies the descriptors. The runbook must forbid any operator inference of PIT support from tooling, schedules, or vendor documentation. The runbook must reference the four named credential roles, their secret-manager namespaces, their rotation owners/periods/expiries, and the audit-log paths; reference the four-path vendor-classification block; and require the §13 exact path/digest record as the only entry point to disaster recovery (no discovery path, no "latest artifact" selection, no runbook-stamped digests). The runbook must not reference any P03-owned `program-closure-dossier.*` artifact. P08 owns `docs/architecture/PROGRAM-CLOSURE.md` and renders the PIT/credential/vendor fields from the descriptor.
- [ ] **Step 2: Run the non-destructive contract suite.** `bash infra/platform/production/test-backup-restore-release.sh`. Expected: every case passes, no skipped case, no live disposable resource.
- [ ] **Step 3: Run the real isolated recovery/release rehearsal.** Execute:

```bash
infra/platform/production/recovery-drill.sh \
  --source-env infra/platform/production/.env.production \
  --drill-env infra/platform/production/.env.recovery \
  --evidence-dir artifacts/p03-recovery \
  --confirm-isolated-target cluster-p03-recovery
```

Expected: exit 0; newest `artifacts/p03-recovery/${RUN_ID}/evidence.json` validates; all integrity/smoke fields pass; RPO ≤ 86,400 seconds; RTO ≤ 14,400 seconds; release and rollback image digests are recorded; source resources are unchanged. The validator must additionally confirm: the descriptor's `pit_disclosure` block equals `evidence.json`'s `pit_disclosure` block byte-for-byte (the only run-scoped byte-equality check); the four named credential roles each carry rotation/expiry/audit evidence that is within the declared `expiry_at`; the four-path `vendors` block is present and well-formed; the `handoff` block carries the deterministic `p08_handoff_descriptor_path` (no digest back from the descriptor); the descriptor's `source_evidence.path`/`source_evidence.sha256` point at `evidence.json`/`evidence.sha256`; the runbook's mandated field names and fixed literals match `evidence.json`'s keys and fixed strings (no run-specific values inside the runbook, so no per-run edits are required); and `credentials-audit.json`, `pit-disclosure.json`, `vendors.json`, and `handoff/p08-handoff.json` (plus `evidence.sha256` and `p08-handoff.sha256`) exist next to `evidence.json` with matching SHA-256 hashes. P03 publishes no `program-closure-dossier.*` artifact; P08 owns `docs/architecture/PROGRAM-CLOSURE.md` and is the sole consumer of the descriptor.

- [ ] **Step 4: Record the authorization checkpoint.** Present the diff, token ledger, evidence manifest, and remaining point-in-time limitation. Record a commit only if the user explicitly authorizes it; otherwise leave `implementation_commit` and `last_verified_commit` null and the status cannot become `completed`.

## 10. Failure, retry, idempotency, concurrency, and authorization behavior

- **Failure:** any failed/missing/skipped component makes the whole backup or drill fail. Partial staging data never receives a valid snapshot tag. Services stopped by P03 are restarted by an idempotent trap using the pre-run service set.
- **Retry:** backup retries receive a new `backup_id`; Restic deduplication avoids duplicate storage. Restore retries reuse a restore lease only after comparing component checksums and target state. Release retries use the same immutable release manifest and refuse changed digests.
- **Idempotency:** component uploads compare expected SHA-256, size, KMS, retention, and legal-hold metadata before accepting an existing target object. Release state changes through atomic rename. Re-running rollback to the already active digest returns success with `no_change=true` evidence.
- **Concurrency:** host `flock` files serialize backup, restore, release, rollback, and prune. Restore/release locks conflict with every other mutation; verification may run concurrently only on an immutable snapshot. Compose project leases prevent one drill from deleting another.
- **Authorization:** host access, secret-file access, repository policy, incident/change records, and two-person production restore approval are external operational controls. HTTP capabilities do not authorize shell restore. Scripts never weaken application capability checks.
- **Outbox/Redis:** use P01's exact stop and `worker-loop run-once` sequence until two zero-work cycles and both owner-unpublished and `XPENDING` counts are zero. MySQL outbox/inbox watermarks and Redis stream/group/PEL/DLQ state are compared before and after recovery. A mismatch blocks traffic. Never perform a global `published_at` reset or broad `XACK`, `XDEL`, `DEL`, or DLQ purge; replay only exact event IDs through an owner-approved path and preserve the CloudEvent ID.
- **Documents:** exact source version IDs are captured. Missing versions, checksum mismatch, KMS mismatch, shortened retention, cleared legal hold, or unavailable malware scanner blocks recovery.
- **Logs:** errors carry a run/correlation ID but redact secrets, user data, object keys, SQL rows, and document bytes.

## 11. Targeted verification commands

Future executors run these commands; this drafting task runs none of them.

| Command | Expected outcome |
|---|---|
| `bash infra/platform/production/test-backup-restore-release.sh --case evidence` | Valid evidence passes; every malformed/redaction fixture fails for its expected reason. |
| `bash infra/platform/production/test-backup-restore-release.sh --case image` | Non-root operations image contains only required clients and no secrets/socket/app. |
| `bash infra/platform/production/test-backup-restore-release.sh --case backup` | Coherent capture order, restart trap, encryption/integrity, and retention guards pass with fakes. |
| `bash infra/platform/production/test-backup-restore-release.sh --case restore` | Destructive controls reject every unsafe target and valid isolated restore is idempotent. |
| `bash infra/platform/production/test-backup-restore-release.sh --case drill` | Recovery overlay is isolated and lease cleanup is scoped. |
| `bash infra/platform/production/test-backup-restore-release.sh --case release` | Digest rollout, expand/contract gate, rollback, and roll-forward behavior pass. |
| `bash infra/platform/production/test-backup-restore-release.sh --case topology` | P01/P02 contracts remain intact and P03 profile/env pass. |
| `bash infra/platform/production/test-backup-restore-release.sh` | All cases pass; zero skipped checks and zero leaked resources. |
| `python3 scripts/validate-p03-recovery-evidence.py artifacts/p03-recovery/${RUN_ID}/evidence.json` | Exit 0 and `PASS: P03 recovery evidence is complete and redacted`. The validator confirms the runbook's mandated field names and fixed literals match `evidence.json` keys and fixed strings (no per-run values from the runbook); the descriptor's `pit_disclosure` block equals `evidence.json`'s `pit_disclosure` block byte-for-byte; the four named credential roles each carry rotation/expiry/audit evidence and are within `expiry_at`; the four-path `vendors` block is present and well-formed; and the `handoff` block carries the deterministic `p08_handoff_descriptor_path` (no digest back from the descriptor). |
| `python3 scripts/validate-p03-recovery-evidence.py artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.json` | Exit 0; descriptor's `pit_disclosure` block equals `evidence.json`'s `pit_disclosure` block byte-for-byte; descriptor's `source_evidence.path`/`source_evidence.sha256` point at `evidence.json`/`evidence.sha256`; `p08_handoff_descriptor_path` resolves to the descriptor's path. |
| `python3 scripts/validate-p03-recovery-evidence.py artifacts/p03-recovery/${RUN_ID}/pit-disclosure.json` | Exit 0; `point_in_time_recovery_supported=false`, `recovery_window_end` equals the newest verified coherent full backup's `consistency_cutoff`, `unsupported_reason="no continuous binlog shipping"`, and every `conditions_evaluated` row is present. |
| `python3 scripts/validate-p03-recovery-evidence.py artifacts/p03-recovery/${RUN_ID}/credentials-audit.json` | Exit 0; the four named credential roles are present with rotation/expiry/audit evidence; no role's `expiry_at` is past the run start time. |
| `python3 scripts/validate-p03-recovery-evidence.py artifacts/p03-recovery/${RUN_ID}/vendors.json` | Exit 0; the four required paths (`backup_repository`, `redis_aof_wrapper`, `redis_persistence_storage`, `operator_host_key_custodian`) are present with provider/legal_entity/service_or_region/data_categories/purpose/subprocessors/retention/incident_contact/agreement decision/approval owner/date/expiry/restricted evidence hash/demonstrated encryption or restore proof/declared RPO. |
| `infra/platform/production/verify-backup.sh --env-file infra/platform/production/.env.production --snapshot "$SNAPSHOT_ID" --evidence-dir artifacts/p03-recovery --read-data 100%` | Full authenticated read and every component hash/inventory check pass. |
| Task 8 recovery-drill command | Full isolated restore, release, rollback, API/workload/document smoke, and RPO/RTO pass. |

No full project formatter, linter, build, or test suite substitutes for these checks. P08 may call them from the singular closure gate only after its exclusive CI/Make token.

## 12. Shared-file integration-token requirements

1. Record `P01` and `P02` completion evidence and the exact integrated base commit.
2. Request `PROD-COMPOSE`, `PROD-ENV`, and `PROD-RELEASE`; each grant records releasing owner, base commit, surfaces, evidence path, and expiry.
3. Before shared edits, prove the base includes P01's worker/scheduler/drain contract and P02's final `verify-documents-runtime.sh` result.
4. If the base commit changes, revoke the token, rebase, rerun P01/P02 targeted gates, and request a new grant.
5. Release tokens only after §11 targeted checks pass on the integrated branch.
6. Do not touch the current architecture-closure reservations, module queues, API/OpenAPI/Orval/web-shell queues, `apps/api/Dockerfile`, worker/scheduler scripts, or console routes.
7. P07 production execution remains blocked until P03 reaches `completed` with a current evidence manifest. P08 owns final Make/workflow wiring. The P03 handoff to P08 is an exact path/digest record: `artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.json` plus its sibling SHA-256 file `p08-handoff.sha256` (the digest is the on-disk SHA-256 of the descriptor, recorded only in the descriptor's sibling file — never back into `evidence.json`). The descriptor's `source_evidence` block carries the `evidence.json` path/digest and the `p08_consumed_fields` list. The P08 handoff descriptor carries the same `pit_disclosure` block as the P03 evidence manifest, the four named credential roles with their rotation/expiry/audit evidence, the full 14-path P04 vendor inventory (P03-assembled), and the exact recovery manifest path/digest. P03 publishes no P03-owned `program-closure-dossier.*` artifact; P08 owns `docs/architecture/PROGRAM-CLOSURE.md` and renders the PIT/credential/vendor fields from the descriptor. The P03 token release to P08 is blocked unless (a) `p08_handoff_descriptor_path` resolves and the descriptor's `source_evidence.path`/`source_evidence.sha256` point at `evidence.json`/`evidence.sha256`, (b) the `pit_disclosure` block is present and equals the runbook's fixed literals, (c) the four named credential roles each carry rotation/expiry/audit evidence with `expiry_at` in the future, (d) the `vendors` block lists exactly the 14 required paths with `evidence_owner` and `vendor_classification_evidence_path` per entry, and (e) the `handoff` block's `p08_handoff_descriptor_path` resolves to the descriptor's path.

## 13. Rollback procedure

### Implementation rollback before deployment

Remove only P03-created files, revert only P03 hunks in `compose.yaml`, `.env.example`, and `deploy-vps.sh`, and return all three tokens as revoked/released. Do not revert P01/P02 content. Re-run P01/P02 topology checks to prove their state remains intact.

### Failed release before migration

Keep the current release running, mark the candidate failed, retain logs/evidence, and do not change `current`/`previous` links. No data restore occurs.

### Failed release after an `expand` migration

Run `rollback-vps.sh` to restore previous API/web digests while leaving the expanded schema in place. Verify prior API compatibility, workers/scheduler, outbox, and documents. If the prior API cannot accept the expanded schema, the script refuses and the operator ships a roll-forward fix.

The `SESSION_CONNECTION=cache` cutover is not transparent: the first P03 release logs out all active users, and reverting that environment variable logs them out again. Deploy preflight and operator communication must state this explicitly; rollback evidence records that session invalidation occurred and must not claim uninterrupted sessions.

### Failed `contract` release

Application rollback is prohibited. Fence traffic and workloads, preserve the database, and roll forward with a reviewed digest-pinned hotfix. A backup restore is considered only for disaster recovery with an accepted loss window and §7 break-glass controls.

### Disaster recovery rollback

Disaster recovery is **never** a discovery operation. The operator supplies the exact recovery manifest path and SHA-256 digest to the runbook before any restore begins:

```text
recovery_manifest_path=artifacts/p03-recovery/<RUN_ID>/evidence.json
recovery_manifest_sha256=<64 lowercase hex digits>
snapshot_id=<64 lowercase hex digits>
backup_id=<UUIDv7>
consistency_cutoff=<RFC3339 UTC>
pit_disclosure_sha256=<64 lowercase hex digits>
p08_handoff_descriptor_path=artifacts/p03-recovery/<RUN_ID>/handoff/p08-handoff.json
p08_handoff_descriptor_sha256=<64 lowercase hex digits (read from the sibling p08-handoff.sha256 file)>
```

`restore.sh` rejects the invocation if any of these is missing, syntactically invalid, refers to a path that does not exist, or whose on-disk SHA-256 disagrees with the supplied digest. There is no "latest artifact" discovery path, no time-based "newest verified coherent snapshot" selection, no implicit recovery from a directory listing, and no platform-side SCAN. The recovery manifest must already bind one `snapshot_id`, one `consistency_cutoff`, one `pit_disclosure` block, and one `handoff` block. The exact path/digest record is the only entry point; a runbook that assumes discovery is invalid. After resolving the exact manifest, the operator follows the production section of `docs/operations/ha-dr-backup.md`: fence writers, re-confirm the target fingerprint and cutoff, restore in §8 order, import the server-wide Redis AOF, flush DB 1 before any application process starts, reconcile DB 0 outbox/streams, smoke, and only then redirect traffic. Never run migration down or overwrite the sole production copy in place; the exact path/digest record is appended to the §7 incident evidence path before the first mutation.

## 14. Exit criteria and required retained evidence

P03 can enter `completed` only when all are true on one recorded commit:

- P01/P02 are completed and all P03 tokens are merged/released with exact evidence;
- operations image is non-root, digest-recorded, and contains no secrets, Docker socket/client, or app code;
- daily encrypted Restic backup succeeds and a 100% verification succeeds;
- newest two verified snapshots survive retention logic; deletion dry-run/apply behavior is proven without deleting held evidence;
- MySQL, the server-wide Redis AOF, both P02 buckets, migration ledger, outbox watermarks, version/retention/legal-hold metadata, and image digests share one coherent manifest;
- Redis DB 0 Streams/groups/PELs/DLQs survive AOF import; DB 1 is flushed before application startup and has zero recovered session/cache keys;
- unsafe restore cases demonstrably make zero source mutations;
- a full isolated recovery drill passes API, worker, scheduler, outbox, document, release, rollback, and cleanup checks;
- measured RPO ≤ 86,400 seconds and RTO ≤ 14,400 seconds;
- point-in-time is **mandatorily** recorded as unavailable: the evidence manifest, the operator runbook (`docs/operations/ha-dr-backup.md`), the P08 handoff, and the PROGRAM-CLOSURE dossier each carry `pit_disclosure.point_in_time_recovery_supported=false`, `recovery_window_end` equal to the newest verified coherent full backup's `consistency_cutoff`, `restore_point=null`, `unsupported_reason="no continuous binlog shipping"`, and `disclosure_match_runbook=disclosure_match_p08_handoff=true`. Each of the four mandatory future PIT conditions is recorded in `pit_disclosure.conditions_evaluated` with a status and an evidence path; the manifest is rejected if any of those fields is missing or disagrees with the runbook or P08 handoff;
- immutable release and safe rollback/roll-forward paths pass; production migration down is absent;
- backup / restore repository credential ownership, secret-manager boundary, least-privilege scopes, rotation/expiry/audit evidence, and the P04 vendor-classification block are all present in the evidence manifest: the four named credential roles (`backup_repository_credential_owner`, `restore_repository_credential_owner`, `redis_aof_wrapper_owner`, `credential_mounter_owner`) each carry `rotation_period_days`, `last_rotated_at`, `expiry_at`, `audit_log_path`, and `audit_log_sha256`; the `vendors` block lists exactly the four paths (`backup_repository`, `redis_aof_wrapper`, `redis_persistence_storage`, `operator_host_key_custodian`) with the operator-supplied provider/legal_entity/service_or_region/data_categories/purpose/subprocessors/retention/incident_contact/agreement decision/approval owner/date/expiry/restricted evidence hash demonstrated encryption/restore proof/declared RPO. P04 validates and references this block; P03 does not assert legal sufficiency, vendor procurement, or RPO tightening beyond evidenced drill;
- both runbooks have exact commands, abort criteria, escalation, and evidence locations;
- no secret, PHI/PII, object key, SQL row, session, or document content appears in retained evidence;
- no critical verification is missing or skipped.

Retain:

```text
artifacts/p03-recovery/source-inventory.json
artifacts/p03-recovery/${RUN_ID}/evidence.json
artifacts/p03-recovery/${RUN_ID}/commands.jsonl
artifacts/p03-recovery/${RUN_ID}/compose-config.sha256
artifacts/p03-recovery/${RUN_ID}/release-before.json
artifacts/p03-recovery/${RUN_ID}/release-after.json
artifacts/p03-recovery/${RUN_ID}/migration-before.txt
artifacts/p03-recovery/${RUN_ID}/migration-after.txt
artifacts/p03-recovery/${RUN_ID}/object-version-map.json
artifacts/p03-recovery/${RUN_ID}/redacted-smoke.log
artifacts/p03-recovery/${RUN_ID}/credentials-audit.json
artifacts/p03-recovery/${RUN_ID}/credentials-audit.sha256
artifacts/p03-recovery/${RUN_ID}/pit-disclosure.json
artifacts/p03-recovery/${RUN_ID}/pit-disclosure.sha256
artifacts/p03-recovery/${RUN_ID}/vendors.json
artifacts/p03-recovery/${RUN_ID}/vendors.sha256
artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.json
artifacts/p03-recovery/${RUN_ID}/handoff/p08-handoff.sha256
```

The manifest references the Restic snapshot ID, component hashes, and only the deterministic `handoff.p08_handoff_descriptor_path`. After `evidence.json` is finalized and hashed, `p08-handoff.json` records `source_evidence.path` and `source_evidence.sha256`; its sibling `p08-handoff.sha256` records the descriptor digest. P03 never creates or hashes a PROGRAM-CLOSURE dossier artifact. P08 later renders `docs/architecture/PROGRAM-CLOSURE.md` from the descriptor. Encrypted backup payloads remain in the repository, not in Git artifacts. Evidence is invalid if its commit differs from `last_verified_commit`, its image digest differs from the drill, any retained P03 file is absent, the manifest and P08 descriptor disagree, the runbook's mandated field names/fixed literals disagree with the manifest, a credential role or vendor path is missing, or the validator reports redaction/contract failure.


## 15. Status transition rules

- `blocked → ready`: orchestration records P01 and P02 `completed`; all three P03 tokens are granted on one clean base commit; required credentials, isolated recovery capacity, and explicit executor/worktree are recorded.
- `ready → in_progress`: implementation begins and base commit plus token leases are written to the execution record.
- `in_progress → blocked`: any dependency regression, stale/revoked token, missing P01 drain/stream contract, missing P02 version/Object Lock evidence, unavailable isolated target, credential/key failure, or authorization boundary prevents the next required step. Record blocker, owner, and last safe commit.
- `in_progress → verification`: implementation and serialized topology integration are complete; no application fake/stub, production Docker socket, build-on-deploy, unsafe restore default, or unresolved credential overlap remains.
- `verification → completed`: every §14 criterion passes on one user-authorized recorded commit, evidence paths resolve, full isolated drill is current, tokens are released, and orchestration is updated.
- `verification → blocked`: any failed, skipped, stale, redacted, over-budget, or cross-commit critical evidence returns the plan to `blocked` with the exact failing gate.
- any status `→ superseded`: requires a later user-approved plan, updated orchestration/dependencies/token ownership, replacement path, and migration of P07's dependency.

Planning completion does not alter the initial `blocked` status and does not authorize execution.