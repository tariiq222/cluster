# Pitfalls Research: Third Health Cluster Administrative Platform

**Domain:** Air-gapped, regulated enterprise administrative workflow platform (R1–R3)  
**Researched:** 2026-07-15  
**Overall confidence:** HIGH for project-specific risks and phase mapping; LOW for external corroboration fetched through the available web provider  
**Primary basis:** Accepted project roadmap, constraints, ADRs, readiness gates, and security model. Draft/proposed documents are treated as unresolved inputs, not approved facts.

## Executive Risk Profile

The most likely project failure is not one isolated coding defect. It is loss of control across several cross-cutting invariants while a 2–4 person team implements a broad platform: authorization must remain identical across every read surface, organizational isolation must survive derived data, published definitions must remain immutable, workers must be replay-safe, and every deploy must be reproducible without Internet access. These invariants need executable gates beginning in W1.1–W1.4; postponing them to release UAT would make failures expensive or unrecoverable.

The unusually detailed documentation baseline is an advantage only if document status and executable evidence are managed. Several important documents are still `draft` or `proposed`, while the roadmap is accepted. Treating detail as approval will freeze assumptions into code; treating documentation as a substitute for running slices will produce a long horizontal foundation with no validated user journey. Every wave therefore needs an evidence bundle tied to REQ-ID/TEST-ID, not a narrative declaration of completion.

The roadmap should preserve the approved wave order. In particular, do not start dynamic definitions before organization and centralized authorization, do not build search/reporting before authorization-aware projections are proven, do not start R2 before the R1 pilot and recovery gate, and do not code R3 before W3.0 approves risk semantics. Security, recovery, air-gap operation, observability, Arabic usability, and adoption are exit criteria for each relevant wave—not hardening phases at the end.

## Gate Semantics Recommended for the Roadmap

| Gate class | Required evidence | Decision rule |
|---|---|---|
| Architecture invariant | Automated test in offline CI plus negative case | Any bypass blocks merge |
| Security/isolation | Subject × action × resource × organization × classification matrix, including derived surfaces | Any unauthorized disclosure or fail-open result blocks release |
| Versioning | Immutable published artifact, instance pin, compatibility/migration test | Silent mutation or migration blocks wave exit |
| Air-gap/supply chain | Clean build/deploy with egress denied, digest, SBOM, provenance, signature verification | Missing or unverifiable artifact blocks promotion |
| Recovery | Isolated end-to-end restore with measured data-loss window and elapsed recovery time | RPO >15 minutes or RTO >2 hours blocks real data/release |
| Adoption | Real-user completion inside platform, shadow-process sampling, usability evidence | Training attendance alone never makes a gate green |
| Governance | Named owner, evidence URI/hash, date, REQ-ID/TEST-ID, residual risk | A checkbox without evidence is `red`, not `yellow` |

## Critical Pitfalls

### 1. Authorization Works in CRUD but Leaks Through Search, Reports, Exports, or Files

**What goes wrong:** The primary API enforces RBAC+ABAC, but an index, dashboard read model, CSV export, notification payload, object-storage URL, or document download exposes another organization’s title, count, field, or file. A second variant is granting visibility to a source record merely because a user can see its task.

**Why it is likely here:** The platform has many derived read surfaces, field-level classification, explicit sharing, supervisory relationships, and cross-organization use cases. A small team will be tempted to implement authorization per controller or filter after retrieval. Search and reporting arrive late enough that they may bypass assumptions proven only against CRUD.

**Early warning signs:**
- Different policy helpers or SQL predicates appear in controllers, search, reports, exports, and documents.
- Read models omit `owner_organization_unit_id`, classification, policy/facts version, or source reference.
- Search returns forbidden titles/counts before filtering, or performs authorization after pagination.
- Presigned URLs remain usable after role/account/classification changes.
- Tests cover positive role access but not adjacent hospital, parent/child unit, expired delegation, explicit deny, field hiding, and source-task separation.

**Prevention and testable gate:**
- W1.3 must expose one fail-closed authorization contract and one policy-decision trace with stable reason codes.
- Build a generated decision matrix for all ten stages in `authorization-model.md`, including unavailable dependencies and stale/unknown facts.
- Require every business module to implement `GetAuthorizationRecordFacts`; prohibit module-owned allow/deny logic.
- Make search/reporting projections carry sufficient immutable facts to prefilter candidates, then re-evaluate sensitive reads at response/export/download time.
- Contract-test API, list, search, dashboard, export, notification link, and document download against the same scenario corpus.
- Add mutation tests that remove each authorization call and prove CI fails.

**Recovery:** Stop affected export/search/download paths, invalidate links and sessions, preserve audit evidence, determine exposed records by decision logs, rebuild projections with complete facts, notify security/privacy owners, and add the missed surface to the shared matrix before reopening.

**Phase/gate mapping:** Design in W1.2; enforce in W1.3/M1; re-verify at W1.4–W1.9 and every R2/R3 module; release blocker at W1.10, W2.7, and W3.7 (`S-09`, `S-10`, isolation scenarios).

---

### 2. Organizational Isolation and Time-Bounded Authority Drift

**What goes wrong:** Transfers, temporary assignments, committee membership, delegation, account disabling, or supervisory relationship expiry do not take effect immediately. Cached access contexts or stale projections continue to authorize a user, while broad “cluster admin” roles erase hospital isolation.

**Why it is likely here:** Authority depends on organization facts, temporal windows, relationships, explicit shares, roles, and classification. These facts change independently. Local accounts and manual structure import also create duplicate or orphaned people and units.

