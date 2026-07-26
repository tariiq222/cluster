<?php

namespace Modules\Authorization\Domain;

use InvalidArgumentException;

final readonly class FieldAccessTemplate
{
    /** @var array<string, FieldDecision> */
    public array $fieldDecisions;

    /**
     * @param  array<string, FieldDecision>  $fieldDecisions
     */
    public function __construct(
        public string $fieldPolicyKey,
        public string $moduleCode,
        array $fieldDecisions,
        public string $policyVersion,
        public bool $isActive = true,
    ) {
        self::requireNonEmpty($fieldPolicyKey, 'Field policy key', 128);
        self::requireNonEmpty($moduleCode, 'Module code', 64);
        self::requireNonEmpty($policyVersion, 'Policy version', 32);

        if ($fieldDecisions === []) {
            throw new InvalidArgumentException('Field access template must define at least one field decision.');
        }

        $normalized = [];
        foreach ($fieldDecisions as $fieldPath => $fieldDecision) {
            $normalizedPath = self::normalizeFieldPath($fieldPath);
            if (isset($normalized[$normalizedPath])) {
                throw new InvalidArgumentException("Field decision path {$normalizedPath} is defined more than once.");
            }
            $normalized[$normalizedPath] = $fieldDecision;
        }

        $this->fieldDecisions = $normalized;
    }

    public function decisionFor(string $fieldPath): FieldDecision
    {
        return $this->fieldDecisions[$fieldPath] ?? FieldDecision::HIDE;
    }

    public static function normalizeFieldPath(string $fieldPath): string
    {
        $fieldPath = trim($fieldPath);
        if ($fieldPath === '' || strlen($fieldPath) > 255) {
            throw new InvalidArgumentException('Field decision path must contain between 1 and 255 characters.');
        }

        return $fieldPath === '*' || str_starts_with($fieldPath, 'payload.')
            ? $fieldPath
            : 'payload.'.$fieldPath;
    }

    private static function requireNonEmpty(string $value, string $field, int $maximumLength): void
    {
        if (trim($value) === '' || strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("{$field} must contain between 1 and {$maximumLength} characters.");
        }
    }
}
