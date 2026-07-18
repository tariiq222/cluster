<?php

namespace Modules\Authorization\Domain;

use InvalidArgumentException;

final readonly class FieldAccessTemplate
{
    /**
     * @param  array<string, FieldDecision>  $fieldDecisions
     */
    public function __construct(
        public string $fieldPolicyKey,
        public string $moduleCode,
        public array $fieldDecisions,
        public string $policyVersion,
        public bool $isActive = true,
    ) {
        self::requireNonEmpty($fieldPolicyKey, 'Field policy key', 128);
        self::requireNonEmpty($moduleCode, 'Module code', 64);
        self::requireNonEmpty($policyVersion, 'Policy version', 32);

        if ($fieldDecisions === []) {
            throw new InvalidArgumentException('Field access template must define at least one field decision.');
        }

        foreach ($fieldDecisions as $fieldPath => $fieldDecision) {
            if (trim($fieldPath) === '' || strlen($fieldPath) > 255) {
                throw new InvalidArgumentException('Field decision path must contain between 1 and 255 characters.');
            }
        }
    }

    public function decisionFor(string $fieldPath): FieldDecision
    {
        return $this->fieldDecisions[$fieldPath] ?? FieldDecision::HIDE;
    }

    private static function requireNonEmpty(string $value, string $field, int $maximumLength): void
    {
        if (trim($value) === '' || strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("{$field} must contain between 1 and {$maximumLength} characters.");
        }
    }
}