**Early warning signs:**
- Long-lived authorization caches have no policy/facts version or invalidation event.
- Deactivation revokes login but not active sessions, refresh tokens, delegations, jobs, or presigned URLs.
- Imported units can be reparented without cycle and effective-date checks.
- Test fixtures use one flat organization instead of cluster + hospital peers + multiple depths.
- “Temporary” grants lack `starts_at`, `ends_at`, approver, or owner.

**Prevention and testable gate:**
- Make the 14 organization invariants and 14 isolation scenarios executable before later modules depend on them.
- Use effective-dated assignments and relationships; evaluate current facts on every sensitive request.
- Version authorization facts and policies; invalidate affected sessions and caches on change.
- Make structure import a staged validate/diff/approve/apply operation with immutable import evidence and rollback plan.
- Run a temporal test suite around boundaries, timezone conversion, overlap, reparenting, disabled accounts, expired delegation, and revoked shares.

**Recovery:** Freeze organization changes, revoke affected sessions/grants, repair effective-dated facts through an audited correction, recompute derived authorization facts, replay access checks for the incident window, and reconcile imported hierarchy against its approved source.

**Phase/gate mapping:** W1.2 organization/import gate; W1.3 authorization gate; session-revocation and temporal regression at every release (`S-03`, `D-10`).

---

### 3. Published Dynamic Definitions or Workflows Are Mutated In Place

**What goes wrong:** Editing a form, validation rule, field policy, SLA, transition, timer, or workflow step changes the meaning of already-open records. Historical audit becomes uninterpretable; tasks disappear or reroute; required fields appear mid-process; timer subscriptions reset; reports mix incompatible schemas.

**Why it is likely here:** R1 deliberately supports no-code dynamic work types and workflows. Product pressure will make “edit published version” look simpler than create-copy-publish-pin. Long-running instances make migration semantics substantially harder than definition CRUD.

**Early warning signs:**
- Published rows receive `UPDATE` or `DELETE` operations.
- Work records refer to a logical definition ID but not an immutable version/digest.
- UI labels “Save” and “Publish” as the same operation.
- A migration changes active instances without a dry-run count and per-state compatibility report.
- Workflow tests assert only new instances, not existing instances pinned before publication.

**Prevention and testable gate:**
- Separate draft from immutable published versions; compute a content digest and record publisher/time.
- Pin each `WorkRecord`, workflow instance, field policy, and timer semantics to exact versions.
- Publish by copy-on-write; retire versions without deleting them.
- Default to “new instances only.” Permit migration only via explicit, audited plan mapping source states/elements/fields to targets, with dry run, incompatibility report, approval, backup, and rollback strategy.
- Test old and new versions concurrently, including validation, field authorization, escalation deadlines, rejection/return, and reporting projections.

**Recovery:** Stop publication/migration, restore the prior immutable definition, identify affected instances by audit/outbox history, reconstruct their original version assignment, repair tasks/timers explicitly, and communicate any user-visible correction. Never hide the repair as a normal update.

**Phase/gate mapping:** Data model in W1.1; definition immutability in W1.4; workflow pin/migration semantics in W1.5; reference journey in W1.6; hard release check at W1.10 (`F1-08`, `F1-14`, `D-11`).

---

### 4. Outbox and Worker Semantics Create Duplicate or Missing Business Effects

**What goes wrong:** A record commits without its event, an event publishes twice, a worker retries after partial success, or two scheduler replicas execute the same escalation. Consequences include duplicate tasks/notifications, missed SLA escalation, inconsistent search/reporting, or incorrect R2/R3 calculations.

**Why it is likely here:** Notifications, projections, search, dashboards, escalations, and cross-module links rely on asynchronous effects. Kubernetes restarts and at-least-once delivery are normal. “Exactly once” may be assumed without implementing idempotency and reconciliation.

**Early warning signs:**
- Business write and outbox insert use separate transactions.
- Consumer tables lack unique `event_id`/idempotency keys.
- Retry tests assert job success but not one final business effect.
- DLQ depth, oldest event age, projection lag, and poison-event rate are not observable.
- Scheduler replicas run cron independently without leader election or durable claim.

**Prevention and testable gate:**
- Enforce business fact + outbox in one database transaction through an architecture test.
- Make every consumer record `event_id` before/with its effect under a unique constraint.
- Use deterministic keys for generated tasks, notifications, approvals, and threshold crossings.
- Add crash-point tests before commit, after commit/before ack, during effect, and after effect/before ack.
- Provide replay, DLQ triage, and projection rebuild runbooks; alert on lag and stuck events.

**Recovery:** Pause the consumer, classify events as not-applied/partially-applied/applied, reconcile using idempotency records and source truth, repair with explicit compensating commands, replay from a recorded checkpoint, and verify downstream projections.

**Phase/gate mapping:** Prove in W1.1 walking skeleton; workflow/timers W1.5; notifications W1.8; read models W1.9; load/restart verification at every release (`P-06`, `O-05`).

---

### 5. The Air Gap Is Verified Only at Runtime, Not Across Bootstrap, Build, Patch, and Recovery

**What goes wrong:** The application runs offline after a connected build, but a clean rebuild, security patch, new developer setup, base-image refresh, malware-signature update, or disaster recovery requires public Internet. Alternatively, internal mirrors contain unverified mutable artifacts and become the trusted attack path.

**Why it is likely here:** Composer, npm, container images, vulnerability data, signing, licenses, fonts, and malware scanning all have external ecosystems. Air-gapped CI/CD is required from W1.1, but mirror governance and emergency patch lead time can be underestimated.

