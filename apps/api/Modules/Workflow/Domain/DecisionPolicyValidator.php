<?php

namespace Modules\Workflow\Domain;

use InvalidArgumentException;

final class DecisionPolicyValidator
{
    /** @var list<string> */
    private const ASSIGNMENT_RULES = [
        'supervisor_of_initiator',
        'supervisor_of_step',
        'role',
    ];

    /**
     * @return array{allowed: bool, single_member_bootstrap_approval: bool, reason: ?string}
     */
    public function approval(
        ?string $authorUserId,
        string $actorUserId,
        int $activeOfficeMembers,
        bool $actorIsOfficeMember,
        bool $isSystem,
    ): array {
        if ($isSystem) {
            return [
                'allowed' => false,
                'single_member_bootstrap_approval' => false,
                'reason' => 'system_path_readonly',
            ];
        }
        if (! $actorIsOfficeMember || $activeOfficeMembers < 1) {
            return [
                'allowed' => false,
                'single_member_bootstrap_approval' => false,
                'reason' => 'operations_office_membership_required',
            ];
        }
        if ($authorUserId !== null && hash_equals($authorUserId, $actorUserId)) {
            if ($activeOfficeMembers >= 2) {
                return [
                    'allowed' => false,
                    'single_member_bootstrap_approval' => false,
                    'reason' => 'self_approval_forbidden',
                ];
            }

            return [
                'allowed' => true,
                'single_member_bootstrap_approval' => true,
                'reason' => 'SINGLE_MEMBER_BOOTSTRAP: author approved while the operations office had one active member',
            ];
        }

        return [
            'allowed' => true,
            'single_member_bootstrap_approval' => false,
            'reason' => null,
        ];
    }

    /** @param array<string, mixed> $graph */
    public function assertPublishableGraph(array $graph): void
    {
        foreach ($this->orderedSteps($graph) as $step) {
            $this->assignmentRule($step);
        }
    }

    /**
     * Converts the persisted linear graph into its ordered approval-step list.
     *
     * @param array<string, mixed> $graph
     * @return list<array<string, mixed>>
     */
    public function orderedSteps(array $graph): array
    {
        $rawNodes = $graph['nodes'] ?? $graph;
        if (! is_array($rawNodes)) {
            throw new InvalidArgumentException('workflow_graph_nodes_invalid');
        }

        $nodes = [];
        $sourceOrder = [];
        foreach ($rawNodes as $index => $node) {
            if (! is_array($node)) {
                throw new InvalidArgumentException('workflow_graph_node_invalid');
            }
            $key = $node['key'] ?? null;
            if (! is_string($key) || trim($key) === '' || isset($nodes[$key])) {
                throw new InvalidArgumentException('workflow_graph_node_key_invalid');
            }
            $nodes[$key] = $node;
            $sourceOrder[(string) $index] = $key;
        }

        $transitions = $graph['transitions'] ?? null;
        if (! is_array($transitions) || $transitions === []) {
            return array_values(array_filter(
                array_map(static fn (string $key): array => $nodes[$key], $sourceOrder),
                fn (array $node): bool => $this->isStep($node),
            ));
        }

        $outgoing = [];
        foreach ($transitions as $transition) {
            if (! is_array($transition)
                || ! is_string($transition['from'] ?? null)
                || ! is_string($transition['to'] ?? null)
                || ! isset($nodes[$transition['from']], $nodes[$transition['to']])) {
                throw new InvalidArgumentException('workflow_graph_transition_invalid');
            }
            $from = $transition['from'];
            if (isset($outgoing[$from])) {
                throw new InvalidArgumentException('workflow_graph_must_be_linear');
            }
            $outgoing[$from] = $transition['to'];
        }

        $startKeys = array_keys(array_filter(
            $nodes,
            static fn (array $node): bool => ($node['type'] ?? null) === 'start',
        ));
        if (count($startKeys) !== 1) {
            throw new InvalidArgumentException('workflow_graph_start_invalid');
        }

        $ordered = [];
        $visited = [];
        $current = $startKeys[0];
        while (isset($outgoing[$current])) {
            if (isset($visited[$current])) {
                throw new InvalidArgumentException('workflow_graph_cycle_invalid');
            }
            $visited[$current] = true;
            $current = $outgoing[$current];
            $node = $nodes[$current];
            if (($node['type'] ?? null) === 'end') {
                break;
            }
            if (! $this->isStep($node)) {
                throw new InvalidArgumentException('workflow_graph_node_type_invalid');
            }
            $ordered[] = $node;
        }

        if (($nodes[$current]['type'] ?? null) !== 'end') {
            throw new InvalidArgumentException('workflow_graph_end_invalid');
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{type: string, step_key?: string, role_code?: string}|null
     */
    public function assignmentRule(array $node): ?array
    {
        $rule = $node['assignment_rule'] ?? ($node['configuration']['assignment_rule'] ?? null);
        if ($rule === null) {
            return null;
        }

        if (is_string($rule)) {
            if (str_starts_with($rule, 'role:')) {
                $rule = ['type' => 'role', 'role_code' => substr($rule, 5)];
            } elseif (str_starts_with($rule, 'supervisor_of_step:')) {
                $rule = ['type' => 'supervisor_of_step', 'step_key' => substr($rule, 19)];
            } else {
                $rule = ['type' => $rule];
            }
        }
        if (! is_array($rule)) {
            throw new InvalidArgumentException('workflow_assignment_rule_invalid');
        }

        $type = $rule['type'] ?? $rule['rule'] ?? null;
        if (! is_string($type) || ! in_array($type, self::ASSIGNMENT_RULES, true)) {
            throw new InvalidArgumentException('workflow_assignment_rule_unknown');
        }

        $normalized = ['type' => $type];
        if ($type === 'supervisor_of_step') {
            $stepKey = $rule['step_key'] ?? null;
            if ($stepKey !== null && (! is_string($stepKey) || trim($stepKey) === '')) {
                throw new InvalidArgumentException('workflow_assignment_rule_invalid');
            }
            if (is_string($stepKey)) {
                $normalized['step_key'] = $stepKey;
            }
        }
        if ($type === 'role') {
            $roleCode = $rule['role_code'] ?? $rule['role'] ?? null;
            if (! is_string($roleCode) || preg_match('/\A[a-z][a-z0-9_.-]{1,95}\z/', $roleCode) !== 1) {
                throw new InvalidArgumentException('workflow_assignment_rule_invalid');
            }
            $normalized['role_code'] = $roleCode;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $node */
    private function isStep(array $node): bool
    {
        return in_array($node['type'] ?? null, ['approval', 'decision', 'task'], true);
    }
}
