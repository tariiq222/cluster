<?php

namespace Modules\Authorization\Contracts;

final class CapabilityCatalog
{
    /** @var list<string> */
    private const CAPABILITIES = [
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
        'platform_settings.read', 'platform_settings.manage', 'platform_settings.publish',
        'platform_settings.calendar.read', 'platform_settings.calendar.manage',
        'platform_settings.calendar.override_official_holiday',
        'platform_operations.health.read', 'platform_operations.backup.read', 'platform_operations.backup.run',
        'platform_operations.restore.request', 'platform_operations.restore.confirm',
        'platform_operations.logs.read', 'platform_operations.logs.restore',
        'platform_operations.alerts.manage', 'platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel',
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

    public static function adminRead(string $resource): ?string
    {
        return self::adminCapability($resource, false);
    }

    public static function adminManage(string $resource): ?string
    {
        return self::adminCapability($resource, true);
    }

    private static function adminCapability(string $resource, bool $manage): ?string
    {
        $module = match ($resource) {
            'roles' => 'role',
            'capabilities' => 'capability',
            'role-capabilities', 'role-assignments' => 'assignment',
            'delegations' => 'delegation',
            'explicit-denies' => 'deny',
            'classification-policies', 'field-access-templates' => 'policy',
            'access-decisions' => 'decision',
            'audit' => 'audit',
            default => null,
        };
        if ($module === null) {
            return null;
        }

        $capability = 'authorization.'.$module.'.'.($manage ? 'manage' : 'read');

        return self::supports($capability) ? $capability : null;
    }
}
