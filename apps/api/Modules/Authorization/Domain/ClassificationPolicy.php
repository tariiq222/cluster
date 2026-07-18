<?php

namespace Modules\Authorization\Domain;

use InvalidArgumentException;

final readonly class ClassificationPolicy
{
    public function __construct(
        public ClassificationLevel $classification,
        public string $minimumCapability,
        public string $exportPolicy,
        public string $downloadPolicy,
        public string $policyVersion,
        public bool $isActive = true,
    ) {
        self::requireNonEmpty($minimumCapability, 'Minimum capability', 96);
        self::requireNonEmpty($exportPolicy, 'Export policy', 32);
        self::requireNonEmpty($downloadPolicy, 'Download policy', 32);
        self::requireNonEmpty($policyVersion, 'Policy version', 32);
    }

    public function permits(ClassificationLevel $clearance): bool
    {
        return $clearance->isAtLeast($this->classification);
    }

    private static function requireNonEmpty(string $value, string $field, int $maximumLength): void
    {
        if (trim($value) === '' || strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("{$field} must contain between 1 and {$maximumLength} characters.");
        }
    }
}
