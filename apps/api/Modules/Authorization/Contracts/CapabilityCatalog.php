<?php

namespace Modules\Authorization\Contracts;

final class CapabilityCatalog
{
    /** @var list<string> */
    private const CAPABILITIES = [
        'work_record.submit',
        'work_record.read',
        'work_record.list',
        'organization.cluster.manage',
        'organization.cluster.read',
        'organization.facility.manage',
        'organization.facility.read',
        'organization.unit.manage',
        'organization.unit.read',
        'organization.position.manage',
        'organization.position.read',
        'organization.person.manage',
        'organization.person.read',
        'organization.person.reference',
        'organization.assignment.manage',
        'organization.assignment.read',
        'organization.import.manage',
        'organization.import.approve',
        'organization.import.read',
        'organization.temporary-assignment.manage',
        'organization.temporary-assignment.read',
        'identity.account.manage',
        'identity.account.read',
        'documents.initiate-upload',
        'documents.complete-upload',
        'documents.get-upload-status',
        'documents.scan-version',
        'documents.reconcile-promotion',
        'documents.link',
        'documents.download',
    ];

    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::CAPABILITIES;
    }

    public static function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }
}
