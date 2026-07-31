<?php

declare(strict_types=1);

namespace Modules\Authorization\Features\Administration\Contracts;

/**
 * Marker contract documenting the shape of the audit event payload that
 * {@see \Modules\Authorization\Features\Administration\Application\AuthorizationAdminService}
 * emits through the shared port. The service intentionally calls the
 * Shared\Contracts\RecordAuditEvent port directly with a plain associative
 * array so the foreign module stays rank-correct and never imports
 * Modules\Audit symbols. This interface exists only to anchor the
 * documentation and any future schema migration.
 */
interface RoleMutationAuditContext
{
    /**
     * @var array<string, string>
     */
    public const ACTION_TOKENS = [
        'role_created' => 'authorization.role.created',
        'role_updated' => 'authorization.role.updated',
        'role_archived' => 'authorization.role.archived',
        'role_cloned' => 'authorization.role.cloned',
        'assignment_created' => 'authorization.assignment.created',
        'assignment_updated' => 'authorization.assignment.updated',
        'assignment_revoked' => 'authorization.assignment.revoked',
        'assignment_expired' => 'authorization.assignment.expired',
        'role_capability_revoked' => 'authorization.role_capability.revoked',
    ];

    /**
     * @var array<string, string>
     */
    public const EVENT_TYPES = [
        'role_created' => 'com.cluster.authorization.rolecreated.v1',
        'role_updated' => 'com.cluster.authorization.roleupdated.v1',
        'role_archived' => 'com.cluster.authorization.rolearchived.v1',
        'role_cloned' => 'com.cluster.authorization.rolecloned.v1',
        'assignment_created' => 'com.cluster.authorization.assignmentcreated.v1',
        'assignment_updated' => 'com.cluster.authorization.assignmentupdated.v1',
        'assignment_revoked' => 'com.cluster.authorization.assignmentrevoked.v1',
        'assignment_expired' => 'com.cluster.authorization.assignmentexpired.v1',
        'role_capability_revoked' => 'com.cluster.authorization.rolecapabilityrevoked.v1',
    ];
}