**Early warning signs:**
- Lockfiles exist, but tarballs/images needed for rebuild are absent internally.
- Builds use tags such as `latest`, download browser binaries/fonts, or contact public registries.
- SBOM exists as a file but is not bound to image digest and provenance.
- Admission verifies registry location but not signature identity/digest.
- No owner/SLA exists for importing advisories, scanner databases, patched dependencies, and revoked keys.
- A critical vulnerability cannot be mapped to deployed artifacts within hours.

**Prevention and testable gate:**
- Before W1.1, define a two-person import/approval lane, quarantine scanning, license review, immutable internal storage, and emergency patch SLA.
- Pin packages and images by lockfile/digest; mirror all transitive build tools and recovery artifacts.
- Produce digest-bound SBOM, provenance, scan results, and signature; verify all at promotion/admission.
- Run clean-room `verify-airgap` with DNS and egress denied and empty local caches.
- Quarterly, rebuild the current production release and one urgent dependency update solely from internal sources.
- Retain the exact artifact set required to restore every supported release.

**Recovery:** Block promotion, inventory affected digests from SBOMs, revoke compromised artifacts/keys, import the fix through quarantine, rebuild from clean internal inputs, rotate signing identity if needed, and prove offline rollback and forward patch.

**Phase/gate mapping:** Mandatory pre-W1.1 decision and W1.1 exit; continuous every wave; release blocker `AG-01`–`AG-08`, `O-01`, `L-07`.

---

### 6. “Backup Success” Is Mistaken for Recoverability

**What goes wrong:** Database backups complete, but restoration fails because object storage, encryption keys, secrets, configuration, schema migration state, internal images, queue/outbox position, or compatible search/read-model rebuild tools are missing. RPO/RTO are reported from backup job duration rather than measured business recovery.

**Why it is likely here:** The platform spans MySQL, object storage, queues/cache, search, Kubernetes configuration, signed artifacts, and encrypted PII. The required backup store must be independent of the cluster and WORM-capable. A small team may optimize for backup automation and postpone full drills.

**Early warning signs:**
- Restore tests use the same cluster, credentials, or storage failure domain as production.
- RTO clock excludes environment provisioning, key recovery, integrity checks, projection rebuild, or business validation.
- Backup logs are green but no sampled record/file can be opened and authorized.
- Schema and application artifact compatibility is undocumented.
- No evidence proves a 15-minute data-loss window under realistic write load.

**Prevention and testable gate:**
- Define a recovery manifest: database, objects, keys/KMS procedure, secrets, manifests, signed images, versions, configuration, audit chain anchor, and rebuild checkpoints.
- Restore into an isolated environment from the independent store; start the clock at incident declaration and stop only after reference journeys and integrity checks pass.
- Measure loss from the last committed business event, not backup timestamps alone.
- Validate hash/audit continuity, object checksums, outbox reconciliation, and authorization after restore.
- Run an early destructive drill before real data, then quarterly and at every release.

**Recovery:** Declare service unavailable rather than accepting writes into an uncertain state, select a compatible recovery point, restore the complete manifest, reconcile outbox/projections, run reference journeys and security checks, document actual RPO/RTO, and remediate the failed component before reopening.

**Phase/gate mapping:** Storage/backup decision before W1.1 and before real data; first end-to-end drill by W1.6 at latest; hard W1.10/W2.7/W3.7 gate (`D-02`–`D-07`, `O-02`–`O-04`).

---

### 7. Small-Team Delivery Collapses Under Platform and Kubernetes Complexity

**What goes wrong:** The team spends most capacity operating GitLab/registry/mirrors/Kubernetes/MySQL HA/object storage/queue/search/observability rather than delivering validated workflows. Specialists become single points of failure; on-call and security reviews are nominal; wave durations expand while partially built infrastructure accumulates.

**Why it is likely here:** The scope is broad, targets 2,000 concurrent users, and assumes multiple HA stateful services, while team experience is stronger in Laravel than React/Kubernetes. The readiness checklist names roles that may exceed actual staffing.

**Early warning signs:**
- One person alone can build, deploy, restore, rotate keys, or diagnose production.
- More than one wave is open; incomplete cross-cutting work is carried forward repeatedly.
- Bespoke infrastructure components appear where an approved internal platform capability exists.
- Runbooks are written by the only operator and never executed by another person.
- Planned product capacity falls below half of the team for multiple sprints.

**Prevention and testable gate:**
- Staff named platform/SRE/security ownership outside the 2–4 developers where the roadmap assumes those functions; if unavailable, formally rebaseline scope/SLO rather than hiding the gap.
- Prefer the minimum supported topology and existing data-center services; do not create microservices or custom operators.
- Limit work in progress to one vertical wave and reserve explicit capacity for patching, observability, recovery, and support.
- Require two-person operability: a second person deploys, restores, rotates a non-production key, and handles a simulated P1 from runbooks.
- Track bus factor, toil hours, lead time for offline dependency updates, and open operational exceptions.

**Recovery:** Freeze new features, reduce topology to supported essentials, close automation/runbook gaps, cross-train, bring in platform operations support, and re-estimate remaining releases. Do not compensate by deleting security or recovery gates.

**Phase/gate mapping:** Resource/operating-model decision before W1.1; review at each wave; explicit capacity and readiness reassessment before R2 and R3.

---

### 8. Module Boundaries Erode Under Reporting and Schedule Pressure

**What goes wrong:** Modules import another module’s ORM model/infrastructure, perform cross-module joins, or put business rules/data in `shared`. Initial delivery accelerates, then authorization, versioning, migrations, tests, and R2/R3 ownership become coupled and risky.

