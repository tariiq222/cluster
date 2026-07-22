<?php

namespace Modules\Workflow\Contracts;

final readonly class RuleSpec
{
    /** @param array<string, mixed> $arguments */
    public function __construct(public string $type, public array $arguments = []) {}

    public static function fromNode(array $node): ?self
    {
        $rule = $node['assignment_rule'] ?? ($node['configuration']['assignment_rule'] ?? null);
        if ($rule === null) {
            return null;
        }
        if (is_string($rule)) {
            if (str_starts_with($rule, 'role:')) {
                return new self('role', ['role_code' => substr($rule, 5)]);
            }
            if (str_starts_with($rule, 'supervisor_of_step:')) {
                return new self('supervisor_of_step', ['step_index' => (int) substr($rule, 19)]);
            }
            return new self($rule);
        }
        if (! is_array($rule) || ! is_string($rule['type'] ?? $rule['rule'] ?? null)) {
            return null;
        }

        $type = (string) ($rule['type'] ?? $rule['rule']);
        $arguments = $rule;
        unset($arguments['type'], $arguments['rule']);

        return new self($type, $arguments);
    }
}
