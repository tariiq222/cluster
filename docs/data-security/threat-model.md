---
doc_id: SEC-AU-002
title: Threat and Trust Model
type: data-security
status: draft
version: 0.2.0
date: 2026-07-15
owner: Information Security Officer
reviewers:
- Platform Engineering Office
- Operations Officer
classification: internal
review_cycle: semi-annual
sources: []
references:
- docs/architecture/c4-and-flows.md
- docs/architecture/module-catalog.md
- docs/adr/004-authorization-and-isolation.md
- docs/adr/018-air-gapped-supply-chain.md
- docs/adr/019-kubernetes-resilience-and-recovery.md
- docs/data-security/logical-data-model.md
- docs/data-security/identity-session-security.md
- docs/data-security/audit-and-privacy.md
- docs/data-security/file-security.md
---

# Threat and Trust Model

> **Planned controls.** The STRIDE boundaries, NCA/PDPL/NDMO mappings, and named guard tests in this document are a draft threat model and a future control/test inventory. Most of the named tests and the break-glass, audit, and export infrastructure do not exist in the verified code today. They are acceptable here only as requirements, not as deployed controls. The implemented authorization boundary is backend-controlled through `IdentitySessionMiddleware` and the Authorization provider guard; this section is the source of truth for that boundary.

## 1. Purpose and Scope

This document defines the threat model for the administrative platform of the Third Health Cluster following the STRIDE methodology, ties each threat to a control and an executable test, and maps to the requirements of:

- **Personal Data Protection Law (PDPL)** and its implementing regulations.
- **NCA Essential Cybersecurity Controls (NCA ECC)** in the locally adopted version.
- **National Data Management Office (NDMO)** standards for data classification and lifecycle management.

### 1.1 Scope Boundaries

In scope:

- The administrative platform, its backend services, and its data.
- Functional identity data of employees and administrative business data.
- Infrastructure inside the cluster data center (Kubernetes, MySQL, Object Storage, Queue, Search).
- Administration channels and break-glass emergency channels.
- Audit channels and the daily export channel.

Out of scope:

- Clinical patient data, the medical record, and HIS/EMR systems.
- "Mawared" systems, the financial system, and procurement.
- External integrations in phase one.
- Employee email and personal data outside the platform.

### 1.2 Nature of Data

The platform is non-clinical. It processes only:

- Functional identity data (employee PII): name, national id, employer, position, work email, work phone.
- Administrative business data: requests, tasks, administrative documents, administrative contracts, projects, institutional risks, KPIs, committees, administrative correspondence.
- Classification, authorization, role, and supervisory-relationship data.
- Audit logs and access logs.

### 1.3 Threat Assumptions

- The internal network is treated as fully untrusted and any device on it is treated as suspect.
- Some users have privileges and may abuse them.
- An insider or external attacker may attempt to compromise the administration layer or the emergency channels.
- An administrator may attempt to tamper with the audit log.
- Physical loss or theft of a device may occur.

## 2. STRIDE Methodology

Threats are classified into six categories:

| Category | Code | Meaning | Primary Impact |
|---|---|---|---|
| Spoofing | S | Impersonation of a user, service, or node | Bypass of access boundaries |
| Tampering | T | Unauthorized modification of data, code, or configuration | Loss of data integrity |
| Repudiation | R | User denies an action, or the system denies an event | Loss of accountability |
| Information Disclosure | I | Information exposed to unauthorized parties | Confidentiality breach |
| Denial of Service | D | Legitimate users prevented from accessing the service | Business disruption |
| Elevation of Privilege | E | Acquiring more capability than granted | Authorization boundary breach |

## 3. Trust Boundaries

