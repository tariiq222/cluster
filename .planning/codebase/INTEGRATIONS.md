# External Integrations

**Analysis Date:** 2026-07-15

## Current Integration Status

- No product runtime, integration adapter, deployed endpoint, database connection, queue client, storage client, or infrastructure manifest is implemented in this repository. The active integration surface is documentation and versioned contracts under `docs/`.
- Release 1 explicitly has no external business-system integration or automated data migration in `docs/architecture/overview.md` and `docs/governance/assumptions-constraints.md`.
- The approved HTTP and event contracts in `docs/contracts/api/openapi.yaml` and `docs/contracts/events/asyncapi.yaml` describe intended internal boundaries. Their `cluster.example` hostnames are examples, not configured environments.
- Operations documents under `docs/operations/` are proposed target designs and state that product names represent required internal roles rather than proof that services are provisioned.

## APIs & External Services

**Internal HTTP API Contract:**
- Cluster Platform REST API - Intended authenticated interface for the unified React client and internal users.
  - SDK/Client: Not implemented; OpenAPI 3.1 source is `docs/contracts/api/openapi.yaml`.
  - Auth: Bearer JWT is declared in `docs/contracts/api/openapi.yaml`; no key, issuer, secret name, or deployed URL is configured.
  - Endpoint status: `https://api.cluster.example/api/v1` in `docs/contracts/api/openapi.yaml` is a placeholder contract server.

**Internal Event Transport:**
- Valkey-compatible Streams - Intended delivery of CloudEvents JSON through transactional Outbox, consumer groups, Inbox deduplication, and DLQ.
  - SDK/Client: Not selected or implemented; the AsyncAPI 3.1 contract is `docs/contracts/events/asyncapi.yaml`.
  - Auth: Not specified in the checked-in AsyncAPI contract.
  - Endpoint status: `events.cluster.example:6379` in `docs/contracts/events/asyncapi.yaml` is a placeholder contract server; Valkey is a target service in `docs/operations/kubernetes-platform.md`.

**Business Systems:**
- None in Release 1 - HR system «موارد», financial systems, procurement, and clinical systems remain outside the platform boundary in `docs/architecture/context-map.md` and `docs/governance/assumptions-constraints.md`.
- No SSO or cloud identity provider - Local identity is the accepted decision in `docs/adr/012-local-identity-and-session-security.md` and `docs/domain/identity.md`.
- No email, SMS, or WhatsApp gateway - Notifications are in-application only in `docs/domain/notifications-search-reporting.md`.
- No external webhook or arbitrary URL execution - The work-definition DSL forbids network requests, URLs, webhooks, and external integrations in `docs/engineering/definition-dsl.md`.

**Internal Security Services:**
- Malware scanning - Uploaded files must use an internally signed AV engine and internally mirrored signatures, but no scanner product or client is selected in `docs/data-security/file-security.md`.
- Internal sandbox/YARA - Required for suspicious-file handling by `docs/data-security/file-security.md`; no implementation or deployment configuration is present.
- Internal KMS/PKI - Document encryption requires internal KMS semantics in `docs/data-security/file-security.md`, while platform certificates and secret rotation target Vault in `docs/operations/kubernetes-platform.md`; no concrete endpoint is configured.

**Development Tooling:**
- GitLab CI - The only implemented remote automation contract is the documentation pipeline in `.gitlab-ci.yml`; it validates docs, builds MkDocs output, and conditionally validates Mermaid.
- OpenCode/GSD - `.opencode/opencode.json` launches a local Node MCP server and `.opencode/package.json` installs `@opencode-ai/plugin` 1.18.1. This is developer tooling, not a production integration.
- Public package metadata remains in `.opencode/package-lock.json` as npm registry URLs. `.opencode/.gitignore` excludes that tooling lockfile; it must not be treated as an approved product air-gap source.

## Data Storage

**Databases:**
- MySQL InnoDB Cluster - Intended single operational source of truth with HA and PITR.
  - Connection: No environment variable, DSN, credential, migration, schema, or client configuration exists in the repository.
  - Client: Future Laravel/Eloquent direction is implied by the Laravel choice in `docs/architecture/overview.md`; no Composer package or ORM code is present.
  - Status: Target requirement in `docs/operations/kubernetes-platform.md`, not a deployed integration.

