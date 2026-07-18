<?php

namespace Modules\Authorization\Tests;

use Modules\Authorization\Contracts\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

class CapabilityCatalogTest extends TestCase
{
    public function test_all_returns_complete_fixture_capability_set(): void
    {
        $expected = [
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
        ];

        $this->assertSame($expected, CapabilityCatalog::all());
    }

    public function test_supports_returns_true_for_each_cataloged_capability(): void
    {
        foreach (CapabilityCatalog::all() as $capability) {
            $this->assertTrue(
                CapabilityCatalog::supports($capability),
                "Expected catalog to support '{$capability}'.",
            );
        }
    }

    public function test_supports_returns_false_for_unknown_capability(): void
    {
        $this->assertFalse(CapabilityCatalog::supports('work_record.delete'));
        $this->assertFalse(CapabilityCatalog::supports('unknown.capability'));
        $this->assertFalse(CapabilityCatalog::supports(''));
    }

    public function test_supports_is_strict_and_case_sensitive(): void
    {
        $this->assertFalse(CapabilityCatalog::supports('WORK_RECORD.SUBMIT'));
        $this->assertFalse(CapabilityCatalog::supports('work_record.Submit'));
        $this->assertFalse(CapabilityCatalog::supports('work_record.submit '));
    }
}