| ID | Boundary Name | What it Separates | Allowed Crossing Pattern |
|---|---|---|---|
| TB-1 | User Browser ↔ Internal Network | Employee browser on its own network and the platform VLAN | Internal HTTPS only with optional mTLS for sensitive services |
| TB-2 | Internal Network ↔ Web/API | Employee device and the platform's internal gateway and application tier | HTTPS with short-lived session and CSRF token |
| TB-3 | Web/API ↔ Database | API services and MySQL | Closed internal network, DB account with least privilege, internal TLS |
| TB-4 | Web/API ↔ Object Storage | API services and file storage | Per-prefix service account with scoped permissions, signed requests, internal network |
| TB-5 | Worker ↔ Database | Worker and database | Same as TB-3 with account separation |
| TB-6 | Worker ↔ Object Storage | Worker and storage | Same as TB-4 with a separate account |
| TB-7 | Administration Channel (Super-admin) | Administration UI and dedicated account | Separate VLAN, expanded privileges with audit |
| TB-8 | Break-glass Emergency Channel | Locked emergency account and activation procedure | Two-person rule with documentation and recording |
| TB-9 | Backup and Recovery Channel | Production and backup store | Separate account, encryption, signature, physical separation |
| TB-10 | Daily Audit Export Channel | Production and the separate audit store | Digital signature, read-only account, physical separation |
| TB-11 | CI/CD and Build Channel | GitHub Actions and source repository | Read-only permissions, pinned actions, secret and dependency scanning |
| TB-12 | Egress Channel | Application containers and the public internet | Least egress with firewall and connection review |

## 4. STRIDE Matrix Across Trust Boundaries

### 4.1 TB-1 Browser ↔ Internal Network

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Browser impersonation by session theft | Bind session to internal IP and re-bind on IP change; bind session to a lightweight fingerprint | `IdentitySessionTest::session_invalidated_on_ip_change` |
| S | Reuse of stolen token | **Identity uses server-side opaque session cookies via `IdentitySessionMiddleware`. There are no JWT tokens or refresh tokens.** Session theft requires possession of the opaque cookie and is invalidated through revoke-on-password-change and server-side expiry. | `IdentitySessionTest::stolen_session_cookie_rejected_after_revoke` |
| T | Content injection via XSS | Strict CSP, backend input sanitization, HTML escaping in React | `SecurityTest::csp_blocks_inline_scripts` |
| I | Information disclosure via browser cache | `Cache-Control: no-store` on sensitive content | `HttpHeaderTest::sensitive_responses_have_no_store` |
| D | Login flooding | Rate limit on `/auth/login` and `/auth/password` | `IdentitySessionTest::login_rate_limit_enforced` |
| E | Authentication elevation via local-variable manipulation | Authorization decided only on the backend, no reliance on JS | `AuthorizationTest::client_hints_ignored_on_server` |

### 4.2 TB-2 Internal Network ↔ Web/API

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Forged request via CSRF | CSRF token on every mutation, `SameSite=Lax` on cookies | `HttpTest::csrf_token_required_on_mutations` |
| T | Request modification in transit | Internal TLS, HSTS headers, optional HMAC signing for sensitive events | `HttpTest::tls_required_on_internal_endpoints` |
| R | Action repudiation | Audit record precedes the response for every sensitive action | `AuditTest::audit_written_before_response` |
| I | Disclosure via detailed error messages | Generic error messages, internal details only | `HttpTest::error_messages_redacted_in_response` |
| D | API flooding | Per-user and per-IP rate limit, temporary blocklist | `RateLimitTest::per_user_and_per_ip_enforced` |
| E | Ungranted capability call | Centralized capability decision before each controller | `AuthorizationTest::capability_check_before_controller` |

### 4.3 TB-3 Web/API ↔ Database

| Category | Threat | Control | Test |
|---|---|---|---|
| S | DB account impersonation | Separate DB account per service with least privilege, password rotation | `DbRoleTest::api_role_lacks_drop_and_alter` |
| T | Modification of other modules' tables | DB user with grants only on its own schema, architectural test | `BoundaryTest::module_cannot_query_other_module_tables` |
| R | Write repudiation in tables | Every sensitive write writes to Outbox + Audit in the same transaction | `OutboxTest::event_written_in_same_transaction` |
| I | Reading whole tables via reports | Read Model allowed; manual joins across modules prohibited | `BoundaryTest::cross_module_join_via_readmodel_only` |
| D | Connection exhaustion | Bounded connection pool, circuit breaker on replica outage | `ResilienceTest::pool_does_not_exhaust_on_db_outage` |
| E | Injected SQL | Prepared statements only, ORM only, raw-query scan | `SecurityTest::raw_sql_blocked_in_module_code` |