**File Storage:**
- S3-compatible object storage or MinIO - Intended for quarantine, available documents, archives, exports, and backups according to `docs/data-security/file-security.md` and `docs/operations/ha-dr-backup.md`.
- Object Lock/WORM, SSE-KMS, TLS, separate service accounts, and separate prefixes/security zones are required by `docs/data-security/file-security.md`; provider, endpoint, bucket names, credentials, and SDK are not selected.
- Generated local documentation artifacts only are currently written to ignored `site/` and `build/` paths by `mkdocs.yml`, `.gitignore`, and `scripts/render-diagrams.sh`; they are not product file storage.

**Caching:**
- Valkey HA - Intended cache and queue/Streams service in `docs/operations/kubernetes-platform.md`; no client, connection variable, topology, or deployed instance is configured.

**Search:**
- OpenSearch - Intended internal user-facing search engine in `docs/operations/kubernetes-platform.md`.
- Search data is a rebuildable, authorization-filtered projection rather than a source of truth, as required by `docs/domain/notifications-search-reporting.md`.
- No OpenSearch client dependency, index mapping, endpoint, credential, or deployment manifest exists.

**Backups:**
- Independent encrypted S3-compatible/MinIO repository with Object Lock/WORM - Required outside the Kubernetes failure domain by `docs/operations/ha-dr-backup.md`.
- No backup product configuration, repository URL, retention value, or credentials are checked in; detailed product/location decisions remain gated in `docs/plans/implementation-roadmap.md`.

## Authentication & Identity

**Auth Provider:**
- Custom local identity - Accepted in `docs/adr/012-local-identity-and-session-security.md`; external SSO and cloud identity are excluded by `docs/domain/identity.md`.
  - Implementation: Not present. The target stores Argon2id password hashes, uses short httpOnly sessions/tokens, enforces MFA for administrative accounts, dual-admin recovery, and governed break-glass access.
  - API contract: Bearer JWT security scheme is declared in `docs/contracts/api/openapi.yaml`.
  - Session detail status: JWT HS256 and cookie/session details are drafted in `docs/data-security/identity-session-security.md`, not implemented configuration.
  - Secrets: No signing key, MFA provider, session-store configuration, or environment-variable name is defined.

**Authorization:**
- Central RBAC + ABAC - The backend must make explainable access decisions for API, search, reports, exports, and downloads according to `docs/architecture/overview.md` and `docs/adr/004-authorization-and-isolation.md`.
- Authorization is an internal module contract, not an external identity integration; no service endpoint or client exists.

## Monitoring & Observability

**Error Tracking:**
- No SaaS or standalone error-tracking product is selected or configured. External SaaS/runtime dependencies are prohibited by `docs/governance/assumptions-constraints.md`.

**Logs:**
- Loki - Named target for internal operational logs in `docs/operations/kubernetes-platform.md`; no Loki endpoint, agent, library, dashboard, or retention configuration exists.
- Application logs must be centralized and correlated without secrets, PII, or sensitive payloads according to `docs/operations/observability-and-slos.md`.

**Metrics & Traces:**
- Internal metrics, traces, dashboards, and alerts are required by `docs/operations/observability-and-slos.md`.
- Prometheus and Grafana are roadmap defaults to be decided before W1.5 in `docs/plans/implementation-roadmap.md`; neither is configured in the repository.
- Required monitoring covers API, workers, MySQL, Valkey, OpenSearch, Loki, object storage, Kubernetes, and security controls in `docs/operations/observability-and-slos.md`.

## CI/CD & Deployment

**Hosting:**
- On-premises, air-gapped Kubernetes in the Third Health Cluster data center is mandatory in `docs/architecture/overview.md`.
- Use an internally managed enterprise Kubernetes platform when approved; otherwise use a three-control-plane-node RKE2 fallback according to `docs/operations/kubernetes-platform.md`.
- The target topology includes multiple Laravel Web/API and worker replicas, one scheduler or leader-elected scheduler, MySQL HA, Valkey HA, object storage, OpenSearch, Loki, Vault, and independent backup storage in `docs/architecture/overview.md`.
- No Dockerfile, Compose file, image reference for the product, Helm/Kustomize definition, Kubernetes manifest, or GitOps repository configuration exists.