**Why it is likely here:** One operational MySQL database makes cross-module access technically easy. Search/reporting and later R2/R3 links create pressure for direct joins. A small Laravel team may default to convenient Eloquent relationships.

**Early warning signs:**
- Cross-module foreign keys or Eloquent relations appear.
- `shared` gains business entities, policies, or mutable tables.
- A module’s migration edits another owner’s table.
- Contracts expose ORM objects or change without schema compatibility tests.
- Dashboard fixes require edits across several business modules.

**Prevention and testable gate:**
- Install architecture tests in W1.1 that reject forbidden imports, dependency cycles, raw cross-owner SQL, cross-module FKs, and business code in shared.
- Give each fact/table/event/schema one owner; consumers keep IDs or rebuildable projections only.
- Version contracts/events and test producer-consumer compatibility.
- Put cross-domain analytics in Reporting read models, never business tables; rebuild from source events.
- Record every temporary exception with owner, expiry, removal test, and CCB approval—no permanent waivers.

**Recovery:** Stop adding dependencies, map ownership violations, introduce a contract/event or local projection, migrate reads, remove cross-module writes/FKs/imports, and add a regression rule matching the discovered bypass.

**Phase/gate mapping:** W1.1 architecture foundation; enforce every merge; special review W1.9, W2.3/W2.6, and W3.4–W3.6.

---

### 9. Performance Is Tested After the Data and Authorization Shapes Are Fixed

**What goes wrong:** R1 works with demo data but fails near 2,000 concurrent users. Per-row authorization creates N+1 calls; dynamic JSON is scanned in reports; broad dashboards issue cross-domain fan-out; projection lag makes data stale; audit logging and PII encryption become bottlenecks.

**Why it is likely here:** The design combines dynamic fields, per-item authorization, search, reports, audit, and derived read models. The published targets vary between PROJECT.md and readiness metrics, creating a risk that teams test an easier threshold.

**Early warning signs:**
- No representative data-volume model exists before W1.4.
- List endpoints fetch candidates then authorize one at a time.
- Reports query raw JSON payloads or transactional aggregates.
- Load tests omit audit, file, queue, search, and realistic permission diversity.
- P95 is reported without error rate, saturation, projection lag, or cold-cache behavior.
- Conflicting SLO thresholds are not resolved by CCB.

**Prevention and testable gate:**
- Reconcile canonical SLOs before W1.1 and make the stricter threshold the temporary default.
- Establish a repeatable offline load harness and synthetic data generator in W1.1.
- Benchmark authorization list strategy and typed projections by W1.4, workflow contention by W1.6, and search/reporting at W1.9.
- Load test realistic organizational scopes, classifications, concurrent updates, files, audit, workers, and node failure.
- Set budgets for query count, authorization evaluations, queue lag, read-model freshness, and dashboard fan-out.

**Recovery:** Capture traces/query plans, disable or rate-limit pathological exports/dashboards, add/rebuild typed projections and indexes, batch authorization inputs without weakening policy, and rerun the full workload plus node-failure test.

**Phase/gate mapping:** SLO decision/W1.1 harness; W1.4–W1.6 data-shape tests; W1.9 production-like load; release gates `P-01`–`P-10`; focused R2/R3 regression.

---

### 10. Pilot Users Revert to Email, Excel, and Paper

**What goes wrong:** The software passes feature UAT, but users keep the authoritative work outside it. Managers receive duplicate requests, staff need support for routine actions, records become incomplete, and dashboards describe only platform activity—not actual work.

**Why it is likely here:** The product replaces entrenched tools, supports Arabic/English and RTL/LTR, and introduces formal scopes, classification, workflows, and denial reasons. In-app-only notifications make the daily workspace and manager inbox critical. Starting with new data avoids migration but also makes parallel shadow tracking easy.

**Early warning signs:**
- Pilot workflows are initiated or approved by email, then backfilled.
- Low ratio of completed-to-created records, rising overdue tasks, high abandoned drafts, or repeated support-assisted completion.
- Users maintain duplicate Excel identifiers or attach screenshots of email approval.
- Usability testing occurs only at W1.10 or with administrators rather than ordinary users.
- Denials are technically correct but explanations do not help the user recover.
- Training attendance is used as adoption evidence.

**Prevention and testable gate:**
- Put 5–8 representative Arabic-first users through the reference journey from W1.1 onward, not just release UAT.
- Instrument funnel completion, time-on-step, denial reason frequency, stale tasks, notification-to-action latency, and support dependency without logging sensitive payloads.
- Define a pilot operating rule for which work is authoritative in the platform; sample for shadow records weekly.
- Design the unified inbox, scope selector, error recovery, and role dashboards around observed tasks; validate RTL/LTR and terminology continuously.
- Give each pilot department a process owner and super-user; publish day-one support and a rapid correction SLA.

**Recovery:** Identify the failed journey rather than blaming users, simplify the definition/workflow, fix terminology or authorization friction, reconcile shadow records through a governed one-time process, retrain on the corrected journey, and repeat the adoption gate before wider rollout.

**Phase/gate mapping:** UX baseline W1.1; role/scoping tests W1.2–W1.3; form/workflow usability W1.4–W1.6; inbox/notifications W1.8; pilot/adoption gate W1.10; repeat with strategy/PMO users at W2.7 and risk users/committee at W3.7 (`UX-*`, `T-*`).

---

### 11. Audit Evidence Is Either Mutable, Incomplete, or Itself a Privacy Leak