### 4.4 TB-4 Web/API ↔ Object Storage

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Forged download request | Signed URL with short TTL (≤ 5 minutes) | `DocumentsTest::presigned_url_short_ttl` |
| T | File modification after upload | File in quarantine until checks pass; checksum computed at intake, no modification allowed | `DocumentsTest::storage_object_immutable_after_quarantine` |
| R | Download repudiation | `document_outbox_events` access event emitted before the URL is issued | `DocumentsTest::access_event_before_url_issued` |
| I | File leakage via URL share | URL scoped to the authenticated user only; authorization on every GET | `DocumentsTest::url_does_not_bypass_authorization` |
| D | Storage exhaustion | Per-`OrgUnit` quota; upload rejected on overflow | `DocumentsTest::quota_enforced_per_orgunit` |
| E | File read via visible capability | Field and document policy applied on every download | `DocumentsTest::download_respects_classification` |

### 4.5 TB-5 and TB-6 Worker and Storage

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Worker impersonation | Dedicated service account per worker, keys in internal Secret | `WorkerTest::worker_uses_distinct_service_account` |
| T | Job tampering | Idempotency key on every job, safe restart | `JobTest::duplicate_job_is_idempotent` |
| R | Execution repudiation | Execution log per job linked to `event_id` | `OutboxTest::job_records_event_id` |
| I | Sensitive content in logs | PII filter in logs, no full payloads | `LoggingTest::pii_redacted_in_worker_logs` |
| D | Stuck-job accumulation | Dead-letter queue, super-admin alert, bounded retry | `QueueTest::failed_job_lands_in_dlq` |
| E | Worker reaches a wider namespace | `NetworkPolicy` confines the worker to DB, Object Storage, and Cache | `NetPolTest::worker_egress_limited` |

### 4.6 TB-7 Administration Channel (Super-admin)

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Administrative account impersonation | MFA mandatory for super-admin, account separated from ordinary users | `AdminTest::mfa_required_for_superadmin` |
| T | Critical-config modification without review | Dual approval on critical changes, before/after recording | `AdminTest::dual_control_on_critical_changes` |
| R | Administrative action repudiation | Full request and response recorded in audit | `AdminTest::superadmin_actions_fully_logged` |
| I | Sensitive content read via admin privilege | Sensitive read recorded, separation of read and admin where feasible | `AuditTest::sensitive_view_by_admin_logged` |
| D | Account lockout due to admin error | Second-factor confirmation before disabling accounts or raising privilege | `AdminTest::disabling_account_requires_second_factor` |
| E | Self-promotion via vulnerability | Privilege separation review, monthly guard tests | `AuthorizationTest::superadmin_cannot_self_grant_sensitive_caps` |

### 4.7 TB-8 Break-glass Emergency Channel

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Break-glass activation with stolen identity | Account locked by default, activation requires two authorized persons | `BreakGlassTest::activation_requires_two_authorized_people` |
| T | Data modification via break-glass without control | Session ≤ 60 minutes, every action recorded in a separate audit | `BreakGlassTest::session_max_60_minutes` |
| R | Emergency-use repudiation | Pre-signed procedure, automatic report after use | `BreakGlassTest::usage_produces_signed_incident_report` |
| I | Exploiting emergency for sensitive read | Emergency capability restricted to an allow-list, no broad read | `BreakGlassTest::breakglass_capabilities_are_denylisted_others` |
| D | Using emergency to disable service | Blocklist of actions entirely forbidden in break-glass | `BreakGlassTest::denied_actions_blocked` |
| E | Converting emergency into permanent privilege | Auto-expiry, post-use review | `BreakGlassTest::grant_auto_expires` |