**CI Pipeline:**
- GitLab CI is implemented only for documentation in `.gitlab-ci.yml`.
- `validate-docs` runs `scripts/validate-docs.sh`; `build-docs` runs strict MkDocs and retains `site/`; `validate-mermaid` runs only when `MERMAID_IMAGE` is set in `.gitlab-ci.yml`.
- Current custom validation is green: `./scripts/validate-docs.sh` passed on 2026-07-15, including the corrected readiness-checklist fragments in `docs/plans/readiness-checklist.md` and their release-plan anchors.
- A local strict MkDocs build was not run because MkDocs is not installed on the inspected workstation; the configured CI gate remains `python -m mkdocs build --strict` in `.gitlab-ci.yml`.
- The future product pipeline requires validate/test/package/verify/publish gates, SBOM, provenance, signatures, vulnerability/license/secret scans, and GitOps promotion according to `docs/engineering/ci-cd-and-release.md`; none of those product stages is implemented.
- The current CI file uses the generic `python:3.12-slim` default in `.gitlab-ci.yml`; the repository does not encode the internal registry/mirror mapping required by `docs/operations/air-gap-supply-chain.md`.

**Artifact Supply Chain:**
- GitLab, Harbor, and Nexus describe required internal roles for source/CI, OCI registry, and Composer/npm mirrors in `docs/operations/air-gap-supply-chain.md`; those names are not proof of provisioned services.
- Argo CD or another approved GitOps controller is a future deployment option in `docs/engineering/ci-cd-and-release.md`; no controller is selected in executable configuration.
- Runtime admission must accept only internally sourced, signed OCI images tied to digest, SBOM, and provenance according to `docs/operations/kubernetes-platform.md`.

## Environment Configuration

**Required env vars:**
- `DOCS_PYTHON_IMAGE` - Has a default value in `.gitlab-ci.yml` and controls the Python image for documentation jobs.
- `MERMAID_IMAGE` - Optional CI variable in `.gitlab-ci.yml`; when absent, the Mermaid validation job is skipped.
- Product runtime env vars: Not defined. No Laravel/React manifest, `.env.example`, DSN variable, storage variable, queue variable, search variable, or observability variable exists.

**Secrets location:**
- No `.env` or `.env.*` file was detected in the repository; no secret values were read or recorded.
- Future runtime secrets and signing credentials belong in internal Vault with least privilege and rotation according to `docs/operations/kubernetes-platform.md` and `docs/operations/air-gap-supply-chain.md`.
- Environment-specific hosts, addresses, and secrets are held in a governed configuration repository outside these documents according to `docs/operations/README.md`.
- Kubernetes manifests and CI logs must not contain secrets according to `docs/operations/kubernetes-platform.md` and `docs/engineering/ci-cd-and-release.md`.

## Webhooks & Callbacks

**Incoming:**
- None implemented. No webhook route, controller, signature scheme, external callback contract, or product runtime exists.
- Release 1 prohibits external integrations in `docs/governance/assumptions-constraints.md`; the OpenAPI contract in `docs/contracts/api/openapi.yaml` exposes first-party platform APIs rather than webhook receivers.

**Outgoing:**
- None implemented or approved for Release 1. `docs/engineering/definition-dsl.md` explicitly forbids webhook, URL, and network execution from definitions.
- Email, SMS, and WhatsApp are excluded; `Notifications` produces in-application messages only according to `docs/domain/notifications-search-reporting.md`.
- Internal asynchronous effects use the Outbox-to-Valkey Streams contract in `docs/contracts/events/asyncapi.yaml`, not external callbacks.

## Air-Gap Integration Constraints

- Build and runtime must have no internet access, CDN, public fonts/scripts, external DNS, external image pulls, or SaaS license checks according to `docs/governance/assumptions-constraints.md`.
- Dependencies and base images must be imported through a controlled intake zone, scanned, approved, mirrored internally, pinned by lockfile/digest, and signed before use according to `docs/operations/air-gap-supply-chain.md`.
- Default-deny NetworkPolicy must permit only required internal flows between ingress, API/workers, state services, GitOps, and Kubernetes API according to `docs/operations/kubernetes-platform.md`.
- AV signatures, package updates, and recovery artifacts must be mirrored and signed internally before the external path is cut, as required by `docs/data-security/file-security.md` and `docs/operations/air-gap-supply-chain.md`.
- Any future external business integration requires a new explicit system/data/direction/owner/security decision before an adapter or contract is added, according to `docs/architecture/c4-and-flows.md`.

---

*Integration audit: 2026-07-15*
