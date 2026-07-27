<?php

namespace Modules\Authorization\Tests;

use Modules\Authorization\Contracts\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

class CapabilityCatalogTest extends TestCase
{
    public function test_all_returns_complete_fixture_capability_set(): void
    {
        $expected = [
            'work_record.create',
            'work_record.read',
            'work_record.list',
            'work_record.update',
            'work_record.submit',
            'work_record.return',
            'work_record.complete',
            'work_record.cancel',
            'work_record.archive',
            'work_definition.create',
            'work_definition.read',
            'work_definition.list',
            'work_definition.update',
            'work_definition.publish',
            'work_definition.retire',
            'workflow.read',
            'workflow.list',
            'workflow.manage',
            'workflow.author',
            'workflow.approve',
            'workflow.decide',
            'workflow.reassign',
            'workflow.escalate',
            'workflow.cancel',
            'tasks.create',
            'tasks.read',
            'tasks.list',
            'tasks.update',
            'tasks.assign',
            'tasks.start',
            'tasks.complete',
            'tasks.cancel',
            'tasks.comment',
            'tasks.participant-manage',
            'documents.create',
            'documents.update',
            'documents.read',
            'documents.list',
            'documents.initiate-upload',
            'documents.complete-upload',
            'documents.get-upload-status',
            'documents.scan-version',
            'documents.reconcile-promotion',
            'documents.link',
            'documents.download',
            'documents.archive',
            'documents.hold',
            'documents.grant',
            'search.query',
            'reporting.read',
            'reporting.list',
            'reporting.run',
            'reporting.export',
            'reporting.download',
            'reporting.dashboard',
            'notifications.read',
            'notifications.manage',
            'identity.account.read',
            'identity.account.manage',
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
            'authorization.role.read',
            'authorization.role.manage',
            'authorization.capability.read',
            'authorization.capability.manage',
            'authorization.assignment.read',
            'authorization.assignment.manage',
            'authorization.delegation.read',
            'authorization.delegation.manage',
            'authorization.deny.read',
            'authorization.deny.manage',
            'authorization.policy.read',
            'authorization.policy.manage',
            'authorization.audit.read',
            'authorization.decision.read',
            'audit.event.read',
            'audit.event.export',
            'audit.integrity.verify',
            'strategy.plan.read',
            'strategy.plan.manage',
            'strategy.indicator.read',
            'strategy.indicator.manage',
            'strategy.measurement.submit',
            'strategy.measurement.approve',
            'strategy.impact.read',
            'portfolio_projects.portfolio.read',
            'portfolio_projects.portfolio.manage',
            'portfolio_projects.project.read',
            'portfolio_projects.project.manage',
            'portfolio_projects.milestone.approve',
            'portfolio_projects.impact.submit',
            'portfolio_projects.budget.read',
            'risk.risk.read',
            'risk.risk.manage',
            'risk.assess',
            'risk.control.manage',
            'risk.treatment.manage',
            'risk.accept',
            'risk.kri.manage',
            'platform_settings.read',
            'platform_settings.manage',
            'platform_settings.publish',
            'platform_settings.calendar.read',
            'platform_settings.calendar.manage',
            'platform_settings.calendar.override_official_holiday',
            'platform_operations.health.read',
            'platform_operations.backup.read',
            'platform_operations.backup.run',
            'platform_operations.restore.request',
            'platform_operations.restore.confirm',
            'platform_operations.logs.read',
            'platform_operations.logs.restore',
            'platform_operations.alerts.manage',
            'platform_operations.maintenance.manage',
            'platform_operations.maintenance.cancel',
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

    public function test_admin_resources_have_deny_by_default_capability_mappings(): void
    {
        $expected = [
            'roles' => ['authorization.role.read', 'authorization.role.manage'],
            'capabilities' => ['authorization.capability.read', 'authorization.capability.manage'],
            'role-capabilities' => ['authorization.assignment.read', 'authorization.assignment.manage'],
            'role-assignments' => ['authorization.assignment.read', 'authorization.assignment.manage'],
            'delegations' => ['authorization.delegation.read', 'authorization.delegation.manage'],
            'explicit-denies' => ['authorization.deny.read', 'authorization.deny.manage'],
            'classification-policies' => ['authorization.policy.read', 'authorization.policy.manage'],
            'field-access-templates' => ['authorization.policy.read', 'authorization.policy.manage'],
            'access-decisions' => ['authorization.decision.read', null],
            'audit' => ['authorization.audit.read', null],
            'unknown' => [null, null],
        ];

        foreach ($expected as $resource => [$read, $manage]) {
            $this->assertSame($read, CapabilityCatalog::adminRead($resource));
            $this->assertSame($manage, CapabilityCatalog::adminManage($resource));
        }
    }
}