**What goes wrong:** Sensitive reads/exports are not logged, business actions and audit events diverge, hash-chain validation cannot distinguish deletion/reordering, administrators can alter evidence, or logs contain PII/document payloads that broaden exposure.

**Why it is likely here:** The platform requires explainable authorization, sensitive-access events, immutable audit, daily export to separate storage, and long retention. Workers, admin, break-glass, search, export, and document access all create separate audit paths.

**Early warning signs:**
- Audit is written after response or via best-effort asynchronous logging.
- Hash chains are generated but no external signed anchor/export is verified.
- Logs contain complete request bodies, dynamic payloads, national IDs, or file names beyond need.
- Break-glass/admin access uses the same stream and credentials as ordinary logs.
- Restore drills omit chain continuity and exported evidence.

**Prevention and testable gate:**
- Define auditable event coverage and redaction schemas before W1.3; separate operational logs from immutable audit.
- For sensitive business mutations, persist the audit/outbox fact transactionally or prove an equivalent no-gap design.
- Chain/sign audit batches and export daily to physically/logically separate append-only storage with independent credentials.
- Test deletion, insertion, reordering, clock skew, exporter failure, key rotation, and restore continuity.
- Prohibit sensitive payload logging through static checks and seeded canary PII tests.

**Recovery:** Preserve affected stores, rotate credentials/keys if compromise is suspected, reconstruct events from business/outbox/deployment records while clearly marking inferred evidence, determine privacy exposure, notify owners, and re-anchor the chain after an approved incident record.

**Phase/gate mapping:** W1.1 audit skeleton; W1.3 decision logging; W1.6 business action coverage; W1.8 document/worker access; release security gate (`S-06`, `S-10`, `O-14`, `O-15`).

---

### 12. Governance Detail Creates False Certainty and Checkbox Gates

**What goes wrong:** Draft/proposed documents are implemented as approved policy, conflicting targets are silently chosen, deferred decisions remain unresolved, or readiness becomes a weighted spreadsheet where critical evidence is absent. Conversely, the team spends weeks perfecting documents while no deployable slice proves them.

**Why it is likely here:** The baseline is unusually extensive and dated as a coordinated package. Yet `threat-model.md` and `coding-and-module-boundaries.md` are draft, `air-gap-supply-chain.md` is proposed, W3 requires a future approved RISK-SPEC, and several infrastructure/retention/classification decisions are explicitly deferred.

**Early warning signs:**
- Code reviews cite a document without checking status/version.
- No decision register links implementation assumptions to acceptance authority.
- Readiness evidence is a screenshot or “CI passed” without artifact hash, test ID, environment, date, and owner.
- A red security/functional item is averaged away by the weighted score.
- Horizontal components are “complete” while the reference journey cannot run in the air gap.

**Prevention and testable gate:**
- Before each wave, create a canonical-reference manifest recording document status/version and unresolved assumptions.
- Convert deferred decisions to dated entry criteria with named owners exactly as the implementation roadmap requires.
- Make readiness machine-readable where possible; evidence must be immutable and traceable to REQ-ID/TEST-ID/build digest/environment.
- Preserve hard vetoes: security, isolation, recovery, and functional red items cannot be averaged away.
- Keep each wave vertical: deployable slice, negative tests, operations evidence, and user demonstration.

**Recovery:** Stop work at the next safe boundary, log the assumption/contradiction as a decision or change request, identify affected code/tests/data, rebaseline the wave, and repeat the invalidated gate. Do not retroactively relabel unsupported evidence green.

**Phase/gate mapping:** Pre-W1.1 document/status and deferred-decision review; every wave entry/exit; W3.0 is a strict specification gate; formal Go/No-Go at W1.10/W2.7/W3.7.

---

### 13. R2 Metrics Manufacture Improvement Through Ambiguous Attribution

**What goes wrong:** Project contribution is counted more than once, baseline/target/reading periods do not align, unapproved readings drive dashboards, or claimed project impact exceeds observed indicator improvement. Executive reporting becomes misleading even though CRUD and charts work.

**Why it is likely here:** R2 links distributed targets, approved readings, project health, budget-like administrative values, and improvement-project impact across organizations. The project explicitly requires that attributed impact not exceed observed improvement.

**Early warning signs:**
- Indicator formulas use binary floating point or undocumented rounding.
- Baselines, units, directionality, period, owner, and approval state are mutable after use.
- Multiple projects claim the same delta without an allocation constraint.
- Dashboards mix approved and draft readings without visible status.
- Corrections overwrite prior values rather than versioning them.

**Prevention and testable gate:**
- Freeze indicator definition versions and use decimal arithmetic with explicit unit/rounding rules.
- Treat readings, targets, approvals, and corrections as effective-dated/versioned facts.
- Enforce contribution allocation and the “not above observed improvement” invariant in the domain, not only the UI.
- Test negative/declining indicators, missing periods, revised baselines, late readings, cross-hospital allocations, and concurrent approval.

**Recovery:** Freeze affected dashboards, recompute from approved source facts, reverse invalid attributions through audited corrections, notify strategy/PMO owners, and add the discovered formula case to guard tests.

**Phase/gate mapping:** W2.2 indicator semantics; W2.5 health/decimal guards; W2.6 attribution invariant; W2.7 evidence gate (`F2-05`, `F2-06`, `F2-07`, `F2-10`).

---

### 14. R3 Is Built Before Risk Governance Semantics Are Approved

**What goes wrong:** Developers choose probability/impact scales, matrix calculation, appetite, residual-risk formula, review cadence, escalation threshold, or control-effectiveness semantics. The risk register later requires a data/model rewrite or produces governance-invalid results.

