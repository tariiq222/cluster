# Phase 1: W1.1 Walking Skeleton - Context

**Gathered:** 2026-07-15
**Status:** Ready for planning; the permanent release gate remains open until its deferred security and deployment decisions close.

<domain>
## Phase Boundary

Deliver one thin, real path from the Arabic-first React shell through Laravel, MySQL, a transactional Outbox, and an internal notification. The path uses two development-only facility fixtures to prove backend-enforced isolation. It must not expand into full Organization, Identity, workflow, search, reporting, dashboard, or integration capabilities.

</domain>

<decisions>
## Implementation Decisions

### Development and runtime shape
- **D-01:** Use Git and external GitHub for development and work preservation only. GitHub may contain source, lockfiles, and revocable development secrets; it must not contain production secrets.
- **D-02:** Development machines may download packages from the Internet. Production servers must not connect to the Internet, GitHub, public registries, or other external sources at runtime or during deployment.
- **D-03:** The intended production runtime is Docker on one on-premises production server. This is an explicit deviation from the Kubernetes/GitOps target in the accepted architecture; planners must flag and govern the deviation rather than silently treating it as compliant.
- **D-04:** Use a separate internal MinIO instance for attachments and encrypted backups. Do not place the only copy of these assets on the Docker production server.

### Data recovery
- **D-05:** Run one MySQL instance on the Docker production server for the initial deployment. Do not introduce a three-node MySQL cluster now.
- **D-06:** Create encrypted MySQL backups to internal MinIO every 15 minutes; retain them for 30 days. Recovery is manual after a failure.
- **D-07:** The system administrator owns backup monitoring, recovery, and recovery evidence. The existing RPO/RTO and restore-test requirements remain binding.

### Release and supply chain
- **D-08:** At a future release, the system administrator alone approves the release bundle before it enters the internal environment.
- **D-09:** The isolated intake process, image signing, signing-key custody, signature verification, and approved deployment transport are deferred. They MUST be decided and implemented before the Phase 1 permanent deployment/exit gate can close.
- **D-10:** The expected future host procedure is to transfer a reviewed image and Compose manifest only through an approved internal channel, run `docker load`, then run `docker compose up -d`; retain a known previous version for rollback. This is a direction, not an approved replacement for the current GitOps requirement.

### Thin-path isolation proof
- **D-11:** Use two fixed development-only test accounts, one assigned to each of two test facilities. Backend authorization must prevent each account from reading the other's records.
- **D-12:** Use a fixed, direct-submit request form with only title and description. The record remains a published `request` WorkDefinition/WorkRecord, never a separate Requests module.

### the agent's Discretion
- Define the smallest notification recipient and presentation that proves the persisted request emits an Outbox event and an idempotent worker creates an internal notification. Do not add identity, organization-management, or workflow capabilities from later phases.
- Select the technical implementation, test fixtures, API adapters, and local Docker development details consistent with the canonical contracts and module boundaries.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope and requirements
- `.planning/PROJECT.md` — project-wide constraints, including the modular monolith, air gap, authorization, and recovery rules.
- `.planning/ROADMAP.md` — Phase 1 goal, entry gate, exit gate, and canonical references.
- `.planning/REQUIREMENTS.md` — the Phase 1 requirements: `FR-R1-013`, `SEC-R1-001`, `SEC-R1-009`, `SEC-R1-011`, `OPS-R1-006`, `OPS-R1-007`, `OPS-R1-008`, `OPS-R1-011`, and `OPS-R1-012`.
- `docs/plans/implementation-roadmap.md` §§4-5, 8 — accepted implementation gates and deferred-decision handling.
- `docs/plans/release-1-platform.md` §3 W1.1 — the thin path, exit criteria, exclusions, and evidence required.

### Architecture and module boundaries
- `docs/architecture/overview.md` — Laravel modular monolith, unified React shell, and WorkRecord direction.
- `docs/architecture/module-catalog.md` — `WorkDefinitions`, `WorkRecords`, `Notifications`, and the prohibition on a Requests module.
- `docs/architecture/dependency-rules.md` — module DAG, transaction ownership, transactional Outbox, and no cross-module persistence access.
- `docs/engineering/vertical-slices.md` — required vertical-slice shape.
- `docs/engineering/coding-and-module-boundaries.md` — contract/event-only module collaboration rules.
- `docs/adr/009-unified-react-shell.md` — one Arabic-first React and TypeScript shell.

### Contracts and validation
- `docs/contracts/api/openapi.yaml` — HTTP contract and authentication boundary.
- `docs/contracts/events/asyncapi.yaml` — Outbox, stream, consumer, and DLQ contract.
- `docs/contracts/module-contracts.md` — idempotency, concurrency, Outbox, and Inbox rules.
- `docs/contracts/schemas/work-record-submitted.schema.json` — submitted-record event schema.
- `docs/contracts/schemas/access-decision.schema.json` — authorization decision contract.
- `scripts/validate-docs.sh` and `.gitlab-ci.yml` — currently implemented repository validation and CI patterns.

### Operations and release gate
- `docs/adr/018-air-gapped-supply-chain.md` — mandatory air-gap, internal-artifact, signing, SBOM, and egress requirements.
- `docs/adr/019-kubernetes-resilience-and-recovery.md` — accepted resilience and recovery direction.
- `docs/operations/air-gap-supply-chain.md` — intake, signing, key-custody, and internal-registry controls that remain open for the future release path.
- `docs/operations/kubernetes-platform.md` — accepted Kubernetes, GitOps, MySQL HA, and operational ownership target; the Docker decision requires an explicit governed deviation.
- `docs/operations/ha-dr-backup.md` — backup, RPO/RTO, and restore-test requirements.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `scripts/validate-docs.sh` — existing documentation, contract, link, and repository validation baseline.
- `scripts/render-diagrams.sh` — existing Mermaid rendering workflow.
- `docs/contracts/api/openapi.yaml`, `docs/contracts/events/asyncapi.yaml`, and `docs/contracts/schemas/` — machine-readable starting contracts for the thin path.

### Established Patterns
- The repository has no Laravel, React, Docker, Kubernetes, or product CI implementation yet; this phase establishes the first product code surface.
- Target code uses module-owned vertical slices, contracts/events across module boundaries, source-state-plus-Outbox transactions, and idempotent derived consumers.
- Current CI validates documentation only; product tests and architecture-boundary checks must be introduced by Phase 1 planning rather than assumed to exist.

### Integration Points
- The new backend must conform to the OpenAPI, AsyncAPI, and JSON Schema contracts already under `docs/contracts/`.
- The new frontend is one Arabic-first React shell; it does not receive a separate administration application.

</code_context>

<specifics>
## Specific Ideas

- Keep the first deployment and database model simple: one on-premises Docker server and one MySQL instance, with MinIO kept separate for backups and attachments.
- Avoid involving the user in low-level fixture and notification details; choose the smallest verifiable design and validate it during planning and execution.
- The immediate priority is building and preserving development work with Git and GitHub. Permanent isolated release operation is deliberately held open rather than bypassed.

</specifics>

<deferred>
## Deferred Ideas

- Finalize the internal intake workflow, signing implementation, signing-key custody, signature verification, internal registry/mirror, and approved release transport before closing the Phase 1 permanent deployment gate.
- Resolve or formally supersede the accepted Kubernetes/GitOps requirement before treating the Docker-on-one-server runtime as a compliant production platform.
- Full organization, identity, authorization-policy management, workflows, search, reporting, dashboards, and integrations remain in their assigned later phases.

</deferred>

---

*Phase: 1-W1.1 Walking Skeleton*
*Context gathered: 2026-07-15*
