<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox;

/**
 * Catalogue of every `event_type` string produced by Cluster modules into
 * the shared `outbox_events` table.
 *
 * This enum is the single source of truth for the outbox contract:
 *  - Producer modules reference one of these cases instead of inline
 *    string literals, so a typo or rename is caught by static analysis.
 *  - The architecture test in
 *    `Tests\Architecture\ModuleBoundariesTest::test_every_event_type_in_outbox_has_a_matching_json_schema`
 *    scans producer code for `com.cluster.*.v<n>` literals and asserts
 *    that each one matches a case in this enum and that the
 *    corresponding JSON schema file exists under
 *    `docs/contracts/schemas/`.
 *  - `schemaPath()` returns the path to the schema file the producer
 *    must document; removing or renaming a case requires updating the
 *    schema file at the same time.
 *
 * Adding a new event type: add the case here, write the JSON schema
 * file at the path returned by `schemaPath()`, and reference the case
 * from the producer. Removing an event type requires a migration of any
 * consumer first; the architecture test will fail if a producer still
 * emits a removed-case literal.
 */
enum OutboxEventType: string
{
    // ── Organization (cluster → facility → unit → position → assignment) ──
    case OrganizationClusterCreated = 'com.cluster.organization.clustercreated.v1';
    case OrganizationClusterUpdated = 'com.cluster.organization.clusterupdated.v1';
    case OrganizationFacilityCreated = 'com.cluster.organization.facilitycreated.v1';
    case OrganizationFacilityUpdated = 'com.cluster.organization.facilityupdated.v1';
    case OrganizationFacilityArchived = 'com.cluster.organization.facilityarchived.v1';
    case OrganizationUnitCreated = 'com.cluster.organization.organizationunitcreated.v1';
    case OrganizationUnitMoved = 'com.cluster.organization.organizationunitmoved.v1';
    case OrganizationUnitArchived = 'com.cluster.organization.organizationunitarchived.v1';
    case OrganizationUnitUpdated = 'com.cluster.organization.organizationunitupdated.v1';
    case OrganizationUnitsReordered = 'com.cluster.organization.organizationunitsreordered.v1';
    case OrganizationPositionCreated = 'com.cluster.organization.positioncreated.v1';
    case OrganizationPositionUpdated = 'com.cluster.organization.positionupdated.v1';
    case OrganizationJobTitleCreated = 'com.cluster.organization.jobtitlecreated.v1';
    case OrganizationAssignmentStarted = 'com.cluster.organization.assignmentstarted.v1';
    case OrganizationAssignmentEnded = 'com.cluster.organization.assignmentended.v1';
    case OrganizationTemporaryAssignmentCreated = 'com.cluster.organization.temporaryassignmentcreated.v1';
    case OrganizationTemporaryAssignmentRevoked = 'com.cluster.organization.temporaryassignmentrevoked.v1';
    case OrganizationTemporaryAssignmentExpired = 'com.cluster.organization.temporaryassignmentexpired.v1';
    case OrganizationPersonRegistered = 'com.cluster.organization.personregistered.v1';
    case OrganizationPersonUpdated = 'com.cluster.organization.personupdated.v1';
    case OrganizationIdentityProvisioningRequested = 'com.cluster.organization.identityprovisioningrequested.v1';
    case OrganizationPersonAccessStatusChanged = 'com.cluster.organization.personaccessstatuschanged.v1';
    case OrganizationImportJobSubmitted = 'com.cluster.organization.importjobsubmitted.v1';
    case OrganizationImportJobValidated = 'com.cluster.organization.importjobvalidated.v1';
    case OrganizationImportJobApplied = 'com.cluster.organization.importjobapplied.v1';
    case OrganizationImportJobApproved = 'com.cluster.organization.importjobapproved.v1';
    case OrganizationImportJobRejected = 'com.cluster.organization.importjobrejected.v1';
    case OrganizationImportJobCancelled = 'com.cluster.organization.importjobcancelled.v1';
    case OrganizationImportJobFailed = 'com.cluster.organization.importjobfailed.v1';

    // ── Identity ──────────────────────────────────────────────────────────
    case IdentityUserAccountCreated = 'com.cluster.identity.useraccountcreated.v1';
    case IdentityUserAccountChanged = 'com.cluster.identity.useraccountchanged.v1';
    case IdentityAuthenticationFailed = 'com.cluster.identity.authentication_failed.v1';
    case IdentityAccountLoginLocked = 'com.cluster.identity.account_login_locked.v1';
    case IdentityAccountActivated = 'com.cluster.identity.account_activated.v1';
    case IdentityActivationTokenIssued = 'com.cluster.identity.activation_token_issued.v1';
    case IdentityAuthenticationSucceeded = 'com.cluster.identity.authentication_succeeded.v1';
    case IdentityCredentialCreated = 'com.cluster.identity.credential_created.v1';
    case IdentityPasswordChanged = 'com.cluster.identity.password_changed.v1';
    case IdentitySessionCreated = 'com.cluster.identity.session_created.v1';
    case IdentitySessionRevoked = 'com.cluster.identity.session_revoked.v1';
    case IdentitySessionsRevoked = 'com.cluster.identity.sessions_revoked.v1';
    case IdentityTotpEnrollmentStarted = 'com.cluster.identity.totp_enrollment_started.v1';
    case IdentityTotpEnabled = 'com.cluster.identity.totp_enabled.v1';

    // ── WorkRecords ───────────────────────────────────────────────────────
    case WorkRecordSubmitted = 'com.cluster.workrecord.submitted.v1';
    case WorkRecordInvalid = 'com.cluster.workrecord.invalid.v1';

    // ── Documents ─────────────────────────────────────────────────────────
    case DocumentUploadInitiated = 'com.cluster.documents.uploadinitiated.v1';
    case DocumentVersionUploaded = 'com.cluster.documents.versionuploaded.v1';
    case DocumentVersionRejected = 'com.cluster.documents.versionrejected.v1';
    case DocumentVersionQuarantined = 'com.cluster.documents.versionquarantined.v1';
    case DocumentVersionPromotionRequested = 'com.cluster.documents.versionpromotionrequested.v1';
    case DocumentVersionAvailable = 'com.cluster.documents.versionavailable.v1';

    // ── PlatformSettings ──────────────────────────────────────────────────
    case PlatformSettingVersionPublished = 'com.cluster.platform-settings.version-published.v1';
    case PlatformTechnicalAlert = 'com.cluster.platform.technical-alert.v1';
    case PlatformOperationsBackupRequested = 'com.cluster.platform-operations.backup-requested.v1';

    /**
     * Path to the JSON schema document for this event type under the
     * `docs/contracts/schemas/` directory. The architecture test asserts
     * that this file exists, so any case returned from this method must
     * have a corresponding schema file on disk.
     */
    public function schemaPath(): string
    {
        $slug = str_replace('.', '-', $this->value);

        return "docs/contracts/schemas/{$slug}.schema.json";
    }

    /**
     * Redis stream name that the Organization outbox relay uses to
     * forward events of this type to downstream consumers. Format:
     * `platform.<module>-<name>` (the case's `v<n>` version suffix is
     * stripped because the stream is version-agnostic).
     */
    public function streamName(): string
    {
        $parts = explode('.', $this->value);
        array_shift($parts); // com
        array_shift($parts); // cluster

        return 'platform.'.implode('-', array_slice($parts, 0, -1));
    }
}