**Why it is likely here:** The roadmap correctly inserts W3.0, and no approved `RISK-SPEC.md` was present in the researched baseline. Risk links also depend on R1 tasks and R2 indicators, increasing pressure to infer missing rules from available UI ideas.

**Early warning signs:**
- R3 tickets exist before an approved matrix and glossary.
- Scores are stored only as final numbers without input scale/version.
- Risk appetite and KRI thresholds are global constants with no owner/effective date.
- Residual risk does not recompute or require review after control-effectiveness change.
- Critical escalation tests use developer-created examples rather than committee-approved cases.

**Prevention and testable gate:**
- Make W3.0 a no-code governance gate covering taxonomy, scales, matrix/versioning, appetite, control effectiveness, treatment states, review cycles, KRI semantics, escalation, ownership, and correction policy.
- Store assessment inputs and governing specification version, not only calculated score.
- Use approved golden cases as executable tests before W3.1.
- Preserve historical assessments and recalculate only through explicit reassessment.

**Recovery:** Halt R3 feature work, version and approve semantics, map existing data to the approved model, migrate only with explicit conversion and reconciliation, and invalidate dashboards/alerts computed under unofficial rules.

**Phase/gate mapping:** W3.0 strict entry gate; semantics enforced W3.1–W3.5; cross-owner links W3.6; committee tabletop and release gate W3.7 (`F3-01`, `F3-06`, `F3-10`).

## Technical Debt Patterns

| Shortcut | Immediate benefit | Long-term cost | Acceptable? |
|---|---|---|---|
| Authorization checks in React/controller only | Fast screen delivery | Cross-surface data breach | Never |
| Cache complete allow/deny decisions without facts/policy version | Lower latency | Stale authority after transfer/revocation | Never |
| Edit published definitions/workflows | Simple admin UX | Corrupted in-flight/history semantics | Never |
| Cross-module Eloquent relation/join | Fast dashboard | Coupling and ownership breach | Never |
| JSON-only dynamic reporting | No projection work | Unbounded scans and weak typing | Prototype with synthetic data only; not a release path |
| Skip outbox idempotency because queue is “reliable” | Less code | Duplicate/missing effects after restart | Never |
| Connected build copied into air gap | Fast bootstrap | Non-reproducible, unpatchable supply chain | Only disposable exploration; cannot satisfy W1.1 |
| Backup without restore drill | Easy green status | Unknown recoverability | Never with real data |
| Single operator for deploy/recovery | Lower staffing demand | Critical bus factor | Temporary only with dated remediation before pilot |
| Carry a gate failure into next wave | Schedule appearance | Compounded invalid assumptions | Only by explicit sponsor/CCB exception with residual-risk owner |

## Performance Traps

| Trap | Early symptom | Prevention | Expected break point |
|---|---|---|---|
| Per-result remote authorization | Query count grows linearly; high P95 on lists | Authorized candidate scope + batched facts + final sensitive recheck | Large pages and manager dashboards well before 2,000 concurrent users |
| Raw JSON report filters | Full scans, CPU spikes | Versioned typed projections/indexes | As work-record volume and dynamic fields grow |
| Synchronous notification/search updates in write transaction | Slow/fragile submission | Transactional outbox + idempotent workers | Under bursty submissions or subsystem outage |
| Dashboard fan-out across modules | Variable latency and partial failure | Reporting-owned read model with freshness SLI | R2/R3 executive dashboards |
| Audit payload overlogging | Storage/I/O pressure and PII exposure | Minimal schemas, redaction, batch export | Continuous sensitive reads/exports |
| Hot optimistic-lock rows | Rising 409/conflict rate | Narrow aggregates, conflict UX, idempotent retries | Shared approvals, counters, portfolio/risk updates |

## Security Mistakes Beyond Generic Web Security

| Mistake | Project-specific risk | Prevention/gate |
|---|---|---|
| Treat internal network as trusted | Insider/device bypass | Enforce every request; TLS; short sessions; fail closed |
| Let super admin imply unrestricted content access | Unlogged exposure of confidential/top-secret data | Separate administration from content clearance; sensitive-access audit |
| Authorize a task’s source record transitively | Classification/field leakage | Independent authorization for task and source facts |
| Generate a presigned URL before access audit/decision | Download bypass and weak repudiation | Decision and access event before short-lived URL; recheck at GET |
| Break-glass becomes a standing role | Permanent privilege escalation | Closed by default, two-person activation, ≤60 minutes, allowlist, incident report |
| Sign images but trust mutable upstream mirror content | Signed malicious artifact | Quarantine, source review, immutable digest, provenance, separated approval |
| Store backups beside cluster or with app credentials | One incident destroys production and recovery | Independent WORM store, network/account/key separation |
| Put PII in logs/SBOM/provenance metadata | Compliance breach through operational systems | Canary PII tests, redaction schema, least-data evidence |

## UX and Adoption Pitfalls

| Pitfall | User impact | Better approach |
|---|---|---|
| Scope selector is decorative or inconsistent | Users act in wrong unit/hospital | Server-confirmed active scope visible on every relevant page and export |
| Arabic is translated after implementation | Broken RTL, clipped fields, foreign terminology | Arabic-first prototypes from W1.1; paired RTL/LTR visual tests |
| Explainable deny exposes policy internals or gives no remedy | Security leak or support burden | Stable user-safe reason code + actionable owner/remedy; full detail only in audit |
| Generic dynamic forms expose implementation metadata | Cognitive overload and bad data | Guardrailed builder, preview as role/classification, publish checklist |
| In-app notifications are noisy | Users ignore the only approved channel | Event deduplication, priority, digest/inbox states, action-linked notifications |
| UAT uses administrators only | Ordinary-user failure hidden | Representative employee/manager/super-admin samples in both pilot organizations |

