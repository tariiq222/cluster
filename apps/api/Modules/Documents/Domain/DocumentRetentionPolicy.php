<?php

namespace Modules\Documents\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class DocumentRetentionPolicy
{
    /** @param array<string, string> $classificationPolicies @param array<string, array{retention_days: int, legal_hold: bool, legal_hold_reason?: string|null}> $policies */
    private function __construct(
        private array $classificationPolicies,
        private array $policies,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $retention = $config['retention'] ?? null;
        if (! is_array($retention)
            || ! is_array($retention['classification_policies'] ?? null)
            || ! is_array($retention['policies'] ?? null)) {
            throw new InvalidArgumentException('Document retention configuration is invalid.');
        }

        /** @var array<string, string> $classificationPolicies */
        $classificationPolicies = $retention['classification_policies'];
        /** @var array<string, array{retention_days: int, legal_hold: bool, legal_hold_reason?: string|null}> $policies */
        $policies = $retention['policies'];

        return new self($classificationPolicies, $policies);
    }

    public function resolve(string $classification, DateTimeImmutable $now): ResolvedDocumentRetention
    {
        $policyKey = $this->classificationPolicies[$classification] ?? null;
        $policy = is_string($policyKey) ? ($this->policies[$policyKey] ?? null) : null;
        if (! is_array($policy)
            || ! is_int($policy['retention_days'] ?? null)
            || $policy['retention_days'] < 1
            || ! is_bool($policy['legal_hold'] ?? null)) {
            throw new UnexpectedValueException('Configured document retention policy is invalid.');
        }

        $retentionUntil = $now->modify('+'.$policy['retention_days'].' days');
        if ($retentionUntil <= $now) {
            throw new UnexpectedValueException('Configured document retention must be in the future.');
        }
        $reason = $policy['legal_hold_reason'] ?? null;
        if ($policy['legal_hold'] && (! is_string($reason) || trim($reason) === '')) {
            throw new UnexpectedValueException('Configured legal hold requires a reason.');
        }

        return new ResolvedDocumentRetention(
            $policyKey,
            $retentionUntil,
            $policy['legal_hold'],
            is_string($reason) ? $reason : null,
        );
    }
}