### 4.8 TB-9 Backup Channel

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Backup identity forgery | Separate account in the backup store, distinct keys, IP allowlist | `BackupTest::backup_account_separate_from_app` |
| T | Backup modification | At-rest encryption, signature, no historical mutation | `BackupTest::backup_is_signed_and_immutable` |
| R | Restore repudiation | Record per restore with approver and executor | `BackupTest::restore_recorded_with_dual_signoff` |
| I | Backup data disclosure | Encryption with a key distinct from production, physical separation | `BackupTest::backup_uses_distinct_kms_key` |
| D | Inability to restore | Quarterly restore test, RPO/RTO documentation | `DrTest::restore_meets_rpo_15_min` |
| E | Reading sensitive data via backup | Restore in an isolated network, delete backup after verification | `DrTest::restore_env_is_isolated` |

### 4.9 TB-10 Daily Audit Export Channel

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Forged export request | Read-only account, two-factor authentication | `AuditExportTest::export_requires_mfa` |
| T | Export modification after creation | Digital signature on the bundle, external hash publication | `AuditExportTest::export_signed_and_hash_published` |
| R | Export creation repudiation | Lifecycle events recorded (start, end, failure) | `AuditExportTest::lifecycle_logged` |
| I | Export content disclosure | Transfer over a separate channel, encryption, separation from production | `AuditExportTest::transfer_over_separate_channel` |
| D | Export delay | Daily schedule, alert on failure, bounded retry | `AuditExportTest::failure_alerts_within_15_min` |
| E | Out-of-scope export | Bundle contains only the day's records, no extra fields | `AuditExportTest::export_scope_is_strict` |

### 4.10 TB-11 CI/CD and Build Channel

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Running modified actions | Pin every GitHub Action to a full commit SHA | Workflow review |
| T | Code modification without verification | CI on every push and PR, images built from the same source | CI checks |
| R | Deployment repudiation | Git commit, Compose log, deploy timestamp | Git and Docker logs |
| I | Secret leakage | Gitleaks and `.env.production` outside Git with `600` permission | CI scan and deploy preflight |
| D | Deploy outage due to external source | Lockfiles, last commit kept locally, healthy images on the server | Rollback exercise |
| E | Instruction execution at the build layer | Reviewed Dockerfiles, non-root runtime user | Production image policy |

### 4.11 TB-12 Egress Channel

| Category | Threat | Control | Test |
|---|---|---|---|
| S | Untrusted service calling out | Restrict and review integrations and their destinations | Config review |
| T | In-flight update | No package installs inside the runtime container | Production image policy |
| R | Connection repudiation | Caddy, Laravel, and Docker logs | Log review |
| I | Data leakage via outbound connection | No external integration without a contract and data classification | Integration test |
| D | Service outage due to firewall | DNS/HTTPS/MySQL/Redis checks after changes | Health probe |
| E | Egress for privilege escalation | Non-root user and privilege-escalation blocks | Image and Compose scans |

## 5. Compliance Map

### 5.1 PDPL

| Article/Principle | Requirement | Technical Control | Test |
|---|---|---|---|
| Processing basis | Process data under a statutory basis or consent | Document the basis on each work type and its classification | `ComplianceTest::processing_basis_recorded` |
| Data minimization | Collect the minimum | Every dynamic field subject to `required` rule and reviewed | `WorkDefTest::fields_minimized_for_purpose` |
| Data accuracy | Ensure employee PII accuracy | PII modification authority to the owner only; every modification audited | `IdentityTest::pii_edits_audited` |
| Retention | Period defined by work type | `retention_until` on every record, controlled destruction | `RetentionTest::retention_policy_enforced` |
| Data subject rights | Access, rectify, erase within limits | Rights-request workflow with documented exceptions | `RightsTest::data_subject_request_workflow` |
| Data security | Protect PII with appropriate measures | PII column encryption, field-capability policies, access auditing | `SecurityTest::pii_encryption_and_access_logging` |
| Breach notification | Notify the competent authority | PII data-leak detection and automated alerting | `IncidentTest::pii_breach_detection_alerts` |
| Data transfer | No transfer outside the Kingdom | No external data destination without approval and classification | Integration review |

### 5.2 NCA ECC