## “Looks Done But Isn’t” Checklist

- [ ] **Authorization:** CRUD tests pass—verify list, search, counts, report, export, notification link, file URL, field redaction, and failure of upstream fact services.
- [ ] **Organization:** Tree renders—verify cycle prevention, effective dates, reparenting, duplicate import, transfer, disabled account, delegation expiry, and peer-hospital isolation.
- [ ] **Dynamic definition:** New version publishes—verify old instances, old fields, old timers, audit readability, projection rebuild, rollback, and explicit migration refusal.
- [ ] **Workflow:** Happy path completes—verify duplicate command, worker crash points, rejection/return, escalation, deadline/timezone, stale task, and concurrency conflict.
- [ ] **Outbox:** Event is visible—verify atomic rollback, duplicate delivery, DLQ, replay, lag alert, and deterministic downstream effect.
- [ ] **Air gap:** Production has no egress—verify clean build/deploy/rebuild/patch/restore with empty caches and only internal artifacts.
- [ ] **SBOM/signature:** Files exist—verify binding to deployed digest, signer identity, admission rejection, revocation, and vulnerability-to-deployment lookup.
- [ ] **Backup:** Job succeeded—verify independent isolated restore of DB, objects, keys, config, artifacts, audit continuity, and measured RPO/RTO.
- [ ] **HA:** Pods have replicas—verify stateful failover, leader-elected scheduler, queue semantics, node failure, rollback, and capacity during degradation.
- [ ] **Performance:** P95 passes—verify representative data/permissions, cold cache, errors, saturation, projection lag, node failure, and stricter canonical target.
- [ ] **Arabic/RTL:** Screenshots look correct—verify full journeys, dynamic content, validation, tables, exports, dates/numbers, and mixed Arabic/English.
- [ ] **Pilot:** Training completed—verify work is initiated, approved, and closed inside platform with no sampled email/Excel shadow authority.
- [ ] **R2 metrics:** Dashboard renders—verify versioned formula/unit/period, approved readings only, decimal rules, and bounded/deduplicated attribution.
- [ ] **R3 risk:** Register has records—verify approved RISK-SPEC version, golden calculations, reassessment, appetite/KRI escalation, and tabletop evidence.
- [ ] **Readiness:** Checkbox is green—verify named owner, date, immutable evidence, environment, REQ-ID/TEST-ID, artifact digest, and no critical red item averaged away.

## Recovery Strategy Matrix

| Failure | Cost | First containment | Durable recovery proof |
|---|---|---|---|
| Cross-organization disclosure | HIGH | Disable surface, revoke access, preserve audit | Full surface matrix and incident-scoped exposure reconciliation |
| Stale authority | HIGH | Revoke sessions/grants, freeze org changes | Temporal matrix and affected decision replay |
| In-place version mutation | HIGH | Stop publish/migrate | Restored pinning plus old/new concurrent instance tests |
| Duplicate/missing async effect | MEDIUM–HIGH | Pause consumer/scheduler | Reconciliation and crash-point replay tests |
| Compromised/unrebuildable artifact | HIGH | Block promotion/revoke digest/key | Clean offline rebuild, signature, admission and rollback evidence |
| Failed restore/RPO/RTO | CRITICAL | Stop uncertain writes | Full isolated recovery within measured targets |
| Boundary erosion | MEDIUM–HIGH | Freeze new dependency | Contract/projection replacement and architecture test |
| Adoption/shadow process | MEDIUM–HIGH | Stop rollout expansion | Reconciled pilot and sustained in-platform completion metrics |
| Invalid R2 attribution | HIGH | Freeze executive dashboard | Recalculation from approved facts and invariant tests |
| Unapproved R3 semantics | HIGH | Halt R3 implementation/release | Approved W3.0 spec and golden-case suite |

## Consolidated Pitfall-to-Phase Mapping

| Pitfall | Prevention phase | Mandatory verification/gate |
|---|---|---|
| Governance/document status ambiguity | Before W1.1 and every wave entry | Canonical-reference manifest; unresolved decision owner/date |
| Air-gap bootstrap and supply chain | W1.1 | Clean offline build/deploy; digest-bound SBOM/provenance/signature; denied egress |
| Outbox/idempotency foundation | W1.1 | Atomic rollback and duplicate/crash replay tests |
| Module boundary erosion | W1.1 onward | Offline CI rejects import/join/FK/cycle violations |
| Organization/time-bounded facts | W1.2 | 14 invariants, import dry-run/rollback, temporal tests |
| Authorization/isolation drift | W1.3/M1 | 14 isolation scenarios plus all derived surfaces and fail-closed cases |
| Published definition mutation | W1.4 | Immutable version/digest; old instance unchanged |
| Workflow migration/timers | W1.5 | Pinning, ten scenarios, explicit migration dry run, scheduler restart |
| End-to-end data consistency | W1.6 | Reference journey with audit/outbox/version/concurrency evidence |
| Document and notification leakage/duplication | W1.8 | Classified-file access matrix; failed/duplicate notification recovery |
| Search/reporting leakage and performance | W1.9/M2 | Prefilter/no-title-leak, field redaction, projection rebuild, target load |
| Recovery illusion | Before real data and W1.10 | Isolated full restore; RPO ≤15m, RTO ≤2h |
| Adoption/shadow process | W1.10/M3 | Two-department pilot, weekly shadow sample, completion/support metrics |
| R2 indicator integrity | W2.2 | Version/unit/period/approval and decimal golden cases |
| R2 attribution inflation | W2.5–W2.6 | Bounded, deduplicated contribution from approved readings |
| R2 release regression | W2.7/M4 | R1 security/recovery/air-gap rerun plus R2 pilot evidence |
| Unapproved risk semantics | W3.0 | Approved RISK-SPEC and executable golden cases; no implementation bypass |
| R3 recalculation/escalation/link errors | W3.1–W3.6 | Versioned assessment, reassessment, KRI threshold, ownership-bound link tests |
| R3 operational validity | W3.7/M5 | Two risk departments, tabletop, full cross-cutting readiness rerun |

## Roadmap Recommendations

1. **Keep W1.1 genuinely vertical.** It must include a dynamic `request` type, two isolated instances, one audited authorization path, outbox delivery, offline deployment, and recoverable artifacts—not only scaffolding.
2. **Treat W1.2–W1.3 as the security/data-isolation foundation.** Later modules may not invent local scope logic.
3. **Split W1.4–W1.6 acceptance around version semantics.** “Can publish” is insufficient; “old in-flight record remains semantically stable” is the key gate.
4. **Add an early recovery drill by W1.6.** Waiting until W1.10 makes infrastructure and key-management defects release-critical.
5. **Make W1.9 a security and scale gate, not a UI phase.** Search/reporting must prove no metadata leakage and no per-row authorization collapse.
6. **Do not overlap R2 with an unproven R1 pilot except by explicit sponsor decision.** R2 depends on trustworthy tasks, authorization, reporting, and recovery.
7. **Preserve W3.0 as a hard stop.** The absence of an approved risk specification is a blocker, not developer discretion.
8. **Repeat cross-cutting gates at every release.** R2/R3 introduce new projections and cross-module links that can reopen R1 failures.

## Confidence and Research Gaps

| Area | Confidence | Basis / remaining gap |
|---|---|---|
| Phase mapping and project constraints | HIGH | Directly derived from accepted implementation roadmap, readiness checklist, assumptions/constraints, and accepted ADRs |
| Authorization and isolation failure modes | HIGH | Detailed internal model and threat tests, corroborated by OWASP; threat model remains draft and needs formal approval |
| Air-gap supply-chain controls | HIGH for project requirement; MEDIUM for operational completeness | Accepted ADR/roadmap plus proposed operations procedure; exact internal products, import SLA, advisory feed, and key custody remain to be decided |
| Workflow/versioning | HIGH for pin/immutability requirement; MEDIUM for migration procedure | Accepted ADR and readiness requirement; concrete migration compatibility policy has not yet been specified |
| Recovery | HIGH for targets and gate | Accepted ADR/readiness; actual data-center topology, KMS, WORM store, and staffing require pre-W1.1 validation |
| Adoption indicators | MEDIUM | Project identifies the risk and pilot structure; baseline/targets for completion, shadow use, and support dependency are not yet defined |
| R2 integrity | MEDIUM–HIGH | Roadmap and readiness define outcome; detailed formula/rounding/allocation contracts need phase-specific review |
| R3 semantics | HIGH that it is a blocker; LOW on final semantics | W3.0 explicitly requires an approved spec, which was not present in the researched baseline |

## Sources

### Canonical project sources
- `/Users/tariq/code/R3/.planning/PROJECT.md` — scope, constraints, R1–R3 success criteria.
- `docs/governance/assumptions-constraints.md` — assumptions, hard constraints, enterprise risks.
- `docs/data-security/threat-model.md` — trust boundaries and executable threat tests (**draft**).
- `docs/plans/implementation-roadmap.md` — wave dependencies, cross-cutting tracks, deferred decisions, gates.
- `docs/plans/readiness-checklist.md` — release evidence and Go/No-Go criteria.
- `docs/engineering/coding-and-module-boundaries.md` — code ownership and architecture guardrails (**draft**).
- `docs/adr/004-authorization-and-isolation.md` — accepted centralized RBAC+ABAC decision.
- `docs/data-security/authorization-model.md` — ten-stage authorization contract (**draft**).
- `docs/adr/005-work-records-dynamic-data.md` — accepted dynamic-data/version pinning decision.
- `docs/operations/air-gap-supply-chain.md` — artifact/import/promotion procedure (**proposed**).
- `docs/adr/019-kubernetes-resilience-and-recovery.md` — accepted HA, RPO, and RTO decision.

### External corroboration
- OWASP Authorization Cheat Sheet — deny by default, every-request/server-side authorization, logging, regression tests: https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- Camunda 8 process-instance migration documentation — explicit mappings and migration semantic constraints: https://docs.camunda.io/docs/components/concepts/process-instance-migration/
- SLSA v1.2 requirements — provenance and artifact verification framework: https://slsa.dev/spec/v1.2/requirements
- NIST SP 800-218 SSDF — secure practices integrated across the SDLC: https://csrc.nist.gov/pubs/sp/800/218/final
- CISA software supply-chain guidance for suppliers — security checks, artifact protection, vulnerability response: https://www.cisa.gov/resources-tools/resources/securing-software-supply-chain-recommended-practices-guide-suppliers
- NIST SP 800-34 Rev. 1 — contingency planning, business impact, and recovery lifecycle: https://csrc.nist.gov/pubs/sp/800/34/r1/upd1/final

---
*Pitfalls research for the Third Health Cluster administrative platform, R1–R3.*