| Control | Title | Platform Application | Test |
|---|---|---|---|
| 1-1-1 | Cybersecurity governance | Assign security officer, adopt threat model, annual review | `GovernanceTest::threat_model_reviewed_annually` |
| 1-2 | Asset management | Automatic asset record per WorkRecord and StorageObject | `AssetTest::asset_inventory_complete` |
| 1-3 | Data protection | At-rest and in-transit encryption, key management | `CryptoTest::encryption_at_rest_and_in_transit` |
| 1-4 | Identity and access management | Local accounts, MFA for administration, privilege separation | `IdentitySessionTest::*` |
| 1-5 | Privileged account management | Break-glass isolated, documented, reviewed | `BreakGlassTest::*` |
| 1-6 | Vulnerability management | Dependency audit, lockfile update, base images | CI checks |
| 1-7 | Logging and monitoring | Centralized logs, alerts, 12-month retention | `LoggingTest::*` |
| 1-8 | Infrastructure protection | Firewall, HTTPS, MySQL/Redis/Docker not exposed | Port scan |
| 1-9 | Incident response | Response team, scenarios, training | `IncidentTest::*` |
| 1-10 | Backup management | Encryption, separation, quarterly restore test | `BackupTest::*` |
| 2 | Advanced cyber-defense controls | EDR, segmentation, monitoring of sensitive channels | `DefenseTest::critical_channels_monitored` |
| 3 | Cloud cybersecurity controls | N/A; on-prem; documented exemption | `GovernanceTest::cloud_exemption_documented` |
| 4 | Third-party cybersecurity controls | None in phase one; documented exemption | `GovernanceTest::third_party_exemption_documented` |

### 5.3 NDMO

| Standard | Title | Application | Test |
|---|---|---|---|
| Data classification | `public`, `internal`, `confidential`, `top_secret` | Dynamic fields, records, documents | `ClassificationTest::*` |
| Data quality | Accuracy, completeness, freshness, consistency | Validation rules on `FieldDefinition`, periodic checks | `QualityTest::data_quality_rules_enforced` |
| Data lifecycle | Create, use, archive, destroy | Retention policies on every work type | `RetentionTest::*` |
| Data ownership | Assignment per data group | `owner_organization_unit_id` mandatory | `OwnershipTest::every_record_has_owner` |
| Data sharing | Permissions built on classification and relationship | Centralized authorization decision | `AuthorizationTest::*` |
| Master data | Controlled duplication, no referential duplication | Cross-module reference ids | `BoundaryTest::cross_module_references_via_ids` |
| Metadata | Mandatory metadata | Envelope and audit event | `MetadataTest::envelope_metadata_complete` |

## 6. Additional Guard Tests

These tests are run periodically and automated in the internal CI:

- `SecurityRegressionTest::all_passwords_are_argon2id`
- `SecurityRegressionTest::no_raw_sql_in_module_code`
- `SecurityRegressionTest::no_external_urls_in_runtime_artifacts`
- `SecurityRegressionTest::no_secrets_in_repository`
- `SecurityRegressionTest::no_employee_pii_in_logs`
- `BoundaryTest::module_cannot_import_other_module_infrastructure`
- `BoundaryTest::no_cross_module_joins`
- `AuditTest::audit_chain_validates_end_to_end`
- `AirGapTest::dns_resolution_external_returns_failure`
- `BackupTest::restore_drill_runs_quarterly`

## 7. Unacceptable-risk Outputs

Any threat whose residual risk remains "high" after the controls must be recorded in the institutional risk register (Risk module, phase three) with a treatment plan tied to a task and a deadline, and the feature may not launch until the risk is reduced to "medium" or below.

## 8. Review Cycle

- Quarterly review of the threat model.
- Full reassessment on adding a new module or changing a trust boundary.
- Annual review of ECC, NDMO, and PDPL alignment.
- The audit module retains a signed copy of every review.

## Change Log

| Version | Date | Role | Change |
|---|---|---|---|
| 0.1.0 | 2026-07-15 | Information Security Officer | Initial draft created |
| 0.2.0 | 2026-07-15 | Information Security Officer | Unified classification, supply-chain and recovery references, document tightening; corrected the identity row to remove JWT/refresh-token claims and document the server-side opaque session implemented via `IdentitySessionMiddleware` |
